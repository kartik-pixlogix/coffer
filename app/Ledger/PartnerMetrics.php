<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\AccountRole;
use App\Models\Company;
use App\Models\TimeEntry;

/**
 * Per-partner figures for the Partners page (spec §6, D39), read from journal lines with
 * the same attribution as profit sharing (§4.4):
 *
 * - Revenue: income brought in by P.
 * - Direct expenses ("Expenses" column): non-salary costs on P's line — paid by P,
 *   on P's card, or by a member billed to P.
 * - Other ("Other" column): P's share of costs nobody paid personally — a side account's
 *   split by within-side equity, the rest by effective equity.
 * - Salary cost: salary of members billed to P.
 * - Hours: timesheet hours of entries billed to P.
 *
 * Expenses on the cards are the three together; net contribution = revenue − that.
 */
final class PartnerMetrics
{
    private const array BLANK = ['revenue' => 0, 'direct' => 0, 'other' => 0, 'salary' => 0, 'hoursCenti' => 0];

    /** @return array<int, array{revenue: int, direct: int, other: int, salary: int, hoursCenti: int}> partner id → figures */
    public function forPeriod(Company $company, Period $period): array
    {
        $effective = Shares::effective($company);
        $figures = array_map(fn () => self::BLANK, $effective);
        $bySide = [];
        $unattributed = 0;

        $rows = Lines::profitAndLoss($company, $period)
            ->leftJoin('categories as c', 'c.id', '=', 'a.category_id')
            ->selectRaw('a.role, l.partner_id, l.side_id, COALESCE(c.system, false) as is_salary, COALESCE(SUM(l.debit_paise - l.credit_paise), 0) as spent')
            ->groupBy('a.role', 'l.partner_id', 'l.side_id', 'c.system')
            ->get();

        foreach ($rows as $row) {
            $spent = (int) $row->spent;
            $partner = $row->partner_id !== null ? (int) $row->partner_id : null;

            // An archived partner can still have lines from before.
            if ($partner !== null && ! isset($figures[$partner])) {
                $figures[$partner] = self::BLANK;
            }

            if ($row->role === AccountRole::Income->value) {
                if ($partner !== null) {
                    $figures[$partner]['revenue'] -= $spent;
                }

                continue;
            }

            if ($partner !== null) {
                $figures[$partner][(bool) $row->is_salary ? 'salary' : 'direct'] += $spent;
            } elseif ($row->side_id !== null) {
                $bySide[(int) $row->side_id] = ($bySide[(int) $row->side_id] ?? 0) + $spent;
            } else {
                $unattributed += $spent;
            }
        }

        foreach ($bySide as $sideId => $spent) {
            $within = Shares::withinSide($company, $sideId);

            if (array_sum($within) === 0) {
                $unattributed += $spent;

                continue;
            }

            foreach (Rounding::largestRemainder($spent, $within) as $id => $part) {
                $figures[$id] ??= self::BLANK;
                $figures[$id]['other'] += $part;
            }
        }

        if (array_sum($effective) > 0) {
            foreach (Rounding::largestRemainder($unattributed, $effective) as $id => $part) {
                $figures[$id] ??= self::BLANK;
                $figures[$id]['other'] += $part;
            }
        }

        $hours = TimeEntry::query()->withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->when($period->from !== null, fn ($q) => $q->where('date', '>=', $period->from?->toDateString()))
            ->where('date', '<=', $period->to->toDateString())
            ->groupBy('partner_id')
            ->selectRaw('partner_id, SUM(hours_centi) as centi')
            ->pluck('centi', 'partner_id');

        foreach ($hours as $id => $centi) {
            if (isset($figures[(int) $id])) {
                $figures[(int) $id]['hoursCenti'] = (int) $centi;
            }
        }

        return $figures;
    }
}
