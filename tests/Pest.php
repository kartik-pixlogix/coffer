<?php

declare(strict_types=1);

use App\Actions\Companies\SetCompanyRole;
use App\Actions\Vault\SaveVaultItem;
use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Ledger\ChartOfAccounts;
use App\Ledger\LedgerGuard;
use App\Models\Category;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\Side;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultGrant;
use App\Models\VaultItem;
use App\Services\Vault\VaultAccess;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/*
| Feature tests boot the app and run against a fresh database.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Stripe is never reached from a test (D80): see FakeStripe::block().
    ->beforeEach(fn () => FakeStripe::block())
    ->in('Feature', 'Unit');

/**
 * A verified user with `$role` in `$company` (a new Single company by default).
 *
 * @param  array<string, mixed>  $attributes
 */
function userWithRole(CompanyRole $role = CompanyRole::Admin, ?Company $company = null, array $attributes = []): User
{
    $company ??= Company::factory()->create();
    $user = User::factory()->create($attributes);

    app(SetCompanyRole::class)->handle($company, $user, $role);

    return $user->refresh();
}

/** The first (and usually only) company a test user belongs to. */
function companyOf(User $user): Company
{
    /** @var Company */
    return $user->companies()->firstOrFail();
}

/**
 * A Single company with books set up: Vertex Labs, Aarav 60% / Ishita 40%.
 *
 * @return object{company: Company, aarav: Partner, ishita: Partner, account: MoneyAccount}
 */
function singleBooks(int $openingPaise = 0): object
{
    $company = Company::factory()->create(['name' => 'Vertex Labs Pvt Ltd', 'opening_balance_paise' => $openingPaise]);
    $aarav = Partner::factory()->for($company)->create(['name' => 'Aarav Mehta', 'equity_bps' => 6000, 'colour' => 1, 'position' => 0]);
    $ishita = Partner::factory()->for($company)->create(['name' => 'Ishita Rao', 'equity_bps' => 4000, 'colour' => 2, 'position' => 1]);

    $chart = app(ChartOfAccounts::class);
    $chart->setUp($company);

    return (object) ['company' => $company, 'aarav' => $aarav, 'ishita' => $ishita, 'account' => $chart->companyAccount($company)];
}

/**
 * A joint venture with books set up: Saarthi Portal JV, Vertex Labs 50% (Aarav 60 / Ishita 40)
 * and Bluefin Infotech 50% (Kabir 50 / Meera 50).
 */
function jvBooks(int $openingPaise = 0): object
{
    $company = Company::factory()->jv()->create(['name' => 'Saarthi Portal JV', 'opening_balance_paise' => $openingPaise]);
    $vertex = Side::factory()->for($company)->create(['name' => 'Vertex Labs', 'equity_bps' => 5000, 'position' => 0]);
    $bluefin = Side::factory()->for($company)->create(['name' => 'Bluefin Infotech', 'equity_bps' => 5000, 'position' => 1]);

    $partner = fn (string $name, $side, int $bps, int $colour, int $position) => Partner::factory()->for($company)
        ->create(['name' => $name, 'side_id' => $side->id, 'equity_bps' => $bps, 'colour' => $colour, 'position' => $position]);

    $books = (object) [
        'company' => $company,
        'vertex' => $vertex,
        'bluefin' => $bluefin,
        'aarav' => $partner('Aarav Mehta', $vertex, 6000, 1, 0),
        'ishita' => $partner('Ishita Rao', $vertex, 4000, 2, 1),
        'kabir' => $partner('Kabir Shah', $bluefin, 5000, 3, 2),
        'meera' => $partner('Meera Iyer', $bluefin, 5000, 4, 3),
    ];

    $chart = app(ChartOfAccounts::class);
    $chart->setUp($company);

    $books->account = $chart->companyAccount($company);
    $books->vertexAccount = $chart->sideAccount($vertex);
    $books->bluefinAccount = $chart->sideAccount($bluefin);

    return $books;
}

/** A company's category by kind and name ("income", "Project"). */
function category(Company $company, string $kind, string $name): Category
{
    return Category::query()->withoutGlobalScopes()
        ->where('company_id', $company->id)->where('kind', $kind)->where('name', $name)->firstOrFail();
}

/** 1 April 2026 + `$days`. */
function fyDay(int $days = 0): CarbonImmutable
{
    return CarbonImmutable::create(2026, 4, 1)->addDays($days);
}

