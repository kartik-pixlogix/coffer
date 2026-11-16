<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Ledger\LedgerPoster;
use App\Models\JournalEntry;
use App\Models\User;

/**
 * Reverses the journal entry a record posted (spec §4.1 "Corrections"), once: a record
 * whose entry is missing or already reversed is left alone.
 */
final class EntryReverser
{
    public function __construct(private readonly LedgerPoster $poster) {}

    public function reverse(?int $entryId, string $memo, User $by): void
    {
        $entry = $entryId !== null ? JournalEntry::query()->withoutGlobalScope('company')->find($entryId) : null;

        if ($entry !== null && ! $entry->isReversed()) {
            $this->poster->reverse($entry, memo: $memo, by: $by);
        }
    }
}
