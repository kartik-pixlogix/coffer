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
 * GST paid for a period (D163): output and reverse-charge tax cleared, input credit used and
 * the cash paid, as worked out by GstSetOff. `breakdown` keeps that working (JSON).
 *
 * @property int $id
 * @property CarbonImmutable $period the month's first day
 * @property CarbonImmutable $paid_on
 * @property int $money_account_id
 * @property string|null $reference CPIN or CIN
 * @property string $breakdown
 * @property int $cash_paise
 * @property int|null $journal_entry_id
 * @property int|null $created_by
 * @property-read MoneyAccount $moneyAccount
 */
#[Fillable(['company_id', 'period', 'paid_on', 'money_account_id', 'reference', 'breakdown', 'cash_paise', 'journal_entry_id', 'created_by'])]
class GstPayment extends Model
{
    use Audited;
    use BelongsToCompany;
    use HasVoucher;

    protected function casts(): array
    {
        return ['period' => 'immutable_date', 'paid_on' => 'immutable_date', 'cash_paise' => 'integer'];
    }

    /** @return array<string, mixed> */
    public function working(): array
    {
        $decoded = json_decode($this->breakdown, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return BelongsTo<MoneyAccount, $this> */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function auditLabel(): string
    {
        return 'GST payment for '.$this->period->format('M Y');
    }
}