/**
 * A journal entry's lines as "account: Dr|Cr paise [p=… s=… m=…]" strings, for exact
 * comparison in posting tests.
 *
 * @return list<string>
 */
function journalLines(int|JournalEntry|null $entry): array
{
    $id = $entry instanceof JournalEntry ? $entry->id : $entry;

    return JournalLine::query()->withoutGlobalScopes()->with(['account' => fn ($q) => $q->withoutGlobalScopes()])
        ->where('journal_entry_id', $id)->orderBy('id')->get()
        ->map(function (JournalLine $l) {
            $dims = array_filter(['p' => $l->partner_id, 's' => $l->side_id, 'm' => $l->member_id]);
            $dimText = $dims === [] ? '' : ' ['.implode(' ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($dims), $dims)).']';

            return $l->account->name.': '.($l->debit_paise > 0 ? 'Dr '.$l->debit_paise : 'Cr '.$l->credit_paise).$dimText;
        })->values()->all();
}

/**
 * Changes posted journal rows behind LedgerPoster's back, as a bug or a bad manual fix would.
 * The database guard (D148) refuses that, so tests that simulate it lift the guard.
 */
function tamperWithLedger(callable $change): mixed
{
    return LedgerGuard::unlocked($change);
}

/** The 2FA setup key every vault test user gets (base32), so tests can compute real codes. */
const VAULT_TEST_2FA = 'JBSWY3DPEHPK3PXP';

/** A user with 2FA on (the vault needs it, spec §14.3). */
function vaultUser(CompanyRole $role = CompanyRole::Admin, ?Company $company = null): User
{
    return userWithRole($role, $company, [
        'two_factor_secret' => encrypt(VAULT_TEST_2FA),
        'two_factor_recovery_codes' => encrypt(json_encode(['VAULT-RECOVERY-1', 'VAULT-RECOVERY-2'])),
        'two_factor_confirmed_at' => now(),
    ]);
}

/** Session state of a vault unlocked with a 2FA code just now (unlocked + verified). */
function vaultUnlocked(bool $verified = true): array
{
    $now = now()->getTimestamp();

    return ['vault.unlocked_at' => $now, 'vault.active_at' => $now, ...($verified ? ['vault.verified_at' => $now] : [])];
}

function vaultIn(Company $company, string $name = 'Internal'): Vault
{
    return app(CurrentCompany::class)->run($company, fn () => Vault::query()->firstOrCreate(['company_id' => $company->id, 'name' => $name]));
}

/**
 * A login item saved through SaveVaultItem. `$secrets` are template secrets (password,
 * totp, recovery_codes) plus notes.
 *
 * @param  array<string, string>  $secrets
 * @param  array<string, mixed>  $data  overrides for the form data
 */
function vaultItemFor(Company $company, array $secrets = [], array $data = [], ?Vault $vault = null, ?User $by = null): VaultItem
{
    $vault ??= vaultIn($company);
    $by ??= User::factory()->create();

    return app(CurrentCompany::class)->run($company, fn () => app(SaveVaultItem::class)->handle($by, $data + [
        'vault_id' => $vault->id,
        'type' => 'login',
        'provider' => 'github',
        'name' => 'GitHub — vertexlabs org',
        'environment' => 'production',
        'fields' => ['url' => 'https://github.com/login', 'username' => 'vertexlabs-admin'],
        'secrets' => $secrets,
        'tags' => [],
    ], null, [], true));
}

/** Give a user a grant on a vault or an item. */
function vaultGrant(User $user, Vault|VaultItem $target, VaultAccessLevel $level, ?CarbonImmutable $ends = null): VaultGrant
{
    $grant = VaultGrant::query()->create([
        'company_id' => $target->company_id,
        $target instanceof Vault ? 'vault_id' : 'vault_item_id' => $target->id,
        'user_id' => $user->id,
        'level' => $level,
        'expires_at' => $ends,
    ]);
    app(VaultAccess::class)->flush();

    return $grant;
}

/** Take a page or action permission (`page.expenses`, `action.settlements.record`) away from a role in a company's matrix. */
function revokeFromRole(Company $company, CompanyRole $role, string $permission): void
{
    setPermissionsTeamId($company->id);
    Role::query()->where('company_id', $company->id)->where('name', $role->value)->sole()
        ->revokePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}
