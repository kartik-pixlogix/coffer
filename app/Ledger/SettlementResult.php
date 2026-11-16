<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Models\Side;

/** Settlements for a period: each side's row, the transfers that settle them, and the JV totals. */
final readonly class SettlementResult
{
    /**
     * @param  array<int, SideSettlement>  $sides  side id → row
     * @param  list<array{from: Side, to: Side, amount: int}>  $transfers
     */
    public function __construct(
        public array $sides,
        public array $transfers,
        public int $companyAccountNet,
        public int $netProfit,
    ) {}

    /** Σ remaining across sides: 0 by construction (invariant 5). */
    public function remainderSum(): int
    {
        return array_sum(array_map(fn (SideSettlement $s) => $s->remaining, $this->sides));
    }
}
