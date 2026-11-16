<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\AccountRole;
use App\Enums\AttributionMode;
use App\Models\Company;

/**
 * Each partner's share of net profit for a period (spec §4.4, D8), in paise, always
 * adding up to net profit exactly (invariant 4).
 *
 * - JV pooled (equity) mode: net profit × effective equity.
 * - Per-partner mode: what the partner brought in − what is attributed to them (paid by
 *   them, paid from their side's account split by within-side equity, salary of members
 *   billed to them), plus their effective equity of everything unattributed.
 */
final class ProfitAllocator
{
    /** @return array<int, int> partner id → share in paise */
    public function allocate(Company $company, Period $period): array
    {
        $net = Lines::netProfit($company, $period);

        if ($company->attribution_mode === AttributionMode::JvPooled) {
            return Rounding::largestRemainder($net, Shares::effective($company));
        }

        return $this->perPartner($company, $period, $net);
    }

    /** @return array<int, int> */
    private function perPartner(Company $company, Period $period, int $net): array
    {
        $shares = array_map(fn () => 0, Shares::effective($company));
        $unattributed = 0;
        $bySide = [];

        $rows = Lines::profitAndLoss($company, $period)
            ->selectRaw('a.role, l.partner_id, l.side_id, COALESCE(SUM(l.credit_paise - l.debit_paise), 0) as amount')
            ->groupBy('a.role', 'l.partner_id', 'l.side_id')
            ->get();

        foreach ($rows as $row) {
            // Income is credit − debit; an expense line is the same sum, already negative.
            $amount = (int) $row->amount;

            if ($row->partner_id !== null) {
                $id = (int) $row->partner_id;
                $shares[$id] = ($shares[$id] ?? 0) + $amount;
            } elseif ($row->role === AccountRole::Expense->value && $row->side_id !== null) {
                $bySide[(int) $row->side_id] = ($bySide[(int) $row->side_id] ?? 0) + $amount;
            } else {
                $unattributed += $amount;
            }
        }

        // A side-account expense is shared by the side's partners by their within-side equity.
        foreach ($bySide as $sideId => $amount) {
            $within = Shares::withinSide($company, $sideId);

            if (array_sum($within) === 0) {
                $unattributed += $amount;

                continue;
            }

            foreach (Rounding::largestRemainder($amount, $within) as $id => $part) {
                $shares[$id] = ($shares[$id] ?? 0) + $part;
            }
        }

        foreach (Rounding::largestRemainder($unattributed, Shares::effective($company)) as $id => $part) {
            $shares[$id] = ($shares[$id] ?? 0) + $part;
        }

        // Everything above is exact, so this always holds; it guards against a future slip.
        assert(array_sum($shares) === $net);

        return $shares;
    }
}
