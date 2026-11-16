<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Models\Company;
use App\Models\Partner;
use App\Models\Side;
use Illuminate\Support\Collection;

/**
 * Who owns what (spec §1). Weights are exact integers on one scale for both company
 * types: Single = share bps × 10000; JV = side bps × within-side bps. Feed them to
 * Rounding::largestRemainder.
 */
final class Shares
{
    /** @return Collection<int, Partner> active partners, in order, with their side loaded */
    public static function partners(Company $company): Collection
    {
        return Partner::query()->withoutGlobalScope('company')
            ->with(['side' => fn ($q) => $q->withoutGlobalScope('company')])
            ->where('company_id', $company->id)
            ->whereNull('archived_at')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, int> partner id → effective-equity weight */
    public static function effective(Company $company): array
    {
        $weights = [];

        foreach (self::partners($company) as $partner) {
            $weights[$partner->id] = $partner->side === null
                ? $partner->equity_bps * 10000
                : $partner->side->equity_bps * $partner->equity_bps;
        }

        return $weights;
    }

    /** @return array<int, int> side id → share weight (bps) */
    public static function sides(Company $company): array
    {
        return Side::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('equity_bps', 'id')
            ->map(fn ($bps) => (int) $bps)
            ->all();
    }

    /** @return array<int, int> partner id → within-side bps, for one side */
    public static function withinSide(Company $company, int $sideId): array
    {
        return self::partners($company)
            ->where('side_id', $sideId)
            ->mapWithKeys(fn (Partner $p) => [$p->id => $p->equity_bps])
            ->all();
    }
}
