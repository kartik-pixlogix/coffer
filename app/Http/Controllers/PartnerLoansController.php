<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Loans\RecordLoan;
use App\Http\Requests\Finance\LoanRequest;
use App\Http\Requests\Finance\LoanUpdateRequest;
use App\Http\Requests\Finance\RepaymentRequest;
use App\Ledger\ChartOfAccounts;
use App\Ledger\FiscalYears;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\User;
use App\Services\Finance\FiscalYearFilter;
use App\Services\Finance\PersonalCardBalances;
use App\Support\CurrentCompany;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Partner loans (PartnerLoans.dc.html, spec §12): the loans active in the chosen fiscal
 * year (lent by its end, not closed before it began) with what was repaid by then, a
 * progress bar, the next payment and Open / Repaying / Repaid status; the KPIs over
 * those loans; the year's repayment history; Record loan (new money, or a personal-card
 * balance converted, D51) and Record repayment. Loans close on their own at ₹0.
 */
final class PartnerLoansController extends Controller
{
    public function index(Request $request, CurrentCompany $current, FiscalYears $years, PersonalCardBalances $balances, ChartOfAccounts $chart): Response
    {
        $company = $current->require();
        /** @var User $user */
        $user = $request->user();
        $fy = FiscalYearFilter::for($company, $request->query('fy'));
        $closed = $years->closedYearFor($company, $fy->start) !== null;
        $from = (string) $fy->period->from?->toDateString();
        $to = $fy->period->to->toDateString();

        $loans = Loan::query()
            ->with(['partner', 'moneyAccount:id,name', 'voucherEntry:id,voucher_no', 'repayments' => fn ($q) => $q->where('paid_on', '<=', $to)->orderBy('paid_on')->orderBy('id')])
            ->where('lent_on', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('closed_on')->orWhere('closed_on', '>=', $from))
            ->orderBy('number')
            ->get();

        $history = LoanRepayment::query()
            ->with(['loan.partner', 'moneyAccount:id,name', 'voucherEntry:id,voucher_no'])
            ->whereBetween('paid_on', [$from, $to])
            ->orderByDesc('paid_on')->orderByDesc('id')
            ->get();

        $rows = $loans->map(fn (Loan $loan) => $this->row($loan, $chart, $years->closedYearFor($company, $loan->lent_on) !== null))->values();
        $borrowed = (int) $rows->sum('amountPaise');
        $repaid = (int) $rows->sum('repaidPaise');
        $open = $rows->filter(fn (array $r) => $r['outstandingPaise'] > 0);
        $partners = Partner::query()->orderBy('position')->orderBy('id')->get();
        $owed = collect($balances->owed($company))->where('type', 'partner')->pluck('owedPaise', 'id');

        return Inertia::render('loans/index', [
            'company' => ['name' => $company->name, 'jv' => $company->isJv()],
            'today' => now()->toDateString(),
            'fy' => [...$fy->props(), 'closed' => $closed],
            'loans' => $rows,
            'kpis' => [
                'outstandingPaise' => $borrowed - $repaid,
                'borrowedPaise' => $borrowed,
                'repaidPaise' => $repaid,
                'loansCount' => $rows->count(),
                'openCount' => $open->count(),
                'repaymentsCount' => (int) $rows->sum('repaymentsCount'),
                'next' => $this->nextDue($loans),
            ],
            'history' => $history->map(fn (LoanRepayment $r) => [
                'id' => $r->id,
                'loanId' => $r->loan_id,
                'code' => $r->loan->code(),
                'date' => $r->paid_on->toDateString(),
                'voucher' => $r->voucher(),
                'partnerId' => $r->loan->partner_id,
                'name' => $r->loan->partner->name,
                'colour' => $r->loan->partner->colour,
                'amountPaise' => $r->amount_paise,
                'fromName' => $r->moneyAccount->name,
                'note' => $r->note,
            ])->values(),
            'options' => [
                'partners' => $partners->map(fn (Partner $p) => [
                    'id' => $p->id, 'name' => $p->name, 'colour' => $p->colour, 'sideId' => $p->side_id, 'active' => $p->archived_at === null,
                    'owedPaise' => (int) ($owed[$p->id] ?? 0),
                    'accountId' => $chart->payingAccountFor($p)->id,
                ])->values(),
                'accounts' => MoneyAccount::query()->whereNull('archived_at')->orderBy('position')->orderBy('id')->get()
                    ->map(fn (MoneyAccount $a) => ['id' => $a->id, 'name' => $a->name, 'sideId' => $a->side_id, 'active' => true])->values(),
            ],
            'abilities' => ['manage' => $user->can('action.capital.manage') && ! $closed],
        ]);
    }

