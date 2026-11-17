<?php

declare(strict_types=1);

use App\Actions\Companies\LaunchCompany;
use App\Actions\Companies\SetCompanyRole;
use App\Actions\Invites\AcceptInvite;
use App\Actions\Invites\IssueInvite;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\CompanyRole;
use App\Enums\PlanLimit;
use App\Exceptions\PlanLimitReached;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\PlanUsage;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Soft limits and read-only (spec §20, D81, D82): past a limit, creating is refused with
 * the `plan_limit` error the shell turns into the Upgrade modal (402 for JSON); nothing
 * that exists is touched. An unpaid workspace turns read-only after the grace days.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** A workspace on the Starter plan (1 company, 3 users, 10 vault items) with one company. */
function starterWorkspace(): object
{
    $owner = User::factory()->create(['name' => 'Aarav Mehta']);
    $workspace = app(CreateWorkspace::class)->handle($owner, 'Vertex Group');
    $workspace->forceFill(['plan_id' => Plan::query()->where('key', 'starter')->value('id')])->save();
    $company = Company::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Vertex Labs Pvt Ltd']);
    app(SetCompanyRole::class)->handle($company, $owner, CompanyRole::Admin);

    return (object) ['workspace' => $workspace->refresh(), 'company' => $company, 'owner' => $owner->refresh()];
}

test('a second company on Starter is refused, and nothing is created', function () {
    $s = starterWorkspace();

    expect(fn () => app(LaunchCompany::class)->handle($s->workspace, $s->owner, ['company' => ['name' => 'Northstar', 'short_code' => 'NS', 'type' => 'single'], 'partners' => []]))
        ->toThrow(PlanLimitReached::class, 'You’ve used all 1 company on Starter. Upgrade to add more — nothing you already have is affected.');
    expect(Company::query()->where('workspace_id', $s->workspace->id)->count())->toBe(1);
});

test('inviting past the user limit answers with the plan_limit error the Upgrade modal listens for', function () {
    $s = starterWorkspace();
    userWithRole(CompanyRole::Partner, $s->company);

    // 2 people + 1 waiting invite = the 3 Starter allows.
    $this->actingAs($s->owner)->post(route('settings.invites.store'), ['emails' => 'one@example.test', 'role' => 'viewer', 'days' => 7])->assertSessionHasNoErrors();
    $this->actingAs($s->owner)->post(route('settings.invites.store'), ['emails' => 'two@example.test', 'role' => 'viewer', 'days' => 7])
        ->assertSessionHasErrors([PlanLimitReached::KEY => 'You’ve used all 3 users on Starter. Upgrade to add more — nothing you already have is affected.']);
    $this->actingAs($s->owner)->post(route('settings.invites.link'), ['role' => 'viewer', 'days' => 7])->assertSessionHasErrors(PlanLimitReached::KEY);
});

test('a share link stops at the user limit; someone already in the workspace still gets in', function () {
    $s = starterWorkspace();
    $second = Company::factory()->create(['workspace_id' => $s->workspace->id]);
    $s->workspace->plan->forceFill(['max_companies' => 2])->save();
    $partner = userWithRole(CompanyRole::Partner, $s->company);
    userWithRole(CompanyRole::Viewer, $s->company);
    $s->workspace->plan->forceFill(['max_users' => 10])->save();
    $link = app(IssueInvite::class)->link($second, $s->owner, CompanyRole::Viewer);
    $s->workspace->plan->forceFill(['max_users' => 3])->save();

    $stranger = User::factory()->create();
    expect(fn () => app(AcceptInvite::class)->handle($link, $stranger))->toThrow(ValidationException::class);

    app(AcceptInvite::class)->handle($link, $partner);
    expect($partner->belongsToCompany($second))->toBeTrue();
});

