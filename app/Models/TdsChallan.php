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

/**
 * TDS deposited with the government by challan (D163), for one section and month.
 *
 * @property int $id
 * @property string $section "194J"
 * @property CarbonImmutable $period the month's first day
 * @property int $amount_paise
 * @property CarbonImmutable $paid_on
 * @property string|null $bsr_code
 * @property string|null $challan_no
 * @property int $money_account_id
 * @property int|null $journal_entry_id
 * @property int|null $created_by
 * @property-read MoneyAccount $moneyAccount
 */
#[Fillable(['company_id', 'section', 'period', 'amount_paise', 'paid_on', 'bsr_code', 'challan_no', 'money_account_id', 'journal_entry_id', 'created_by'])]
class TdsChallan extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasVoucher;

    protected function casts(): array
    {
        return ['period' => 'immutable_date', 'paid_on' => 'immutable_date', 'amount_paise' => 'integer'];
    }

    /** @return BelongsTo<MoneyAccount, $this> */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function auditLabel(): string
    {
        return "TDS {$this->section} for ".$this->period->format('M Y');
    }
}
