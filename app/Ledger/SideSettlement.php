<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Models\Side;

/** One side's row on the settlements page (spec §13), in paise. */
final readonly class SideSettlement
{
    public function __construct(
        public Side $side,
        public int $shareBps,
        public int $collected,
        public int $paid,
        public int $netHeld,
        public int $pooled,
        public int $entitled,
        public int $paidOut,
        public int $paidIn,
        public int $remaining,
    ) {}

    /** OWES above ₹1, OWED below −₹1, otherwise SETTLED. */
    public function status(): string
    {
        return match (true) {
            $this->remaining > SettlementCalculator::TOLERANCE_PAISE => 'owes',
            $this->remaining < -SettlementCalculator::TOLERANCE_PAISE => 'owed',
            default => 'settled',
        };
    }
}
