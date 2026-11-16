<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountRole;
use App\Enums\AccountType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account in a company's chart (spec §4.1). Create them through
 * App\Ledger\ChartOfAccounts, which picks the code and keeps one per role and record.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property AccountRole $role
 * @property int|null $money_account_id
 * @property int|null $partner_id
 * @property int|null $member_id
 * @property int|null $category_id
 * @property-read MoneyAccount|null $moneyAccount
 * @property-read Partner|null $partner
 * @property-read Member|null $member
 * @property-read Category|null $category
 */
#[Fillable(['company_id', 'code', 'name', 'type', 'role', 'money_account_id', 'partner_id', 'member_id', 'category_id'])]
class Account extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'role' => AccountRole::class,
        ];
    }

    /** @return BelongsTo<MoneyAccount, $this> */
    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
