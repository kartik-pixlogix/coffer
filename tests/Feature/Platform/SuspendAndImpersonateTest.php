<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Filament\Resources\Workspaces\Pages\ViewWorkspace;
use App\Models\Company;
use App\Models\Impersonation;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\WorkspaceSuspension;
use App\Services\Platform\Impersonator;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Suspend / unsuspend and impersonation (spec §22, D86): audited, a clear suspended page,
| the impersonation banner and stop action, and no vault secrets while impersonating.
*/

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->withoutVite();
    $this->admin = PlatformAdmin::query()->create(['name' => 'Priya Platform', 'email' => 'priya@coffer.test', 'password' => 'platform-password']);
    // D94: the panel needs a second factor set up.
    $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
});

/** The session marker Impersonator::start writes, for HTTP tests. */
function impersonating(PlatformAdmin $admin, User $user, ?int $id = null): array
{
    return [Impersonator::SESSION => [
        'id' => $id ?? 0,
        'admin_id' => $admin->id,
        'admin_name' => $admin->name,
        'user_id' => $user->id,
        'workspace_id' => null,
        'reason' => 'Support ticket 4411: reports look wrong',
    ]];
}

it('suspends a workspace with a reason, audited, and blocks its tenants with a clear page', function () {
    $tenant = userWithRole(CompanyRole::Admin);
    $workspace = companyOf($tenant)->workspace;

    $this->actingAs($this->admin, 'platform');
    Livewire::test(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->callAction('suspend', ['reason' => 'Chargeback on the last invoice'])
        ->assertHasNoFormErrors();

    expect($workspace->refresh()->isSuspended())->toBeTrue()
        ->and($workspace->suspended_reason)->toBe('Chargeback on the last invoice')
        ->and($workspace->suspended_by)->toBe($this->admin->id);

    $log = Activity::query()->where('log_name', 'platform')->where('description', 'workspace suspended')->sole();
    expect($log->causer?->is($this->admin))->toBeTrue()
        ->and($log->subject?->is($workspace))->toBeTrue()
        ->and($log->properties['reason'])->toBe('Chargeback on the last invoice');

    Auth::shouldUse('web');
    $this->actingAs($tenant, 'web')->get('/dashboard')
        ->assertStatus(423)
        ->assertInertia(fn (Assert $page) => $page->component('errors/suspended')->where('workspace', $workspace->name));
    $this->getJson('/notifications')->assertStatus(423)->assertJson(['suspended' => true]);
    $this->post('/logout')->assertRedirect();
});

it('needs a reason to suspend', function () {
    $workspace = companyOf(userWithRole(CompanyRole::Admin))->workspace;

    $this->actingAs($this->admin, 'platform');
    Livewire::test(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->callAction('suspend', ['reason' => ''])
        ->assertHasFormErrors(['reason' => 'required']);

    expect($workspace->refresh()->isSuspended())->toBeFalse();
});

it('offers companies in other workspaces on the suspended page, and unsuspending restores access', function () {
    $tenant = userWithRole(CompanyRole::Admin);
    $home = companyOf($tenant);
    $workspace = $home->workspace;
    $elsewhere = Company::factory()->create();
    $elsewhere->users()->attach($tenant->id, ['role' => CompanyRole::Viewer->value]);
    $workspace->forceFill(['suspended_at' => now(), 'suspended_reason' => 'Test'])->save();

    $this->actingAs($tenant)->withSession(['current_company_id' => $home->id])->get('/dashboard')
        ->assertStatus(423)
        ->assertInertia(fn (Assert $page) => $page->has('otherCompanies', 1)->where('otherCompanies.0.id', $elsewhere->id));

    $this->actingAs($this->admin, 'platform');
    Livewire::test(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->callAction('unsuspend')
        ->assertHasNoFormErrors();

    expect($workspace->refresh()->isSuspended())->toBeFalse();
    expect(Activity::query()->where('description', 'workspace unsuspended')->exists())->toBeTrue();
    Auth::shouldUse('web');
    $this->actingAs($tenant, 'web')->withSession(['current_company_id' => $home->id])->get('/dashboard')->assertOk();
});

it('impersonates with a reason: recorded, audited and signed in on the tenant guard', function () {
    $tenant = userWithRole(CompanyRole::Partner);
    $workspace = companyOf($tenant)->workspace;

    $this->actingAs($this->admin, 'platform');
    Livewire::test(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->callAction('impersonate', ['user_id' => $tenant->id, 'reason' => 'Support ticket 4411: reports look wrong'])
        ->assertHasNoFormErrors()
        ->assertRedirect('/home');

    $row = Impersonation::query()->sole();
    expect($row->platform_admin_id)->toBe($this->admin->id)
        ->and($row->user_id)->toBe($tenant->id)
        ->and($row->workspace_id)->toBe($workspace->id)
        ->and($row->reason)->toBe('Support ticket 4411: reports look wrong')
        ->and($row->started_at)->not->toBeNull()
        ->and($row->ended_at)->toBeNull()
        ->and(Auth::guard('web')->id())->toBe($tenant->id)
        ->and(Auth::guard('platform')->id())->toBe($this->admin->id);

    $log = Activity::query()->where('description', 'impersonation started')->sole();
    expect($log->causer?->is($this->admin))->toBeTrue()
        ->and($log->subject?->is($tenant))->toBeTrue()
        ->and($log->properties['reason'])->toBe('Support ticket 4411: reports look wrong');
});

it('refuses an impersonation without a real reason, or of someone outside the workspace', function () {
    $tenant = userWithRole(CompanyRole::Partner);
    $outsider = userWithRole(CompanyRole::Admin);
    $workspace = companyOf($tenant)->workspace;

    $this->actingAs($this->admin, 'platform');
    Livewire::test(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->callAction('impersonate', ['user_id' => $tenant->id, 'reason' => 'look'])
        ->assertHasFormErrors(['reason']);

    Livewire::test(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->callAction('impersonate', ['user_id' => $outsider->id, 'reason' => 'Support ticket 4411: reports look wrong'])
        ->assertHasFormErrors(['user_id']);

    expect(Impersonation::query()->count())->toBe(0)
        ->and(Auth::guard('web')->check())->toBeFalse();
});

it('shows the banner on every app page while impersonating, and not otherwise', function () {
    $tenant = userWithRole(CompanyRole::Admin);

    $this->actingAs($tenant)->withSession(impersonating($this->admin, $tenant))->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('impersonation.admin', 'Priya Platform')
            ->where('impersonation.user', $tenant->name)
            ->where('impersonation.reason', 'Support ticket 4411: reports look wrong'));

    session()->forget(Impersonator::SESSION);
    session()->save(); // the array driver reloads saved data on the next request
    $this->actingAs($tenant)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('impersonation', null));
});

it('stops impersonating: closes the record, signs the tenant out, keeps the platform session', function () {
    $tenant = userWithRole(CompanyRole::Admin);
    $workspace = companyOf($tenant)->workspace;
    $row = Impersonation::query()->create([
        'platform_admin_id' => $this->admin->id, 'user_id' => $tenant->id, 'workspace_id' => $workspace->id,
        'reason' => 'Support ticket 4411: reports look wrong', 'started_at' => now(),
    ]);

    $this->actingAs($this->admin, 'platform')->actingAs($tenant)
        ->withSession(impersonating($this->admin, $tenant, $row->id))
        ->post('/impersonation/stop')
        ->assertRedirect('/admin/workspaces/'.$workspace->id);

    expect($row->refresh()->ended_at)->not->toBeNull()
        ->and(Auth::guard('web')->check())->toBeFalse()
        ->and(Auth::guard('platform')->check())->toBeTrue()
        ->and(Activity::query()->where('description', 'impersonation ended')->exists())->toBeTrue();
});

it('blocks vault secrets while impersonating, even with the vault unlocked', function () {
    $company = Company::factory()->create();
    $tenant = vaultUser(CompanyRole::Admin, $company);
    $item = vaultItemFor($company, ['password' => 'demo-Imp-1!']);
    vaultGrant($tenant, $item, VaultAccessLevel::Manage);
    $session = [...vaultUnlocked(), ...impersonating($this->admin, $tenant)];

    $this->actingAs($tenant)->withSession($session)->postJson(route('vault.items.reveal', $item), ['field' => 'password'])
        ->assertForbidden()
        ->assertDontSee('demo-Imp-1!');
    $this->actingAs($tenant)->withSession($session)->postJson(route('vault.items.copy', $item), ['field' => 'password'])->assertForbidden();
    $this->actingAs($tenant)->withSession($session)->postJson(route('vault.items.totp', $item))->assertForbidden();
    $this->actingAs($tenant)->withSession($session)->postJson(route('vault.unlock'), ['code' => '000000'])->assertForbidden();

    expect(Activity::query()->where('description', 'vault access blocked while impersonating')->count())->toBe(4);

    // The same person, not impersonated, can reveal.
    session()->forget(Impersonator::SESSION);
    session()->save(); // the array driver reloads saved data on the next request
    $this->actingAs($tenant)->withSession(vaultUnlocked())->postJson(route('vault.items.reveal', $item), ['field' => 'password'])
        ->assertOk();
});

it('starts the Impersonator only with a reason', function () {
    $tenant = userWithRole(CompanyRole::Admin);

    expect(fn () => app(Impersonator::class)->start($this->admin, $tenant, 'short', request()))
        ->toThrow(InvalidArgumentException::class);
});

it('closes the impersonation record when the app signs out mid-impersonation', function () {
    $tenant = userWithRole(CompanyRole::Admin);
    $row = Impersonation::query()->create([
        'platform_admin_id' => $this->admin->id, 'user_id' => $tenant->id,
        'reason' => 'Support ticket 4411: reports look wrong', 'started_at' => now(),
    ]);

    $this->actingAs($tenant)->withSession(impersonating($this->admin, $tenant, $row->id))->post('/logout')->assertRedirect();

    expect($row->refresh()->ended_at)->not->toBeNull();
});

it('keeps a suspension history with who, when, why and when it was lifted (S6)', function () {
    $tenant = userWithRole(CompanyRole::Admin);
    $workspace = companyOf($tenant)->workspace;
    $this->actingAs($this->admin, 'platform');

    Livewire::test(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->callAction('suspend', ['reason' => 'Chargeback on the last invoice'])->assertHasNoFormErrors();
    Livewire::test(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->callAction('unsuspend')->assertHasNoFormErrors();

    $row = WorkspaceSuspension::query()->where('workspace_id', $workspace->id)->sole();
    expect($row->reason)->toBe('Chargeback on the last invoice')
        ->and($row->suspended_by)->toBe($this->admin->id)
        ->and($row->unsuspended_at)->not->toBeNull()
        ->and($row->unsuspended_by)->toBe($this->admin->id);

    Livewire::test(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->assertSee('Chargeback on the last invoice')
        ->assertSee('unsuspended');
});

it('changes a trial workspace’s plan and extends its trial, both audited (S7)', function () {
    $tenant = userWithRole(CompanyRole::Admin);
    $workspace = companyOf($tenant)->workspace;
    $workspace->forceFill(['trial_ends_at' => now()->addDays(3)])->save();
    $business = Plan::query()->where('key', 'business')->sole();
    $this->actingAs($this->admin, 'platform');

    Livewire::test(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->callAction('changePlan', ['plan_id' => $business->id, 'reason' => 'Agreed on a sales call'])->assertHasNoFormErrors();
    Livewire::test(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->callAction('extendTrial', ['days' => 10, 'reason' => 'Onboarding took longer'])->assertHasNoFormErrors();

    $fresh = $workspace->fresh();
    expect($fresh->plan_id)->toBe($business->id)
        ->and($fresh->trial_ends_at->toDateString())->toBe(now()->addDays(13)->toDateString())
        ->and(Activity::query()->where('log_name', 'platform')->whereIn('description', ['workspace plan changed', 'workspace trial extended'])->count())->toBe(2);
});
