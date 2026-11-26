<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\AccountRole;
use App\Enums\JournalType;
use App\Models\Company;
use App\Models\Side;

/**
 * JV settlements (spec §13, D7, D34). For each side, over a period, from journal lines:
 *
 * - Holdings are the side's money accounts, its partners' current accounts and what's owed
 *   to members billed to its partners. Every income, expense, salary and reimbursement
 *   entry moves money in or out of holdings, so the change in holdings across all sides
 *   and the company account always equals net profit.
 * - Net held = that change for the side; Pooled = its share of the company account's;
 *   Entitled = its share of net profit; transfers sent and received come from
 *   settlement entries.
 * - Remaining = Net held + Pooled − Paid out + Paid in − Entitled, which sums to zero
 *   across sides by construction (invariant 5).
 */
final class SettlementCalculator
{
    /** A side owes or is owed only beyond ₹1 (spec §13). */
    public const int TOLERANCE_PAISE = 100;

    /** Tax accounts (D161) count too: their lines carry no side, so they pool by equity like the company account. */
    private const array HOLDINGS = [
        AccountRole::Money, AccountRole::PartnerCurrent, AccountRole::ReimbursementsPayable,
        AccountRole::GstInput, AccountRole::TdsReceivable, AccountRole::GstOutput, AccountRole::RcmPayable, AccountRole::TdsPayable,
    ];

    private const array CASH_TYPES = [JournalType::Income, JournalType::Expense, JournalType::Salary, JournalType::Reimbursement, JournalType::GstPayment, JournalType::TdsChallan];

    public function calculate(Company $company, Period $period): SettlementResult
    {
        $sides = Side::query()->withoutGlobalScope('company')->where('company_id', $company->id)->orderBy('position')->orderBy('id')->get();

        if (! $company->isJv() || $sides->isEmpty()) {
            return new SettlementResult([], [], 0, 0);
        }

        $rows = Lines::in($company, $period)
            ->whereIn('a.role', array_map(fn (AccountRole $r) => $r->value, self::HOLDINGS))
            ->whereIn('e.type', [...array_map(fn (JournalType $t) => $t->value, self::CASH_TYPES), JournalType::Settlement->value])
            ->selectRaw('e.type, l.side_id, COALESCE(SUM(l.debit_paise), 0) as debits, COALESCE(SUM(l.credit_paise), 0) as credits')
            ->groupBy('e.type', 'l.side_id')
            ->get();

        $blank = ['collected' => 0, 'paid' => 0, 'reimbursed' => 0, 'paidOut' => 0, 'paidIn' => 0];
        $flows = array_fill_keys($sides->pluck('id')->all(), $blank);
        $companyNet = 0;

        foreach ($rows as $row) {
            $net = (int) $row->debits - (int) $row->credits;
            $type = JournalType::from($row->type);

            if ($row->side_id === null) {
                if ($type !== JournalType::Settlement) {
                    $companyNet += $net;
                }

                continue;
            }

            $id = (int) $row->side_id;
            $flows[$id] ??= $blank;

            match ($type) {
                JournalType::Income => $flows[$id]['collected'] += $net,
                // A side paying the firm's GST or TDS paid for the pool, like an expense (D161).
                JournalType::Expense, JournalType::Salary, JournalType::GstPayment, JournalType::TdsChallan => $flows[$id]['paid'] -= $net,
                JournalType::Reimbursement => $flows[$id]['reimbursed'] += $net,
                JournalType::Settlement => $net >= 0 ? $flows[$id]['paidIn'] += $net : $flows[$id]['paidOut'] -= $net,
                default => null,
            };
        }

        $weights = Shares::sides($company);
        $netProfit = Lines::netProfit($company, $period);
        $pooled = Rounding::largestRemainder($companyNet, $weights);
        $entitled = Rounding::largestRemainder($netProfit, $weights);

        $result = [];

        foreach ($sides as $side) {
            $f = $flows[$side->id];
            $netHeld = $f['collected'] - $f['paid'] + $f['reimbursed'];
            $remaining = $netHeld + $pooled[$side->id] - $f['paidOut'] + $f['paidIn'] - $entitled[$side->id];

            $result[$side->id] = new SideSettlement(
                side: $side,
                shareBps: $side->equity_bps,
                collected: $f['collected'],
                paid: $f['paid'],
                netHeld: $netHeld,
                pooled: $pooled[$side->id],
                entitled: $entitled[$side->id],
                paidOut: $f['paidOut'],
                paidIn: $f['paidIn'],
                remaining: $remaining,
            );
        }

        return new SettlementResult($result, $this->transfers($result), $companyNet, $netProfit);
    }

    /**
     * The fewest transfers that settle everyone (spec §13, greedy match): the side that
     * owes most pays the side owed most, until everything is within ₹1.
     *
     * @param  array<int, SideSettlement>  $sides
     * @return list<array{from: Side, to: Side, amount: int}>
     */
    private function transfers(array $sides): array
    {
        $owes = [];
        $owed = [];

        foreach ($sides as $id => $s) {
            if ($s->remaining > self::TOLERANCE_PAISE) {
                $owes[$id] = $s->remaining;
            } elseif ($s->remaining < -self::TOLERANCE_PAISE) {
                $owed[$id] = -$s->remaining;
            }
        }

        $transfers = [];

        while ($owes !== [] && $owed !== []) {
            arsort($owes);
            arsort($owed);
            $from = (int) array_key_first($owes);
            $to = (int) array_key_first($owed);
            $amount = min($owes[$from], $owed[$to]);

            $transfers[] = ['from' => $sides[$from]->side, 'to' => $sides[$to]->side, 'amount' => $amount];

            $owes[$from] -= $amount;
            $owed[$to] -= $amount;

            if ($owes[$from] <= self::TOLERANCE_PAISE) {
                unset($owes[$from]);
            }

            if ($owed[$to] <= self::TOLERANCE_PAISE) {
                unset($owed[$to]);
            }
        }

        return $transfers;
    }
}
