<?php

declare(strict_types=1);

namespace App\Actions\Loans;

use Carbon\CarbonImmutable;

/** A validated "Edit loan" form (A6, D138): the amount, date, what it's for and the terms. */
final readonly class LoanEdit
{
    public function __construct(
        public int $amountPaise,
        public CarbonImmutable $lentOn,
        public ?string $purpose = null,
        public ?int $instalmentPaise = null,
        public ?CarbonImmutable $dueOn = null,
    ) {}
}
