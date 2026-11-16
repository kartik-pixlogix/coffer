<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Models\Account;

/** One line of an entry to post: an account, debit or credit, and its dimensions. */
final readonly class LineData
{
    public function __construct(
        public Account $account,
        public int $debit = 0,
        public int $credit = 0,
        public ?int $partnerId = null,
        public ?int $sideId = null,
        public ?int $memberId = null,
        public ?int $projectId = null,
    ) {}

    public static function debit(Account $account, int $paise, ?int $partnerId = null, ?int $sideId = null, ?int $memberId = null, ?int $projectId = null): self
    {
        return new self($account, debit: $paise, partnerId: $partnerId, sideId: $sideId, memberId: $memberId, projectId: $projectId);
    }

    public static function credit(Account $account, int $paise, ?int $partnerId = null, ?int $sideId = null, ?int $memberId = null, ?int $projectId = null): self
    {
        return new self($account, credit: $paise, partnerId: $partnerId, sideId: $sideId, memberId: $memberId, projectId: $projectId);
    }

    public function isEmpty(): bool
    {
        return $this->debit === 0 && $this->credit === 0;
    }

    /** The same line on the other side (for reversals). */
    public function flipped(): self
    {
        return new self($this->account, $this->credit, $this->debit, $this->partnerId, $this->sideId, $this->memberId, $this->projectId);
    }
}
