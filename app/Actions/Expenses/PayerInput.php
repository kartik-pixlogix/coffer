<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Enums\PayerType;

/** One validated "Paid by" line: who, from which account (null = personal card), how much. */
final readonly class PayerInput
{
    public function __construct(
        public PayerType $type,
        public ?int $partnerId,
        public ?int $memberId,
        public ?int $accountId,
        public int $amountPaise,
    ) {}
}
