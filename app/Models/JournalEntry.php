<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JournalType;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A balanced journal entry. Write them only through App\Ledger\LedgerPoster; a posted
 * entry never changes (a correction posts a reversal plus a new entry, D5).
 *
 * @property int $id
 * @property CarbonImmutable $date
 * @property JournalType $type
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $memo
 * @property int|null $posted_by
 * @property int|null $reverses_id
 * @property int|null $reversed_by_id
 * @property-read Collection<int, JournalLine> $lines
 * @property-read JournalEntry|null $reverses
 * @property-read JournalEntry|null $reversedBy
 */
#[Fillable(['company_id', 'date', 'type', 'source_type', 'source_id', 'memo', 'posted_by', 'reverses_id'])]
class JournalEntry extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'type' => JournalType::class,
        ];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_id !== null;
    }
}
