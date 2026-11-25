<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\AccountRole;
use App\Enums\JournalType;
use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The trial balance in four columns (D155): for each account, the opening balance on the
 * period's first day, the period's debits and credits, and the closing balance, with
 * opening + debits − credits = closing for every account.
 *
 * Read the same way as the as-of trial balance (D60): balance-sheet accounts run from the
 * beginning; income and expenses from the start of the fiscal year, before its own close
 * (so a closed year still shows them); profit of earlier years never closed is its own
 * equity row, in the opening column. The closing column equals Reports::trialBalanceAsOf
 * for the period's last day.
 */
final class FourColumnTrialBalance
{
    /**
     * @return array{
     *     accounts: list<array{id: int|null, code: string, name: string, type: string, role: string|null, opening: int, debit: int, credit: int, closing: int}>,
     *     openingDebit: int, openingCredit: int, debit: int, credit: int, closingDebit: int, closingCredit: int,
     *     openingDrift: int, movementDrift: int, closingDrift: int
     * }
     */
    public function compute(Company $company, CarbonImmutable $fyStart, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $pnlRoles = [AccountRole::Income->value, AccountRole::Expense->value];
        $fromDay = $from->toDateString();
        $fyDay = $fyStart->toDateString();
        $columns = 'a.id, a.code, a.name, a.type, a.role,'
            .' COALESCE(SUM(CASE WHEN l.date < ? THEN l.debit_paise - l.credit_paise ELSE 0 END), 0) as opening,'
            .' COALESCE(SUM(CASE WHEN l.date >= ? THEN l.debit_paise ELSE 0 END), 0) as debit,'
            .' COALESCE(SUM(CASE WHEN l.date >= ? THEN l.credit_paise ELSE 0 END), 0) as credit';

        // Balance-sheet accounts: everything up to the last day, but not this year's own close.
        $balance = Lines::in($company, Period::asOf($to))
            ->whereNotIn('a.role', $pnlRoles)
            ->where(fn ($q) => $q->where('e.type', '!=', JournalType::FyClose->value)->orWhere('l.date', '<', $fyDay))
            ->selectRaw($columns, [$fromDay, $fromDay, $fromDay])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.role');

        // Income and expenses: from the year's first day, before closing.
        $profit = Lines::profitAndLoss($company, Period::between($fyStart, $to))
            ->selectRaw($columns, [$fromDay, $fromDay, $fromDay])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.role');

        $accounts = [];

        foreach ($balance->unionAll($profit)->get() as $row) {
            $opening = (int) $row->opening;
            $debit = (int) $row->debit;
            $credit = (int) $row->credit;

            $accounts[] = [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'type' => (string) $row->type,
                'role' => (string) $row->role,
                'opening' => $opening,
                'debit' => $debit,
                'credit' => $credit,
                'closing' => $opening + $debit - $credit,
            ];
        }

        usort($accounts, fn (array $a, array $b) => strnatcmp($a['code'], $b['code']));

        $earlier = (int) (Lines::in($company, Period::asOf($fyStart->subDay()))
            ->whereIn('a.role', $pnlRoles)
            ->selectRaw('COALESCE(SUM(l.debit_paise - l.credit_paise), 0) as net')
            ->value('net') ?? 0);

        if ($earlier !== 0) {
            $accounts[] = [
                'id' => null,
                'code' => '3900',
                'name' => 'Profit of earlier years, not yet closed',
                'type' => 'equity',
                'role' => null,
                'opening' => $earlier,
                'debit' => 0,
                'credit' => 0,
                'closing' => $earlier,
            ];
        }

        $totals = ['openingDebit' => 0, 'openingCredit' => 0, 'debit' => 0, 'credit' => 0, 'closingDebit' => 0, 'closingCredit' => 0];

        foreach ($accounts as $a) {
            $totals['openingDebit'] += max($a['opening'], 0);
            $totals['openingCredit'] += max(-$a['opening'], 0);
            $totals['debit'] += $a['debit'];
            $totals['credit'] += $a['credit'];
            $totals['closingDebit'] += max($a['closing'], 0);
            $totals['closingCredit'] += max(-$a['closing'], 0);
        }

        return [
            'accounts' => $accounts,
            ...$totals,
            'openingDrift' => $totals['openingDebit'] - $totals['openingCredit'],
            'movementDrift' => $totals['debit'] - $totals['credit'],
            'closingDrift' => $totals['closingDebit'] - $totals['closingCredit'],
        ];
    }
}
