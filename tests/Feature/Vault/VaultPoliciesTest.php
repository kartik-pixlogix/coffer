<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\VaultAuditEvent;
use App\Models\VaultPolicySetting;
use App\Services\Vault\VaultPolicy;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function policyForm(array $overrides = []): array
{
    return $overrides + [
        'reauth_minutes' => 15, 'idle_lock_minutes' => 15, 'fresh_code_each_reveal' => false, 'lock_on_close' => false,
        'clipboard_clear_seconds' => 30, 'reveal_seconds' => 30, 'export_who' => 'admins', 'export_reason' => true,
        'breach_checks' => true, 'generator_min_length' => 12,
    ];
}

test('Admins see and save the policies; each change is audited with old and new values', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    vaultIn($company);

    $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.policies'))
        ->assertInertia(fn (Assert $page) => $page->component('vault/policies')
            ->where('policy', policyForm())
            ->where('options.reauth', [5, 15, 60, 480])
            ->where('breachAvailable', false));

    $this->actingAs($admin)->withSession(vaultUnlocked())
        ->put(route('vault.policies.update'), policyForm(['reauth_minutes' => 5, 'clipboard_clear_seconds' => 60, 'lock_on_close' => true, 'generator_min_length' => 20]))
        ->assertRedirect()->assertInertiaFlash('toast.message', 'Vault policies saved. They apply on next unlock.');

    $policy = VaultPolicy::for($company);
    expect($policy->reauthMinutes)->toBe(5)->and($policy->clipboardClearSeconds)->toBe(60)
        ->and($policy->lockOnClose)->toBeTrue()->and($policy->generatorMinLength)->toBe(20)
        ->and(VaultPolicySetting::query()->withoutGlobalScopes()->sole()->updated_by)->toBe($admin->id);

    $event = VaultAuditEvent::query()->withoutGlobalScopes()->where('action', 'policies_changed')->sole();
    expect($event->meta)->toBe([
        'reauth_minutes' => '15 → 5',
        'lock_on_close' => 'off → on',
        'clipboard_clear_seconds' => '30 → 60',
        'generator_min_length' => '12 → 20',
    ]);

    // The vault page and the add form take their timings from the saved policy.
    $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.index'))
        ->assertInertia(fn (Assert $page) => $page->where('vaultState.policy.reauthMinutes', 5)->where('vaultState.policy.clipboardClearSeconds', 60)->where('vaultState.policy.lockOnClose', true));
    $this->actingAs($admin)->withSession(vaultUnlocked())->get(route('vault.items.create', ['provider' => 'github']))
        ->assertInertia(fn (Assert $page) => $page->where('generatorMinLength', 20));

    // Saving the same values writes nothing.
    $this->actingAs($admin)->withSession(vaultUnlocked())->put(route('vault.policies.update'), policyForm(['reauth_minutes' => 5, 'clipboard_clear_seconds' => 60, 'lock_on_close' => true, 'generator_min_length' => 20]))
        ->assertInertiaFlash('toast.message', 'Nothing changed');
    expect(VaultAuditEvent::query()->withoutGlobalScopes()->where('action', 'policies_changed')->count())->toBe(1);
});

test('policies are Admin only, validated, per company, and need the vault unlocked to save', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    $partner = vaultUser(CompanyRole::Partner, $company);
    $other = Company::factory()->create();

    $this->actingAs($partner)->withSession(vaultUnlocked())->get(route('vault.policies'))->assertForbidden();
    $this->actingAs($partner)->withSession(vaultUnlocked())->put(route('vault.policies.update'), policyForm())->assertForbidden();

    $this->actingAs($admin)->withSession(vaultUnlocked())->put(route('vault.policies.update'), policyForm(['reauth_minutes' => 7, 'export_who' => 'everyone', 'generator_min_length' => 8]))
        ->assertSessionHasErrors(['reauth_minutes', 'export_who', 'generator_min_length']);

    $this->actingAs($admin)->withSession(vaultUnlocked())->put(route('vault.policies.update'), policyForm(['idle_lock_minutes' => 5]))->assertRedirect();
    expect(VaultPolicy::for($company)->idleLockMinutes)->toBe(5)
        ->and(VaultPolicy::for($other)->idleLockMinutes)->toBe(15);

    $this->flushSession();
    $this->actingAs($admin)->put(route('vault.policies.update'), policyForm(['idle_lock_minutes' => 60]))
        ->assertInertiaFlash('toast.message', 'The vault locked. Unlock it to continue.');
    expect(VaultPolicy::for($company)->idleLockMinutes)->toBe(5);
});

test('the idle lock follows the saved minutes on the server', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    VaultPolicySetting::query()->forceCreate(['company_id' => $company->id, ...policyForm(['idle_lock_minutes' => 5])]);
    $item = vaultItemFor($company, ['password' => 'demo-Policy-1!']);

    $sixMinutesAgo = now()->subMinutes(6)->getTimestamp();
    $this->actingAs($admin)->withSession(['vault.unlocked_at' => $sixMinutesAgo, 'vault.active_at' => $sixMinutesAgo, 'vault.verified_at' => $sixMinutesAgo])
        ->postJson(route('vault.items.reveal', $item), ['field' => 'password'])->assertStatus(423);
});

test('"Ask again before revealing" makes each code good for one reveal', function () {
    $company = Company::factory()->create();
    $admin = vaultUser(CompanyRole::Admin, $company);
    VaultPolicySetting::query()->forceCreate(['company_id' => $company->id, ...policyForm(['fresh_code_each_reveal' => true])]);
    $item = vaultItemFor($company, ['password' => 'demo-Policy-2!']);

    $this->actingAs($admin)->withSession(vaultUnlocked());
    $this->get(route('vault.index'))->assertInertia(fn (Assert $page) => $page->where('vaultState.verifiedUntil', null));
    $this->postJson(route('vault.items.reveal', $item), ['field' => 'password'])->assertOk();
    $this->postJson(route('vault.items.copy', $item), ['field' => 'password'])->assertStatus(428);

    // A new code (here: the session's step-up time moves on) allows one more.
    CarbonImmutable::setTestNow('2026-11-15 10:01:00');
    $this->withSession(['vault.verified_at' => now()->getTimestamp()]);
    $this->postJson(route('vault.items.copy', $item), ['field' => 'password'])->assertOk();
    $this->postJson(route('vault.items.reveal', $item), ['field' => 'password'])->assertStatus(428);
});
