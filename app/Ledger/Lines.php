<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\AccountRole;
use App\Enums\JournalType;
use App\Models\Company;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Query building blocks over journal lines, shared by allocation, settlements, reports and
 * the integrity check so they all read the books the same way.
 */
final class Lines
{
    /** Lines of a company within a period, joined to their account and entry. */
    public static function in(Company $company, Period $period): Builder
    {
        return DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $company->id)
            ->when($period->from !== null, fn (Builder $q) => $q->where('l.date', '>=', $period->from?->toDateString()))
            ->where('l.date', '<=', $period->to->toDateString());
    }

    /**
     * Income and expense lines that make up a period's profit: everything except the FY
     * close (and its reversal), which only moves profit into capital.
     */
    public static function profitAndLoss(Company $company, Period $period): Builder
    {
        return self::in($company, $period)
            ->whereIn('a.role', [AccountRole::Income->value, AccountRole::Expense->value])
            ->where('e.type', '!=', JournalType::FyClose->value);
    }

    /**
     * Net profit for a period (spec §4.3): income credits − expense debits, in paise.
     */
    public static function netProfit(Company $company, Period $period): int
    {
        $row = self::profitAndLoss($company, $period)
            ->selectRaw('COALESCE(SUM(l.credit_paise - l.debit_paise), 0) as net')
            ->first();

        return (int) ($row->net ?? 0);
    }
}
