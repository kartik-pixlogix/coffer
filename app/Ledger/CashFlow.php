<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\AccountRole;
use App\Enums\JournalType;
use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Cash flow statement, indirect method (AS 3, D157). Cash and cash equivalents are the
 * money accounts. Every journal entry balances, so the change in cash equals the change in
 * everything else with the sign flipped; the statement groups those changes:
 *
 * - A. Operating: net profit, then the working-capital changes (amounts due to or from
 *   partners on their current accounts, reimbursements owed to members).
 * - B. Investing: nothing yet (there are no fixed-asset accounts; hardware is expensed).
 * - C. Financing: capital introduced, drawings, loans from partners received and repaid.
 *
 * Left out because they move no cash, or only between money accounts: the opening balance
 * (it is the opening cash), the year-end close, a personal-card balance converted to a
 * loan, and settlement transfers between sides. Opening cash + A + B + C must equal the
 * closing balance of the money accounts; `reconciled` says whether it does.
 */
final class CashFlow
{
    private const array NON_CASH = [
        JournalType::Opening->value,
        JournalType::FyClose->value,
        JournalType::LoanConversion->value,
        JournalType::Settlement->value,
    ];

    /**
     * @return array{
     *     operating: list<array{key: string, label: string, amount: int}>, operatingTotal: int,
     *     investing: list<array{key: string, label: string, amount: int}>, investingTotal: int,
     *     financing: list<array{key: string, label: string, amount: int}>, financingTotal: int,
     *     netChange: int, openingCash: int, closingCash: int, reconciled: bool, drift: int,
     *     cash: list<array{code: string, name: string, opening: int, closing: int}>
     * }
     */
    public function compute(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $period = Period::between($from, $to);

        // Cash effect of each non-cash role (credit − debit), per entry type, for the period.
        $effects = Lines::in($company, $period)
            ->whereNotIn('e.type', self::NON_CASH)
            ->where('a.role', '!=', AccountRole::Money->value)
            ->selectRaw('a.role, e.type, COALESCE(SUM(l.credit_paise - l.debit_paise), 0) as amount')
            ->groupBy('a.role', 'e.type')
            ->get();

        $role = fn (AccountRole $role): int => (int) $effects->where('role', $role->value)->sum('amount');
        $of = fn (AccountRole $role, JournalType $type): int => (int) $effects->where('role', $role->value)->where('type', $type->value)->sum('amount');

        $netProfit = $role(AccountRole::Income) + $role(AccountRole::Expense);
        $partners = $role(AccountRole::PartnerCurrent);
        $reimbursements = $role(AccountRole::ReimbursementsPayable);
        $taxes = array_sum(array_map($role, AccountRole::TAX));

        $operating = [
            ['key' => 'net_profit', 'label' => 'Net profit for the period', 'amount' => $netProfit],
            ['key' => 'non_cash', 'label' => 'Adjustments for depreciation and other non-cash items', 'amount' => 0],
            ['key' => 'partners', 'label' => 'Increase / (decrease) in amounts due to partners on current account', 'amount' => $partners],
            ['key' => 'reimbursements', 'label' => 'Increase / (decrease) in reimbursements owed to members', 'amount' => $reimbursements],
            ['key' => 'taxes', 'label' => 'Increase / (decrease) in GST and TDS owed, net of tax credits', 'amount' => $taxes],
        ];

        $capital = $role(AccountRole::Capital);
        $contributions = $of(AccountRole::Capital, JournalType::Contribution);
        $drawings = $of(AccountRole::Capital, JournalType::Drawing);
        $loans = $role(AccountRole::LoansPayable);
        $received = $of(AccountRole::LoansPayable, JournalType::Loan);
        $repaid = $of(AccountRole::LoansPayable, JournalType::Repayment);

        $financing = [
            ['key' => 'contributions', 'label' => 'Capital introduced by partners', 'amount' => $contributions],
            ['key' => 'drawings', 'label' => 'Drawings by partners', 'amount' => $drawings],
            ['key' => 'loans_received', 'label' => 'Loans received from partners', 'amount' => $received],
            ['key' => 'loans_repaid', 'label' => 'Loans repaid to partners', 'amount' => $repaid],
        ];

        // Anything else on these accounts (none today) still counts, so the totals always tie.
        $otherCapital = $capital - $contributions - $drawings;
        $otherLoans = $loans - $received - $repaid;

        if ($otherCapital !== 0 || $otherLoans !== 0) {
            $financing[] = ['key' => 'other_financing', 'label' => 'Other movements with partners', 'amount' => $otherCapital + $otherLoans];
        }

        $operatingTotal = array_sum(array_column($operating, 'amount'));
        $financingTotal = array_sum(array_column($financing, 'amount'));
        $netChange = $operatingTotal + $financingTotal;

        $cash = $this->cash($company, $from, $to);
        $openingCash = array_sum(array_column($cash, 'opening'));
        $closingCash = array_sum(array_column($cash, 'closing'));
        $drift = $openingCash + $netChange - $closingCash;

        return [
            'operating' => $operating,
            'operatingTotal' => $operatingTotal,
            'investing' => [],
            'investingTotal' => 0,
            'financing' => $financing,
            'financingTotal' => $financingTotal,
            'netChange' => $netChange,
            'openingCash' => $openingCash,
            'closingCash' => $closingCash,
            'reconciled' => $drift === 0,
            'drift' => $drift,
            'cash' => $cash,
        ];
    }

    /**
     * Each money account's opening cash (its balance the day before, plus any opening balance
     * posted inside the period) and closing balance.
     *
     * @return list<array{code: string, name: string, opening: int, closing: int}>
     */
    private function cash(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Lines::in($company, Period::asOf($to))
            ->where('a.role', AccountRole::Money->value)
            ->selectRaw(
                'a.code, a.name,'
                .' COALESCE(SUM(CASE WHEN l.date < ? OR e.type = ? THEN l.debit_paise - l.credit_paise ELSE 0 END), 0) as opening,'
                .' COALESCE(SUM(l.debit_paise - l.credit_paise), 0) as closing',
                [$from->toDateString(), JournalType::Opening->value],
            )
            ->groupBy('a.id', 'a.code', 'a.name')
            ->get();

        $cash = [];

        foreach ($rows as $row) {
            if ((int) $row->opening === 0 && (int) $row->closing === 0) {
                continue;
            }

            $cash[] = ['code' => (string) $row->code, 'name' => (string) $row->name, 'opening' => (int) $row->opening, 'closing' => (int) $row->closing];
        }

        usort($cash, fn (array $a, array $b) => strnatcmp($a['code'], $b['code']));

        return $cash;
    }
}