    public function store(LoanRequest $request, CurrentCompany $current, RecordLoan $record): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->loanData();
        $loan = $record->lend($current->require(), $data, $user);

        activity('loans')->performedOn($loan)->causedBy($user)
            ->withProperties(['amount_paise' => $loan->amount_paise, 'partner_id' => $loan->partner_id, 'converted' => $loan->converted])
            ->log($loan->converted ? 'converted a personal-card balance into a loan' : 'recorded a loan');
        Inertia::flash('toast', ['type' => 'success', 'message' => $loan->converted
            ? Money::format($loan->amount_paise).' of '.$data->partner->name.'’s card spending is now loan '.$loan->code()
            : $loan->code().' recorded: '.Money::format($loan->amount_paise).' from '.$data->partner->name]);

        return back();
    }

    /**
     * Edit loan (A6, D138): what it's for and the terms always; the amount and date only
     * while nothing has been repaid (reversed and posted again). The change is logged
     * field by field.
     */
    public function update(LoanUpdateRequest $request, Loan $loan, CurrentCompany $current, RecordLoan $record): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $changes = $record->edit($current->require(), $loan, $request->loanEdit(), $user);

        if ($changes === []) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Nothing changed on '.$loan->code()]);

            return back();
        }

        activity('loans')->performedOn($loan)->causedBy($user)
            ->withProperties(['loan' => $loan->code(), 'changes' => $changes])
            ->log('edited a loan');

        $reposted = isset($changes['amount_paise']) || isset($changes['lent_on']);
        Inertia::flash('toast', ['type' => 'success', 'message' => $loan->code().' updated'.($reposted ? '; its entry was posted again' : '')]);

        return back();
    }

    public function repay(RepaymentRequest $request, Loan $loan, CurrentCompany $current, RecordLoan $record): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $r = $request->repayment();
        $repayment = $record->repay($current->require(), $loan, $r['amount'], $r['date'], $r['account'], $r['note'], $user);
        $partner = $loan->partner()->firstOrFail();

        activity('loans')->performedOn($repayment)->causedBy($user)
            ->withProperties(['amount_paise' => $r['amount'], 'loan' => $loan->code()])
            ->log('recorded a loan repayment');
        Inertia::flash('toast', ['type' => 'success', 'message' => $loan->closed_on !== null
            ? $loan->code().' repaid in full'
            : Money::format($r['amount']).' repaid to '.$partner->name]);

        return back();
    }

    public function destroyRepayment(Request $request, LoanRepayment $repayment, CurrentCompany $current, RecordLoan $record): RedirectResponse
    {
        Gate::authorize('action.capital.manage');
        /** @var User $user */
        $user = $request->user();
        $record->deleteRepayment($current->require(), $repayment, $user);

        activity('loans')->performedOn($repayment)->causedBy($user)->withProperties(['amount_paise' => $repayment->amount_paise])->log('deleted a loan repayment');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Repayment deleted']);

        return back();
    }

    public function destroy(Request $request, Loan $loan, CurrentCompany $current, RecordLoan $record): RedirectResponse
    {
        Gate::authorize('action.capital.manage');
        /** @var User $user */
        $user = $request->user();
        $record->delete($current->require(), $loan, $user);

        activity('loans')->performedOn($loan)->causedBy($user)->withProperties(['amount_paise' => $loan->amount_paise])->log('deleted a loan');
        Inertia::flash('toast', ['type' => 'success', 'message' => $loan->code().' deleted']);

        return back();
    }

    /** @return array<string, mixed> */
    private function row(Loan $loan, ChartOfAccounts $chart, bool $locked): array
    {
        $repaid = (int) $loan->repayments->sum('amount_paise');
        $outstanding = $loan->amount_paise - $repaid;
        $last = $loan->repayments->last();

        return [
            'id' => $loan->id,
            'code' => $loan->code(),
            'partnerId' => $loan->partner_id,
            'name' => $loan->partner->name,
            'colour' => $loan->partner->colour,
            'date' => $loan->lent_on->toDateString(),
            'voucher' => $loan->voucher(),
            'purpose' => $loan->purpose,
            'converted' => $loan->converted,
            'intoName' => $loan->moneyAccount?->name,
            'amountPaise' => $loan->amount_paise,
            'repaidPaise' => $repaid,
            'outstandingPaise' => $outstanding,
            'instalmentPaise' => $loan->instalment_paise,
            'dueOn' => $loan->due_on?->toDateString(),
            'closedOn' => $outstanding <= 0 ? ($loan->closed_on ?? $last?->paid_on)?->toDateString() : null,
            'repaymentsCount' => $loan->repayments->count(),
            'next' => $this->next($loan, $outstanding, $last?->paid_on),
            'terms' => $this->terms($loan),
            // Repaid from the account it came into, else the lender's own (side) account.
            // Lent inside a closed fiscal year: it can't be edited or deleted (A6).
            'locked' => $locked,
            'fromAccountId' => $loan->money_account_id ?? $chart->payingAccountFor($loan->partner)->id,
        ];
    }

    /**
     * The next payment: an instalment at the end of the month after the last repayment
     * (or after the loan), or the whole outstanding amount by the due date.
     *
     * @return array{amountPaise: int, date: string, by: bool}|null
     */
    private function next(Loan $loan, int $outstanding, ?CarbonImmutable $lastPaid): ?array
    {
        if ($outstanding <= 0) {
            return null;
        }

        if ($loan->instalment_paise !== null) {
            $after = ($lastPaid ?? $loan->lent_on)->startOfMonth()->addMonthNoOverflow()->endOfMonth();
            $date = $loan->due_on !== null && $after->greaterThan($loan->due_on) ? $loan->due_on : $after;

            return ['amountPaise' => min($loan->instalment_paise, $outstanding), 'date' => $date->toDateString(), 'by' => false];
        }

        return $loan->due_on !== null ? ['amountPaise' => $outstanding, 'date' => $loan->due_on->toDateString(), 'by' => true] : null;
    }

    private function terms(Loan $loan): string
    {
        return match (true) {
            $loan->instalment_paise !== null => Money::format($loan->instalment_paise).' a month, interest-free',
            $loan->due_on !== null => 'Lump sum, interest-free',
            default => 'Interest-free, no fixed date',
        };
    }

    /**
     * The soonest next payment across open loans (the Open loans KPI caption).
     *
     * @param  EloquentCollection<int, Loan>  $loans  with repayments loaded
     * @return array{code: string, amountPaise: int, date: string, by: bool}|null
     */
    private function nextDue(EloquentCollection $loans): ?array
    {
        $soonest = null;

        foreach ($loans as $loan) {
            $outstanding = $loan->amount_paise - (int) $loan->repayments->sum('amount_paise');
            $next = $this->next($loan, $outstanding, $loan->repayments->last()?->paid_on);

            if ($next !== null && ($soonest === null || $next['date'] < $soonest['date'])) {
                $soonest = ['code' => $loan->code(), ...$next];
            }
        }

        return $soonest;
    }
}
