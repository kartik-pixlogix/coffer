<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Category;
use App\Models\JournalLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** How many records use each category: posted journal entries on its account. */
final class CategoryUsage
{
    /**
     * @param  Collection<int, Category>  $categories
     * @return array<int, int> category id → records
     */
    public function counts(Collection $categories): array
    {
        $ids = $categories->map(fn (Category $c): int => $c->id)->values()->all();

        if ($ids === []) {
            return [];
        }

        return JournalLine::query()
            ->join('accounts as a', 'a.id', '=', 'journal_lines.account_id')
            ->whereIn('a.category_id', $ids)
            ->groupBy('a.category_id')
            ->select('a.category_id', DB::raw('COUNT(DISTINCT journal_lines.journal_entry_id) as n'))
            ->pluck('n', 'a.category_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
