<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CapitalMovementKind;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A capital contribution or drawing (spec §11, §4.2): a partner puts money into a money
 * account (Dr account, Cr Capital) or takes it out (Dr Capital, Cr account). The source
 * of one posted journal entry; a delete reverses it.
 *
 * @property int $id
 * @property int $partner_id
 * @property CapitalMovementKind $kind
 * @property int $amount_paise
 * @property CarbonImmutable $moved_on
 * @property int $money_account_id
 * @property string|null $note
 * @property int|null $journal_entry_id
 * @property int|null $created_by
 * @property-read Partner $partner
 * @property-read MoneyAccount $moneyAccount
 */
#[Fillable(['company_id', 'partner_id', 'kind', 'amount_paise', 'moved_on', 'money_account_id', 'note', 'journal_entry_id', 'created_by'])]
class CapitalMovement extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected function casts(): array
    {
        return [
            'kind' => CapitalMovementKind::class,
            'amount_paise' => 'integer',
            'moved_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<MoneyAccount, $this> */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }
}
