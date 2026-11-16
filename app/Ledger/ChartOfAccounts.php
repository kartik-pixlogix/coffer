<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Enums\AccountRole;
use App\Enums\CategoryKind;
use App\Enums\MoneyAccountKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\Company;
use App\Models\Member;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\Side;
use Illuminate\Support\Facades\DB;

/**
 * A company's chart of accounts (spec §4.1). Accounts for money accounts, partners,
 * members and categories are found or created on first use, so adding a partner or a
 * category never needs a hook; `setUp` lays down the defaults at launch.
 */
final class ChartOfAccounts
{
    /** Categories, money accounts and the accounts every company starts with. */
    public function setUp(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            foreach (Category::DEFAULTS as $kind => $names) {
                foreach ($names as $position => $name) {
                    $category = Category::query()->withoutGlobalScope('company')->firstOrCreate(
                        ['company_id' => $company->id, 'kind' => $kind, 'name' => $name],
                        ['system' => $name === Category::SALARY && $kind === CategoryKind::Expense->value, 'position' => $position],
                    );

                    $kind === CategoryKind::Income->value ? $this->income($category) : $this->expense($category);
                }
            }

            $this->money($this->companyAccount($company));

            foreach (Side::query()->withoutGlobalScope('company')->where('company_id', $company->id)->orderBy('position')->get() as $side) {
                $this->money($this->sideAccount($side));
            }

            foreach (Partner::query()->withoutGlobalScope('company')->where('company_id', $company->id)->orderBy('position')->get() as $partner) {
                $this->capital($partner);
                $this->partnerCurrent($partner);
            }
        });
    }

    /** "Company account": the Single company's account, or the JV-level one. */
    public function companyAccount(Company $company): MoneyAccount
    {
        /** @var MoneyAccount */
        return MoneyAccount::query()->withoutGlobalScope('company')->firstOrCreate(
            ['company_id' => $company->id, 'kind' => MoneyAccountKind::Company->value],
            ['name' => 'Company account', 'position' => 0],
        );
    }

    /** "<Side> account": each JV side's own account (spec §3.1). */
    public function sideAccount(Side $side): MoneyAccount
    {
        /** @var MoneyAccount */
        return MoneyAccount::query()->withoutGlobalScope('company')->firstOrCreate(
            ['company_id' => $side->company_id, 'kind' => MoneyAccountKind::Side->value, 'side_id' => $side->id],
            ['name' => "{$side->name} account", 'position' => $side->position + 1],
        );
    }

    /** The account a partner's money is paid from: their side's account in a JV, else the company account. */
    public function payingAccountFor(Partner $partner): MoneyAccount
    {
        return $partner->side_id === null
            ? $this->companyAccount($partner->company()->withoutGlobalScopes()->firstOrFail())
            : $this->sideAccount($partner->side()->withoutGlobalScopes()->firstOrFail());
    }

    public function salaryCategory(Company $company): Category
    {
        /** @var Category */
        return Category::query()->withoutGlobalScope('company')->firstOrCreate(
            ['company_id' => $company->id, 'kind' => CategoryKind::Expense->value, 'name' => Category::SALARY],
            ['system' => true, 'position' => 0],
        );
    }

    public function money(MoneyAccount $moneyAccount): Account
    {
        return $this->account($moneyAccount->company_id, AccountRole::Money, 'money_account_id', $moneyAccount->id, $moneyAccount->name);
    }

    public function partnerCurrent(Partner $partner): Account
    {
        return $this->account($partner->company_id, AccountRole::PartnerCurrent, 'partner_id', $partner->id, "Partner current — {$partner->name}");
    }

    public function loansPayable(Partner $partner): Account
    {
        return $this->account($partner->company_id, AccountRole::LoansPayable, 'partner_id', $partner->id, "Loans payable — {$partner->name}");
    }

    public function reimbursementsPayable(Member $member): Account
    {
        return $this->account($member->company_id, AccountRole::ReimbursementsPayable, 'member_id', $member->id, "Reimbursements payable — {$member->name}");
    }

    public function capital(Partner $partner): Account
    {
        return $this->account($partner->company_id, AccountRole::Capital, 'partner_id', $partner->id, "Capital — {$partner->name}");
    }

    public function income(Category $category): Account
    {
        return $this->account($category->company_id, AccountRole::Income, 'category_id', $category->id, "Income — {$category->name}");
    }

    public function expense(Category $category): Account
    {
        return $this->account($category->company_id, AccountRole::Expense, 'category_id', $category->id, "Expenses — {$category->name}");
    }

    private function account(int $companyId, AccountRole $role, string $reference, int $id, string $name): Account
    {
        $existing = Account::query()->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('role', $role->value)
            ->where($reference, $id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($companyId, $role, $reference, $id, $name): Account {
            // Lock the role's codes so two new accounts can't take the same number.
            $codes = Account::query()->withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('role', $role->value)
                ->lockForUpdate()
                ->pluck('code');

            $next = 1 + (int) $codes->map(fn (string $code) => (int) (str_contains($code, '-') ? substr($code, strpos($code, '-') + 1) : substr($code, 1)))->max();

            /** @var Account */
            return Account::query()->create([
                'company_id' => $companyId,
                'code' => $role->code($next),
                'name' => $name,
                'type' => $role->type(),
                'role' => $role,
                $reference => $id,
            ]);
        });
    }
}
