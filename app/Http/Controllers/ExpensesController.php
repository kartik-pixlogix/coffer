<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Expenses\RecordExpense;
use App\Enums\CategoryKind;
use App\Enums\ExpenseStatus;
use App\Enums\JournalType;
use App\Enums\PayerType;
use App\Enums\ProjectStatus;
use App\Http\Requests\Finance\ExpenseRequest;
use App\Ledger\Period;
use App\Ledger\Reports;
use App\Models\Category;
use App\Models\Expense;
use App\Models\ExpensePayer;
use App\Models\Member;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\Project;
use App\Models\User;
use App\Policies\ExpensePolicy;
use App\Services\Finance\Attachments;
use App\Services\Finance\FiscalYearFilter;
use App\Services\Finance\LedgerCounts;
use App\Services\Finance\PersonalCardBalances;
use App\Support\Csv;
use App\Support\CurrentCompany;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expenses (Expenses.dc.html, MobileExpenses.dc.html, spec §10): KPIs, the filtered list
 * (25 a page) with status chips, approvals one by one or in bulk, the receipt drawer,
 * CSV export, and add / edit / delete. Salary runs show as read-only Auto rows. Members
 * see only what they submitted.
 */
final class ExpensesController extends Controller
{
    public const int PER_PAGE = 25;

