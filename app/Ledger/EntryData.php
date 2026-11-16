<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\JournalType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** A journal entry to post through LedgerPoster. */
final readonly class EntryData
{
    /**
     * @param  list<LineData>  $lines
     */
    public function __construct(
        public CarbonImmutable $date,
        public JournalType $type,
        public array $lines,
        public ?string $memo = null,
        public ?Model $source = null,
        public ?User $postedBy = null,
    ) {}
}
