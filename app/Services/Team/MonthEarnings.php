<?php

declare(strict_types=1);

namespace App\Services\Team;

/** One member's month: hours logged (hundredths, D35) and what they earned in paise. */
final readonly class MonthEarnings
{
    public function __construct(
        public int $hoursCenti,
        public int $billableCenti,
        public int $amountPaise,
        /** The month is paid as a fixed monthly cost. */
        public bool $fixed,
        /** Active on at least one day of the month. */
        public bool $active,
    ) {}
}