test('adding a vault item past the limit opens the upgrade instead; editing never does', function () {
    $s = starterWorkspace();
    $vault = vaultIn($s->company);
    $admin = vaultUser(CompanyRole::Admin, $s->company);
    $s->workspace->plan->forceFill(['max_vault_items' => 1])->save();
    $item = vaultItemFor($s->company, ['password' => 'demo-pass-1'], [], $vault, $admin);

    $payload = ['vault_id' => $vault->id, 'type' => 'login', 'name' => 'GitHub', 'fields' => ['username' => 'x'], 'secrets' => ['password' => 'demo-Pass-2026!'], 'strength' => 3];

    $this->actingAs($admin)->withSession(vaultUnlocked())->post(route('vault.items.store'), $payload)
        ->assertSessionHasErrors([PlanLimitReached::KEY => 'You’ve used all 1 vault item on Starter. Upgrade to add more — nothing you already have is affected.']);

    // The same refusal as JSON is a 402 carrying the key.
    $this->actingAs($admin)->withSession(vaultUnlocked())->postJson(route('vault.items.store'), $payload)
        ->assertStatus(402)
        ->assertJsonPath('errors.'.PlanLimitReached::KEY.'.0', 'You’ve used all 1 vault item on Starter. Upgrade to add more — nothing you already have is affected.')
        ->assertJsonPath('limit', 'vault_items')
        ->assertJsonPath('max', 1);

    $this->actingAs($admin)->withSession(vaultUnlocked())->put(route('vault.items.update', $item), [...$payload, 'name' => 'Renamed'])->assertSessionDoesntHaveErrors(PlanLimitReached::KEY);
});

test('pages that add something warn near the limit', function () {
    $s = starterWorkspace();
    userWithRole(CompanyRole::Partner, $s->company);
    $s->workspace->plan->forceFill(['max_users' => 2])->save();

    $this->actingAs($s->owner)->get(route('settings.members'))
        ->assertInertia(fn (Assert $page) => $page->where('planLimit', ['key' => 'users', 'label' => 'Users', 'noun' => 'users', 'used' => 2, 'limit' => 2, 'full' => true]));
    $this->actingAs($s->owner)->get(route('settings.company'))
        ->assertInertia(fn (Assert $page) => $page->where('planLimit', null));

    $s->workspace->plan->forceFill(['max_users' => 50])->save();
    $this->actingAs($s->owner)->get(route('settings.members'))
        ->assertInertia(fn (Assert $page) => $page->where('planLimit', null));
});

test('a workspace with no plan set (internal) has no limits', function () {
    $workspace = Workspace::factory()->create();

    foreach (PlanLimit::cases() as $limit) {
        app(PlanUsage::class)->ensureCanAdd($workspace, $limit, 10_000);
    }

    expect(true)->toBeTrue();
});

test('an ended trial is read-only after the grace days: views work, changes open the upgrade, billing stays open', function () {
    $s = starterWorkspace();
    $s->workspace->forceFill(['trial_ends_at' => now()->subDays(8)])->save();

    $this->actingAs($s->owner)->get(route('settings.projects'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('billingStatus.status', 'expired')->where('billingStatus.readOnly', true));
    $this->actingAs($s->owner)->post(route('settings.projects.store'), ['name' => 'New project'])
        ->assertSessionHasErrors([PlanLimitReached::KEY => 'Vertex Group’s trial has ended, so it’s read-only for now. Everything stays visible; choose a plan to make changes again.']);
    $this->actingAs($s->owner)->putJson(route('billing.details'), ['email' => 'accounts@example.test'])->assertStatus(302);
    $this->actingAs($s->owner)->postJson(route('settings.projects.store'), ['name' => 'New project'])->assertStatus(402);

    // Inside the grace days it still works, with the banner.
    $s->workspace->forceFill(['trial_ends_at' => now()->subDays(3)])->save();
    $this->actingAs($s->owner)->post(route('settings.projects.store'), ['name' => 'New project'])->assertSessionDoesntHaveErrors(PlanLimitReached::KEY);
    $this->actingAs($s->owner)->get(route('settings.projects'))
        ->assertInertia(fn (Assert $page) => $page->where('billingStatus.status', 'expired')->where('billingStatus.readOnly', false)->where('billingStatus.graceEndsAt', '19 Nov 2026'));
});

test('the shell knows the trial days left and who can fix billing', function () {
    $s = starterWorkspace();
    $partner = userWithRole(CompanyRole::Partner, $s->company);

    $this->actingAs($partner)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('billingStatus', [
            'status' => 'trial', 'plan' => 'Starter', 'trialDaysLeft' => 14, 'readOnly' => false, 'graceEndsAt' => null, 'canManage' => false, 'owner' => 'Aarav Mehta',
        ]));
    $this->actingAs($s->owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('billingStatus.canManage', true));
});
