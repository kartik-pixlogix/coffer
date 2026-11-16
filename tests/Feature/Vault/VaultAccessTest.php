<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Enums\VaultType;
use App\Models\Company;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultItem;
use App\Services\Vault\VaultAccess;
use App\Support\CurrentCompany;
use App\Support\Permissions\Catalog;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** The level `$user` has on `$item`, as the current company's signed-in user. */
function levelOf(User $user, Company $company, VaultItem $item): ?VaultAccessLevel
{
    test()->actingAs($user);
    app(CurrentCompany::class)->set($company, $company->roleOf($user));
    app(VaultAccess::class)->flush();

    return app(VaultAccess::class)->itemLevel($user, $item->fresh());
}

test('Admins manage everything; others need a grant, capped by their role', function () {
    $company = Company::factory()->create();
    $item = vaultItemFor($company, ['password' => 'Pa55-word-long-enough']);
    $admin = vaultUser(CompanyRole::Admin, $company);
    $partner = vaultUser(CompanyRole::Partner, $company);
    $member = vaultUser(CompanyRole::Member, $company);
    $viewer = vaultUser(CompanyRole::Viewer, $company);

    expect(levelOf($admin, $company, $item))->toBe(VaultAccessLevel::Manage)
        ->and(levelOf($partner, $company, $item))->toBeNull()
        ->and(levelOf($member, $company, $item))->toBeNull();

    vaultGrant($partner, $item->vault, VaultAccessLevel::Manage);
    vaultGrant($member, $item->vault, VaultAccessLevel::Manage);
    vaultGrant($viewer, $item->vault, VaultAccessLevel::Manage);

    expect(levelOf($partner, $company, $item))->toBe(VaultAccessLevel::Manage)
        ->and(levelOf($member, $company, $item))->toBe(VaultAccessLevel::Manage)
        // Viewers can't open the vault page at all (spec §2.1).
        ->and(levelOf($viewer, $company, $item))->toBeNull();
});

test('without "Reveal and copy" the ceiling is View; without "Share and manage" it is Edit', function () {
    $company = Company::factory()->create();
    $item = vaultItemFor($company);
    $partner = vaultUser(CompanyRole::Partner, $company);
    vaultGrant($partner, $item->vault, VaultAccessLevel::Manage);

    setPermissionsTeamId($company->id);
    $role = Role::query()->where('company_id', $company->id)->where('name', 'partner')->sole();
    $role->revokePermissionTo(Catalog::actionPermission('vault.share'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(levelOf($partner->fresh(), $company, $item))->toBe(VaultAccessLevel::Edit);

    $role->revokePermissionTo(Catalog::actionPermission('vault.reveal'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(levelOf($partner->fresh(), $company, $item))->toBe(VaultAccessLevel::View);
});

test('an item grant wins over the vault grant, restricted items ignore vault grants, ended grants count for nothing', function () {
    $company = Company::factory()->create();
    $item = vaultItemFor($company);
    $other = vaultItemFor($company, [], ['name' => 'AWS — root account', 'restricted' => true]);
    $partner = vaultUser(CompanyRole::Partner, $company);

    vaultGrant($partner, $item->vault, VaultAccessLevel::Edit);
    expect(levelOf($partner, $company, $item))->toBe(VaultAccessLevel::Edit)
        ->and(levelOf($partner, $company, $other))->toBeNull();

    vaultGrant($partner, $item, VaultAccessLevel::View);
    expect(levelOf($partner, $company, $item))->toBe(VaultAccessLevel::View);

    vaultGrant($partner, $other, VaultAccessLevel::Use, CarbonImmutable::now()->subMinute());
    expect(levelOf($partner, $company, $other))->toBeNull();
});

test('a personal vault is its owner’s alone, Admins included', function () {
    $company = Company::factory()->create();
    $owner = vaultUser(CompanyRole::Partner, $company);
    $admin = vaultUser(CompanyRole::Admin, $company);
    $vault = app(CurrentCompany::class)->run($company, fn () => Vault::query()->create(['name' => 'Aarav’s logins', 'type' => VaultType::Personal, 'owner_id' => $owner->id]));
    $item = vaultItemFor($company, [], [], $vault);

    expect(levelOf($owner, $company, $item))->toBe(VaultAccessLevel::Manage)
        ->and(levelOf($admin, $company, $item))->toBeNull();
});
