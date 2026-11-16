<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\JournalType;
use App\Ledger\Period;
use App\Models\Company;
use App\Models\JournalEntry;

/**
 * Entry counts for finance KPIs, read from the books like every other figure (spec §4.3):
 * live entries only, so an edited record (a reversal plus a new entry) counts once.
 */
final class LedgerCounts
{
    /** @param list<JournalType> $types */
    public function live(Company $company, Period $period, array $types): int
    {
        return JournalEntry::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->whereIn('type', array_map(fn (JournalType $t) => $t->value, $types))
            ->whereNull('reverses_id')
            ->whereNull('reversed_by_id')
            ->when($period->from !== null, fn ($q) => $q->where('date', '>=', $period->from?->toDateString()))
            ->where('date', '<=', $period->to->toDateString())
            ->count();
    }
}
