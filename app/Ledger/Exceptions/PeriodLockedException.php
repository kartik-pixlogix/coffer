<?php

declare(strict_types=1);

namespace App\Ledger\Exceptions;

/** Spec §4.6: nothing dated inside a closed fiscal year can be posted, edited or reversed. */
final class PeriodLockedException extends LedgerException
{
    public static function for(string $label): self
    {
        return new self("{$label} is closed, so nothing dated inside it can change. An Admin can reopen it in Settings.");
    }
}
