<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\AccountRole;
use App\Ledger\ChartOfAccounts;
use App\Models\Company;
use App\Models\Member;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\Reimbursement;
use Illuminate\Support\Facades\DB;

/**
 * What the company owes people for spending on their own cards (spec §4.1, §4.2), read
 * from the books: a member's Reimbursements payable balance, and a partner's Partner
 * current balance when it is in credit (a debit balance means the partner holds company
 * money, so nothing is owed to them). Payouts and loan conversions bring these down.
 */
final class PersonalCardBalances
{
    /**
     * Everyone owed something, partners first, largest first within each group.
     *
     * @return list<array{type: 'partner'|'member', id: int, name: string, owedPaise: int}>
     */
    public function owed(Company $company): array
    {
        $rows = DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $company->id)
            ->whereIn('a.role', [AccountRole::PartnerCurrent->value, AccountRole::ReimbursementsPayable->value])
            ->groupBy('a.role', 'a.partner_id', 'a.member_id')
            ->selectRaw('a.role, a.partner_id, a.member_id, COALESCE(SUM(l.credit_paise - l.debit_paise), 0) as owed')
            ->get();

        $partners = Partner::query()->withoutGlobalScope('company')->where('company_id', $company->id)->pluck('name', 'id');
        $members = Member::query()->withoutGlobalScope('company')->where('company_id', $company->id)->pluck('name', 'id');
        $owed = [];

        foreach ($rows as $row) {
            $amount = (int) $row->owed;

            if ($amount <= 0) {
                continue;
            }

            $partner = $row->role === AccountRole::PartnerCurrent->value;
            $id = (int) ($partner ? $row->partner_id : $row->member_id);
            $owed[] = [
                'type' => $partner ? 'partner' : 'member',
                'id' => $id,
                'name' => (string) ($partner ? $partners[$id] ?? '' : $members[$id] ?? ''),
                'owedPaise' => $amount,
            ];
        }

        usort($owed, fn (array $a, array $b) => [$a['type'] === 'member', -$a['owedPaise'], $a['name']] <=> [$b['type'] === 'member', -$b['owedPaise'], $b['name']]);

        return $owed;
    }

    /**
     * The "Owed for personal cards" card on Expenses (D50): who is owed what, with the
     * account a payout defaults to (their side's account in a JV), and the latest payouts.
     *
     * @return array{owed: list<array<string, mixed>>, recent: array<int, array<string, mixed>>, accounts: array<int, array<string, mixed>>}
     */
    public function panel(Company $company): array
    {
        $chart = app(ChartOfAccounts::class);
        $partners = Partner::query()->withoutGlobalScope('company')->where('company_id', $company->id)->get()->keyBy('id');
        $members = Member::query()->withoutGlobalScope('company')->where('company_id', $company->id)->get()->keyBy('id');

        $owed = array_map(function (array $row) use ($company, $partners, $members, $chart): array {
            $partner = $row['type'] === 'partner' ? $partners[$row['id']] ?? null : $partners[$members[$row['id']]->partner_id ?? 0] ?? null;

            return [
                ...$row,
                'key' => $row['type'].':'.$row['id'],
                'colour' => $row['type'] === 'partner' ? $partner?->colour : null,
                'billedTo' => $row['type'] === 'member' ? $partner?->name : null,
                'accountId' => $partner !== null ? $chart->payingAccountFor($partner)->id : $chart->companyAccount($company)->id,
            ];
        }, $this->owed($company));

        $recent = Reimbursement::query()->withoutGlobalScope('company')->where('company_id', $company->id)
            ->with(['partner:id,name', 'member:id,name', 'moneyAccount:id,name'])
            ->orderByDesc('paid_on')->orderByDesc('id')->limit(5)->get()
            ->map(fn (Reimbursement $r) => [
                'id' => $r->id,
                'date' => $r->paid_on->toDateString(),
                'name' => $r->payeeName(),
                'amountPaise' => $r->amount_paise,
                'fromName' => $r->moneyAccount->name,
            ])->values()->all();

        $accounts = MoneyAccount::query()->withoutGlobalScope('company')->where('company_id', $company->id)->whereNull('archived_at')
            ->orderBy('position')->orderBy('id')->get()
            ->map(fn (MoneyAccount $a) => ['id' => $a->id, 'name' => $a->name, 'sideId' => $a->side_id, 'active' => true])->values()->all();

        return ['owed' => $owed, 'recent' => $recent, 'accounts' => $accounts];
    }

    /** What the company owes one partner or member for personal-card spending, in paise (never negative). */
    public function owedTo(Partner|Member $payee): int
    {
        $role = $payee instanceof Partner ? AccountRole::PartnerCurrent : AccountRole::ReimbursementsPayable;

        $owed = (int) DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $payee->company_id)
            ->where('a.role', $role->value)
            ->where($payee instanceof Partner ? 'a.partner_id' : 'a.member_id', $payee->id)
            ->selectRaw('COALESCE(SUM(l.credit_paise - l.debit_paise), 0) as owed')
            ->value('owed');

        return max(0, $owed);
    }
}
