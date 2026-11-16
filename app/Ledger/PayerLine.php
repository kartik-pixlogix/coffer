<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Models\Member;
use App\Models\MoneyAccount;
use App\Models\Partner;

/**
 * One payer of an expense (spec §10 "Paid by"): who paid (a partner, a member, or the
 * company) and where from (a money account, or a personal card to reimburse).
 */
final readonly class PayerLine
{
    private function __construct(
        public int $amount,
        public ?Partner $partner,
        public ?Member $member,
        public ?MoneyAccount $from,
    ) {}

    /** The company paid from one of its money accounts. */
    public static function company(int $amount, MoneyAccount $from): self
    {
        return new self($amount, null, null, $from);
    }

    /** A partner paid: from a money account, or (null) with a personal card. */
    public static function partner(int $amount, Partner $partner, ?MoneyAccount $from = null): self
    {
        return new self($amount, $partner, null, $from);
    }

    /** A team member paid: from a money account, or (null) personally, to be reimbursed. */
    public static function member(int $amount, Member $member, ?MoneyAccount $from = null): self
    {
        return new self($amount, null, $member, $from);
    }

    public function isPersonal(): bool
    {
        return $this->from === null;
    }
}
