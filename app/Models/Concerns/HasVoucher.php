<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that posts a journal entry (`journal_entry_id`) shows that entry's voucher number
 * (D154) on its page, drawer and export. Eager-load `voucherEntry:id,voucher_no` first.
 *
 * @mixin Model
 */
trait HasVoucher
{
    /** @return BelongsTo<JournalEntry, $this> */
    public function voucherEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id')->withoutGlobalScopes();
    }

    /** "INC/2026-27/0001", or null before it posts (a pending expense). */
    public function voucher(): ?string
    {
        if ($this->getAttribute('journal_entry_id') === null) {
            return null;
        }

        /** @var JournalEntry|null $entry */
        $entry = $this->getRelationValue('voucherEntry');

        return $entry?->voucher_no;
    }
}
