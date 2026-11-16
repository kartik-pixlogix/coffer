<?php

declare(strict_types=1);

namespace App\Actions\Loans;

use App\Models\MoneyAccount;
use App\Models\Partner;
use Carbon\CarbonImmutable;

/**
 * A validated "Record loan" form (spec §12): the lending partner, amount and date, the
 * account it went into (null = converted from their personal-card balance), what it's
 * for and how it's repaid (a monthly instalment and/or a date it's due by).
 */
final readonly class LoanData
{
    public function __construct(
        public Partner $partner,
        public int $amountPaise,
        public CarbonImmutable $lentOn,
        public ?MoneyAccount $into,
        public ?string $purpose = null,
        public ?int $instalmentPaise = null,
        public ?CarbonImmutable $dueOn = null,
    ) {}
}