    public function index(Request $request, CurrentCompany $current, ExpensePolicy $policy, Reports $reports, LedgerCounts $counts): Response
    {
        $company = $current->require();
        /** @var User $user */
        $user = $request->user();
        $fy = FiscalYearFilter::for($company, $request->query('fy'));
        $ownOnly = $policy->ownOnly();
        $filters = $this->filters($request);
        $today = CarbonImmutable::today();

        $scoped = fn (): Builder => Expense::query()->when($ownOnly, fn (Builder $q) => $q->where('submitted_by', $user->id));
        $filtered = fn (): Builder => $this->apply($scoped(), $filters, $fy->period, $user, withStatus: false);

        // KPIs (spec §10): approved from the books, pending from the queue; a Member sees their own.
        $pending = $scoped()->where('status', ExpenseStatus::Pending->value)
            ->whereBetween('spent_on', [$fy->period->from?->toDateString(), $fy->period->to->toDateString()])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount_paise), 0) as paise')->toBase()->first();

        if ($ownOnly) {
            $approved = $scoped()->where('status', ExpenseStatus::Approved->value)
                ->whereBetween('spent_on', [$fy->period->from?->toDateString(), $fy->period->to->toDateString()])
                ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount_paise), 0) as paise')->toBase()->first();
            $approvedPaise = (int) ($approved->paise ?? 0);
            $approvedCount = (int) ($approved->n ?? 0);
        } else {
            $approvedPaise = $reports->profitAndLoss($company, $fy->period)['expenses'];
            $approvedCount = $counts->live($company, $fy->period, [JournalType::Expense, JournalType::Salary]);
        }

        $statusCounts = $filtered()->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status');

        $expenses = $this->apply($scoped(), $filters, $fy->period, $user, withStatus: true)
            ->with(['category:id,name', 'project:id,name', 'submitter:id,name', 'decider:id,name', 'payers.partner:id,name,colour', 'payers.member:id,name,partner_id', 'payers.moneyAccount:id,name'])
            ->orderBy($filters['sort'] === 'amount' ? 'amount_paise' : 'spent_on', $filters['dir'])
            ->orderBy('id', $filters['dir'])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $colours = $this->userColours();
        $partnerColours = Partner::query()->pluck('colour', 'id');

        // The phone layout's "November so far" card.
        $month = $scoped()->where('status', '!=', ExpenseStatus::Rejected->value)
            ->whereBetween('spent_on', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount_paise), 0) as paise, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as waiting', [ExpenseStatus::Pending->value])
            ->toBase()->first();

        $me = [
            'memberId' => Member::query()->where('user_id', $user->id)->value('id'),
            'partnerId' => Partner::query()->active()->where('user_id', $user->id)->value('id'),
            'name' => $user->name,
        ];

        return Inertia::render('expenses/index', [
            'company' => ['name' => $company->name, 'jv' => $company->isJv()],
            'today' => $today->toDateString(),
            'fy' => $fy->props(),
            'filters' => (object) array_filter([
                'status' => $filters['status'],
                'category' => $filters['category'],
                'payer' => $filters['payer'],
                'month' => $filters['month'],
                'project' => $filters['project'],
                'q' => $filters['q'],
                'mine' => $filters['mine'] ? 1 : null,
                'sort' => $filters['sort'] === 'date' ? null : $filters['sort'],
                'dir' => $filters['dir'] === 'desc' ? null : $filters['dir'],
            ], fn ($v) => $v !== null && $v !== ''),
            'kpis' => [
                'approvedPaise' => $approvedPaise,
                'approvedCount' => $approvedCount,
                'pendingPaise' => (int) ($pending->paise ?? 0),
                'pendingCount' => (int) ($pending->n ?? 0),
            ],
            'statusCounts' => [
                'all' => (int) $statusCounts->sum(),
                'pending' => (int) ($statusCounts[ExpenseStatus::Pending->value] ?? 0),
                'approved' => (int) ($statusCounts[ExpenseStatus::Approved->value] ?? 0),
                'rejected' => (int) ($statusCounts[ExpenseStatus::Rejected->value] ?? 0),
            ],
            'month' => [
                'label' => $today->format('F'),
                'paise' => (int) ($month->paise ?? 0),
                'count' => (int) ($month->n ?? 0),
                'waiting' => (int) ($month->waiting ?? 0),
            ],
            'expenses' => $expenses->through(fn (Expense $e) => $this->row($e, $user, $colours, $partnerColours)),
            'detail' => fn () => $this->detail($request, $user),
            'options' => $this->options(),
            'me' => $me,
            // Payouts and loan conversions of personal-card balances (D50).
            'cards' => fn () => ! $ownOnly && $user->can('action.capital.manage') ? app(PersonalCardBalances::class)->panel($company) : null,
            'abilities' => [
                'create' => $user->can('create', Expense::class),
                'approve' => $user->can('action.expenses.approve'),
                'addCategory' => $user->can('create', Expense::class) && ! $ownOnly,
                'ownOnly' => $ownOnly,
            ],
        ]);
    }

    public function store(ExpenseRequest $request, CurrentCompany $current, RecordExpense $record): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $expense = $record->submit($current->require(), $request->expenseData(), $user);

        activity('expenses')->performedOn($expense)->causedBy($user)
            ->withProperties(['amount_paise' => $expense->amount_paise])
            ->log('submitted');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$expense->description} sent for approval"]);

        return back();
    }

    public function update(ExpenseRequest $request, Expense $expense, CurrentCompany $current, RecordExpense $record): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $reopened = $record->update($current->require(), $expense, $request->expenseData(), $user);

        activity('expenses')->performedOn($expense)->causedBy($user)
            ->withProperties(['amount_paise' => $expense->amount_paise, 'reversed' => $reopened])
            ->log('edited');
        Inertia::flash('toast', ['type' => 'success', 'message' => $reopened
            ? 'Expense updated. Its entry was reversed and it’s back with partners for approval.'
            : 'Expense updated']);

        return back();
    }

    public function destroy(Request $request, Expense $expense, CurrentCompany $current, RecordExpense $record): RedirectResponse
    {
        Gate::authorize('delete', $expense);
        /** @var User $user */
        $user = $request->user();
        $record->delete($current->require(), $expense, $user);

        activity('expenses')->performedOn($expense)->causedBy($user)->log('deleted');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Expense deleted']);

        return back();
    }

    public function approve(Request $request, Expense $expense, CurrentCompany $current, RecordExpense $record): RedirectResponse
    {
        $this->authorizeDecision($request, $expense);
        /** @var User $user */
        $user = $request->user();
        $record->approve($current->require(), $expense, $user);

        activity('expenses')->performedOn($expense)->causedBy($user)->log('approved');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$expense->description} approved"]);

        return back();
    }

    public function reject(Request $request, Expense $expense, RecordExpense $record): RedirectResponse
    {
        $this->authorizeDecision($request, $expense);
        $reason = $this->reason($request);
        /** @var User $user */
        $user = $request->user();
        $record->reject($expense, $reason, $user);

        activity('expenses')->performedOn($expense)->causedBy($user)->withProperties(['reason' => $reason])->log('rejected');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$expense->description} rejected"]);

        return back();
    }

    /** Approve or reject several (BulkActionBar). Rows the user can't decide are skipped and counted. */
    public function bulk(Request $request, CurrentCompany $current, RecordExpense $record): RedirectResponse
    {
        Gate::authorize('action.expenses.approve');

        /** @var array{action: string, ids: list<int>} $data */
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);
        $reason = $data['action'] === 'reject' ? $this->reason($request) : null;
        /** @var User $user */
        $user = $request->user();
        $company = $current->require();

        $done = 0;
        $skipped = 0;

        foreach (Expense::query()->with(['payers', 'submitter'])->whereIn('id', $data['ids'])->orderBy('spent_on')->get() as $expense) {
            if (! $user->can('decide', $expense)) {
                $skipped++;

                continue;
            }

            try {
                if ($reason === null) {
                    $record->approve($company, $expense, $user);
                } else {
                    $record->reject($expense, $reason, $user);
                }
            } catch (ValidationException) {
                $skipped++;

                continue;
            }

            activity('expenses')->performedOn($expense)->causedBy($user)
                ->withProperties($reason === null ? ['bulk' => true] : ['bulk' => true, 'reason' => $reason])
                ->log($reason === null ? 'approved' : 'rejected');
            $done++;
        }

        $verb = $reason === null ? 'approved' : 'rejected';
        $message = ($done === 1 ? '1 expense ' : "{$done} expenses ").$verb;

        if ($skipped > 0) {
            $message .= ". {$skipped} skipped: your own, not pending, or in a closed year.";
        }

        Inertia::flash('toast', ['type' => $skipped > 0 ? 'warning' : 'success', 'message' => $message]);

        return back();
    }

    /** The receipt, inline for the drawer preview or with `?download=1`. */
    public function receipt(Request $request, Expense $expense, Attachments $attachments): StreamedResponse
    {
        Gate::authorize('view', $expense);
        abort_if($expense->receipt_path === null, 404);

        return $attachments->response($expense->receipt_path, (string) $expense->receipt_name, $expense->receipt_mime, $request->boolean('download'));
    }

    /** CSV of the filtered list, or of the selected rows (`ids[]`). */
    public function export(Request $request, CurrentCompany $current, ExpensePolicy $policy): StreamedResponse
    {
        $company = $current->require();
        /** @var User $user */
        $user = $request->user();
        $fy = FiscalYearFilter::for($company, $request->query('fy'));
        $ids = array_values(array_filter(array_map(intval(...), (array) $request->query('ids', []))));

        $query = Expense::query()->when($policy->ownOnly(), fn (Builder $q) => $q->where('submitted_by', $user->id));
        $query = $ids !== [] ? $query->whereIn('id', $ids) : $this->apply($query, $this->filters($request), $fy->period, $user, withStatus: true);

        $rows = $query->with(['category:id,name', 'project:id,name,client', 'submitter:id,name', 'payers.partner:id,name', 'payers.member:id,name', 'payers.moneyAccount:id,name'])
            ->orderBy('spent_on')->orderBy('id')->get();

        activity('expenses')->causedBy($user)->withProperties(['rows' => $rows->count()])->log('exported expenses');

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            Csv::put($out, ['Date', 'Description', 'Category', 'Amount (INR)', 'Status', 'Paid by', 'Submitted by', 'Project', 'Bill to client', 'Reject reason', 'Notes']);

            foreach ($rows as $e) {
                Csv::put($out, [
                    $e->spent_on->toDateString(),
                    $e->description,
                    $e->category->name,
                    Money::plain($e->amount_paise),
                    ucfirst($e->status->value).($e->auto ? ' (Auto)' : ''),
                    $e->payers->map(fn (ExpensePayer $p) => $this->payerName($p).' '.Money::plain($p->amount_paise).' ('.$this->fromLabel($p).')')->implode('; '),
                    $e->auto ? 'Payroll' : $e->submitter?->name,
                    $e->project?->name,
                    $e->billable ? ($e->project->client ?? $e->project?->name) : '',
                    $e->reject_reason,
                    $e->notes,
                ]);
            }

            fclose($out);
        }, 'expenses-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{status: string|null, category: int|null, payer: string|null, month: string|null, project: int|null, q: string|null, mine: bool, sort: 'date'|'amount', dir: 'asc'|'desc'}
     */
    private function filters(Request $request): array
    {
        $status = $request->string('status')->toString();
        $payer = $request->string('payer')->toString();
        $month = $request->string('month')->toString();
        $q = trim($request->string('q')->toString());

        return [
            'status' => ExpenseStatus::tryFrom($status)?->value,
            'category' => $request->integer('category') ?: null,
            'payer' => preg_match('/^(company|partner:\d+|member:\d+)$/', $payer) === 1 ? $payer : null,
            'month' => preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : null,
            'project' => $request->integer('project') ?: null,
            'q' => $q !== '' ? mb_substr($q, 0, 100) : null,
            'mine' => $request->boolean('mine'),
            'sort' => $request->query('sort') === 'amount' ? 'amount' : 'date',
            'dir' => $request->query('dir') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  Builder<Expense>  $query
     * @param  array{status: string|null, category: int|null, payer: string|null, month: string|null, project: int|null, q: string|null, mine: bool, sort: string, dir: string}  $f
     * @return Builder<Expense>
     */
    private function apply(Builder $query, array $f, Period $year, User $user, bool $withStatus): Builder
    {
        $month = $f['month'] !== null ? CarbonImmutable::createFromFormat('!Y-m', $f['month']) : null;
        [$from, $to] = $month
            ? [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()]
            : [$year->from?->toDateString(), $year->to->toDateString()];

        return $query
            ->whereBetween('spent_on', [$from, $to])
            ->when($withStatus && $f['status'] !== null, fn (Builder $q) => $q->where('status', $f['status']))
            ->when($f['category'] !== null, fn (Builder $q) => $q->where('category_id', $f['category']))
            ->when($f['project'] !== null, fn (Builder $q) => $q->where('project_id', $f['project']))
            ->when($f['mine'], fn (Builder $q) => $q->where('submitted_by', $user->id))
            ->when($f['payer'] !== null, function (Builder $q) use ($f): void {
                [$type, $id] = explode(':', (string) $f['payer']) + [1 => null];
                $q->whereHas('payers', fn (Builder $p) => match ($type) {
                    'company' => $p->where('payer_type', PayerType::Company->value),
                    'partner' => $p->where('partner_id', (int) $id),
                    default => $p->where('member_id', (int) $id),
                });
            })
            ->when($f['q'] !== null, function (Builder $q) use ($f): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $f['q']).'%';
                $q->where(fn (Builder $w) => $w->where('description', 'like', $like)
                    ->orWhereHas('submitter', fn (Builder $u) => $u->where('name', 'like', $like))
                    ->orWhereHas('payers.partner', fn (Builder $p) => $p->where('name', 'like', $like))
                    ->orWhereHas('payers.member', fn (Builder $m) => $m->where('name', 'like', $like)));
            });
    }

    /**
     * @param  array<int, int>  $colours  user id → partner colour
     * @param  \Illuminate\Support\Collection<int, int>  $partnerColours
     * @return array<string, mixed>
     */
    private function row(Expense $e, User $user, array $colours, \Illuminate\Support\Collection $partnerColours): array
    {
        return [
            'id' => $e->id,
            'description' => $e->description,
            'amountPaise' => $e->amount_paise,
            'date' => $e->spent_on->toDateString(),
            'categoryId' => $e->category_id,
            'categoryName' => $e->category->name,
            'projectId' => $e->project_id,
            'projectName' => $e->project?->name,
            'billable' => $e->billable,
            'notes' => $e->notes,
            'status' => $e->status->value,
            'rejectReason' => $e->reject_reason,
            'auto' => $e->auto,
            'mode' => $e->payer_mode->value,
            'payers' => $e->payers->map(fn (ExpensePayer $p) => [
                'type' => $p->payer_type->value,
                'partnerId' => $p->partner_id,
                'memberId' => $p->member_id,
                'accountId' => $p->money_account_id,
                'amountPaise' => $p->amount_paise,
                'name' => $this->payerName($p),
                'colour' => match ($p->payer_type) {
                    PayerType::Partner => $p->partner?->colour,
                    PayerType::Member => $p->member !== null ? ($partnerColours[$p->member->partner_id] ?? null) : null,
                    PayerType::Company => null,
                },
                'fromLabel' => $this->fromLabel($p),
            ])->values(),
            'submittedBy' => $e->submitter !== null ? ['name' => $e->submitter->name, 'colour' => $colours[$e->submitter->id] ?? null] : null,
            'decidedBy' => $e->decider?->name,
            'decidedAt' => $e->decided_at?->toIso8601String(),
            'receipt' => $e->receipt_path === null ? null : [
                'name' => (string) $e->receipt_name,
                'size' => (int) $e->receipt_size,
                'url' => route('expenses.receipt', $e),
            ],
            'can' => [
                'edit' => $user->can('update', $e),
                'delete' => $user->can('delete', $e),
                'decide' => $user->can('decide', $e),
            ],
        ];
    }

    /**
     * The receipt drawer's extra facts (`?expense={id}`): its history and the posted entry.
     *
     * @return array<string, mixed>|null
     */
    private function detail(Request $request, User $user): ?array
    {
        $id = $request->integer('expense');
        $expense = $id > 0 ? Expense::query()->with('salaryRun')->find($id) : null;

        if ($expense === null || ! $user->can('view', $expense)) {
            return null;
        }

        $history = Activity::query()
            ->where('subject_type', $expense->getMorphClass())
            ->where('subject_id', $expense->id)
            ->with('causer')
            ->orderBy('id')
            ->get()
            ->map(fn (Activity $a) => [
                'id' => $a->id,
                'event' => (string) $a->description,
                'by' => $a->causer instanceof User ? $a->causer->name : null,
                'at' => $a->created_at?->toIso8601String(),
                'reason' => $a->properties->get('reason'),
            ])->values();

        return [
            'id' => $expense->id,
            'journalEntryId' => $expense->journal_entry_id,
            'salaryMonth' => $expense->salaryRun?->month->format('Y-m'),
            'history' => $history,
        ];
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        $partnerColours = Partner::query()->pluck('colour', 'id');

        return [
            'categories' => Category::query()->where('kind', CategoryKind::Expense->value)->orderBy('position')->orderBy('name')->get()
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name, 'active' => $c->archived_at === null, 'system' => $c->system])->values(),
            'partners' => Partner::query()->orderBy('position')->orderBy('id')->get()
                ->map(fn (Partner $p) => ['id' => $p->id, 'name' => $p->name, 'colour' => $p->colour, 'sideId' => $p->side_id, 'active' => $p->archived_at === null])->values(),
            'members' => Member::query()->orderBy('name')->get()
                ->map(fn (Member $m) => ['id' => $m->id, 'name' => $m->name, 'partnerId' => $m->partner_id, 'colour' => $partnerColours[$m->partner_id] ?? null, 'active' => $m->isActive()])->values(),
            'accounts' => MoneyAccount::query()->orderBy('position')->orderBy('id')->get()
                ->map(fn (MoneyAccount $a) => ['id' => $a->id, 'name' => $a->name, 'sideId' => $a->side_id, 'active' => $a->archived_at === null])->values(),
            'projects' => Project::query()->orderBy('name')->get()
                ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'client' => $p->client, 'active' => $p->status === ProjectStatus::Active])->values(),
        ];
    }

    /** @return array<int, int> user id → the colour of their partner (or their member's partner) */
    private function userColours(): array
    {
        /** @var Collection<int, Partner> $partners */
        $partners = Partner::query()->get(['id', 'user_id', 'colour']);
        $byId = $partners->pluck('colour', 'id');
        $colours = [];

        foreach (Member::query()->whereNotNull('user_id')->get(['user_id', 'partner_id']) as $m) {
            $colours[(int) $m->user_id] = (int) ($byId[$m->partner_id] ?? 0);
        }

        foreach ($partners as $p) {
            if ($p->user_id !== null) {
                $colours[$p->user_id] = $p->colour;
            }
        }

        return $colours;
    }

    private function payerName(ExpensePayer $p): string
    {
        return match ($p->payer_type) {
            PayerType::Partner => (string) $p->partner?->name,
            PayerType::Member => (string) $p->member?->name,
            PayerType::Company => 'Company account',
        };
    }

    private function fromLabel(ExpensePayer $p): string
    {
        return $p->moneyAccount !== null ? $p->moneyAccount->name : 'Personal card (reimburse)';
    }

    private function authorizeDecision(Request $request, Expense $expense): void
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('view', $expense);

        if (! $user->can('decide', $expense)) {
            $reason = match (true) {
                ! $user->can('action.expenses.approve') => null,
                $expense->auto => 'Auto rows come from timesheets and are already approved.',
                ! $expense->isPending() => "“{$expense->description}” isn’t waiting for approval any more.",
                default => 'You can’t approve or reject your own expense. Another partner or an Admin can.',
            };

            abort_if($reason === null, 403);

            throw ValidationException::withMessages(['expense' => $reason]);
        }
    }

    private function reason(Request $request): string
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Say why it’s rejected.']);

        return trim($data['reason']);
    }
}
