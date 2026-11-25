<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasVoucher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A JV settlement transfer from one side's account to another's (spec §13, §4.2):
 * Dr the receiving side's account, Cr the paying side's. An edit reverses and re-posts
 * it; a delete reverses it.
 *
 * @property int $id
 * @property int $from_side_id
 * @property int $to_side_id
 * @property int $amount_paise
 * @property CarbonImmutable $transferred_on
 * @property string|null $reference
 * @property int|null $journal_entry_id
 * @property int|null $created_by
 * @property-read Side $fromSide
 * @property-read Side $toSide
 * @property-read User|null $creator
 */
#[Fillable(['company_id', 'from_side_id', 'to_side_id', 'amount_paise', 'transferred_on', 'reference', 'journal_entry_id', 'created_by'])]
class SettlementTransfer extends Model
{
    use Audited;
    use BelongsToCompany, SoftDeletes;
    use HasVoucher;

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'transferred_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Side, $this> */
    public function fromSide(): BelongsTo
    {
        return $this->belongsTo(Side::class, 'from_side_id');
    }

    /** @return BelongsTo<Side, $this> */
    public function toSide(): BelongsTo
    {
        return $this->belongsTo(Side::class, 'to_side_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditLabel(): string
    {
        return 'Settlement transfer #'.$this->id;
    }
}
