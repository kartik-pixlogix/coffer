<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Filament\Resources\ContactRequests\ContactRequestResource;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\Workspaces\Pages\ListWorkspaces;
use App\Filament\Resources\Workspaces\WorkspaceResource;
use App\Models\Company;
use App\Models\ContactRequest;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Workspace;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| The super-admin panel at /admin (spec §22, D85, D87): a separate guard and table, tenant
| users never get in, a platform admin is never a tenant user, and the resources render.
*/

function platformAdmin(): PlatformAdmin
{
    return PlatformAdmin::query()->create([
        'name' => 'Priya Platform',
        'email' => 'priya@coffer.test',
        'password' => 'platform-password',
    ]);
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->withoutVite();
});

it('sends guests to the panel sign-in', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->get('/admin/workspaces')->assertRedirect('/admin/login');
});

it('never lets a tenant user into /admin, even an Admin who owns a workspace', function () {
    $user = userWithRole(CompanyRole::Admin);

    $this->actingAs($user)->get('/admin')->assertRedirect('/admin/login');
    $this->actingAs($user)->get('/admin/workspaces')->assertRedirect('/admin/login');
    expect(Auth::guard('platform')->check())->toBeFalse();
});

it('refuses tenant credentials on the panel sign-in', function () {
    $user = userWithRole(CompanyRole::Admin, attributes: ['password' => 'tenant-password-123']);

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'tenant-password-123'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect(Auth::guard('platform')->check())->toBeFalse();
});

it('signs a platform admin in on the platform guard only', function () {
    platformAdmin();

    Livewire::test(Login::class)
        ->fillForm(['email' => 'priya@coffer.test', 'password' => 'platform-password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(Auth::guard('platform')->check())->toBeTrue()
        ->and(Auth::guard('web')->check())->toBeFalse();
});

it('does not make a platform admin a tenant user', function () {
    $admin = platformAdmin();
    // Even with the same email as a tenant user, the two accounts stay separate.
    User::factory()->create(['email' => 'priya@coffer.test']);

    $this->actingAs($admin, 'platform');
    // actingAs() also makes 'platform' the default guard; the app's routes use 'web'.
    Auth::shouldUse('web');

    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/home')->assertRedirect('/login');
    expect(Auth::guard('web')->check())->toBeFalse();
});

it('renders every resource for a platform admin', function () {
    $admin = platformAdmin();
    $tenant = userWithRole(CompanyRole::Admin);
    $workspace = companyOf($tenant)->workspace;
    $lead = ContactRequest::query()->create(['topic' => 'demo', 'name' => 'Asha Rao', 'email' => 'asha@example.com']);
    $plan = Plan::query()->where('key', 'growth')->sole();

    $this->actingAs($admin, 'platform');

    $this->get('/admin')->assertOk()->assertSee('MRR')->assertSee('Active trials');
    $this->get(WorkspaceResource::getUrl('index'))->assertOk()->assertSee($workspace->name);
    $this->get(WorkspaceResource::getUrl('view', ['record' => $workspace]))->assertOk()
        ->assertSee($tenant->email)
        ->assertSee('Usage')
        ->assertSee('Subscription');
    $this->get(PlanResource::getUrl('index'))->assertOk()->assertSee('Growth');
    $this->get(PlanResource::getUrl('edit', ['record' => $plan]))->assertOk();
    $this->get(ContactRequestResource::getUrl('index'))->assertOk()->assertSee('Asha Rao');
    $this->get(ContactRequestResource::getUrl('view', ['record' => $lead]))->assertOk();
});

it('lists workspaces with users and companies, across every company scope', function () {
    $this->actingAs(platformAdmin(), 'platform');
    $tenant = userWithRole(CompanyRole::Admin);
    $company = companyOf($tenant);
    userWithRole(CompanyRole::Member, Company::factory()->create(['workspace_id' => $company->workspace_id]));

    Livewire::test(ListWorkspaces::class)
        ->assertCanSeeTableRecords([$company->workspace])
        ->assertTableColumnStateSet('users_count', 2, $company->workspace)
        ->assertTableColumnStateSet('companies_count', 2, $company->workspace);
});

it('edits a plan in paise and logs the change', function () {
    $admin = platformAdmin();
    $this->actingAs($admin, 'platform');
    $plan = Plan::query()->where('key', 'starter')->sole();

    Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
        ->fillForm(['price_monthly_inr' => 1_49_900, 'max_users' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($plan->refresh()->price_monthly_inr)->toBe(1_49_900)
        ->and($plan->max_users)->toBeNull()
        ->and(Activity::query()->where('log_name', 'platform')->where('description', 'plan updated')->sole()->causer?->is($admin))->toBeTrue();
});

it('only registers read-only or platform resources (never tenant books)', function () {
    $models = collect(Filament::getPanel('admin')->getResources())->map(fn (string $resource) => $resource::getModel())->values()->all();

    expect($models)->toEqualCanonicalizing([Workspace::class, Plan::class, ContactRequest::class])
        ->and(WorkspaceResource::canCreate())->toBeFalse()
        ->and(PlanResource::canCreate())->toBeFalse()
        ->and(array_keys(WorkspaceResource::getPages()))->toBe(['index', 'view']);
});

it('creates a platform admin from the command', function () {
    $this->artisan('coffer:platform-admin', ['--email' => 'Ops@Coffer.test', '--name' => 'Ops', '--password' => 'a-long-password'])
        ->assertSuccessful();

    $admin = PlatformAdmin::query()->where('email', 'ops@coffer.test')->sole();
    expect(password_verify('a-long-password', $admin->password))->toBeTrue()
        ->and(User::query()->where('email', 'ops@coffer.test')->exists())->toBeFalse();

    $this->artisan('coffer:platform-admin', ['--email' => 'x@coffer.test', '--name' => 'X', '--password' => 'short'])
        ->assertFailed();
});

it('reads the command defaults from the environment config', function () {
    config(['platform.admin' => ['email' => 'env@coffer.test', 'name' => 'Env Admin', 'password' => 'env-password-123']]);

    $this->artisan('coffer:platform-admin')->assertSuccessful();

    expect(PlatformAdmin::query()->where('email', 'env@coffer.test')->value('name'))->toBe('Env Admin');
});
