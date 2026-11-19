<?php

declare(strict_types=1);

use App\Actions\Companies\SetCompanyRole;
use App\Actions\Workspaces\CreateWorkspace;
use App\Enums\CompanyRole;
use App\Enums\PlanLimit;
use App\Exceptions\PlanLimitReached;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Invite;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\PlanUsage;
use App\Services\Privacy\CompanyTables;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Settings › Company › Archive or delete (V4, D139–D141): Admin or workspace owner only,
| type-to-confirm; archived companies leave the switcher and the plan count and are read-only;
| deleted ones are soft-deleted, kept until the D98 grace period ends, then erased.
*/

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    CarbonImmutable::setTestNow('2026-11-17 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** Vertex Group (owner Aarav, an Admin of both) with Vertex Labs and Northstar; the plan allows two companies. */
function twoCompanies(): object
{
    $owner = User::factory()->create(['name' => 'Aarav Mehta', 'password' => 'owner-password-123']);
    $workspace = app(CreateWorkspace::class)->handle($owner, 'Vertex Group');
    $workspace->forceFill(['plan_id' => Plan::query()->where('key', 'starter')->value('id')])->save();
    $workspace->plan->forceFill(['max_companies' => 2])->save();

    $labs = singleBooks()->company;
    $labs->forceFill(['workspace_id' => $workspace->id, 'name' => 'Vertex Labs'])->save();
    $north = Company::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Northstar', 'short_code' => 'NS']);

    foreach ([$labs, $north] as $c) {
        app(SetCompanyRole::class)->handle($c, $owner, CompanyRole::Admin);
    }

    return (object) ['workspace' => $workspace->refresh(), 'labs' => $labs->refresh(), 'north' => $north->refresh(), 'owner' => $owner->refresh()];
}

function inCompany(object $t, Company $company): array
{
    return [CurrentCompany::SESSION_KEY => $company->id];
}

test('only an Admin or the workspace owner can archive or delete, with the exact name (and the password to delete)', function (CompanyRole $role) {
    $t = twoCompanies();
    $user = userWithRole($role, $t->labs, ['password' => 'their-password-123']);
    app(SetCompanyRole::class)->handle($t->north, $user, CompanyRole::Viewer);

    $this->actingAs($user)->withSession(inCompany($t, $t->labs))->post(route('settings.company.archive'), ['confirmation' => 'Vertex Labs'])->assertForbidden();
    $this->actingAs($user)->withSession(inCompany($t, $t->labs))->post(route('settings.company.delete'), ['confirmation' => 'Vertex Labs', 'password' => 'their-password-123'])->assertForbidden();
    expect($t->labs->refresh()->archived_at)->toBeNull();
})->with([
    'partner' => [CompanyRole::Partner],
    'member' => [CompanyRole::Member],
    'viewer' => [CompanyRole::Viewer],
]);

test('type-to-confirm and the password are checked', function () {
    $t = twoCompanies();
    $session = inCompany($t, $t->labs);

    $this->actingAs($t->owner)->withSession($session)->post(route('settings.company.archive'), ['confirmation' => 'vertex labs'])
        ->assertSessionHasErrors(['confirmation' => 'Type the company name exactly to confirm.']);
    $this->actingAs($t->owner)->withSession($session)->post(route('settings.company.delete'), ['confirmation' => 'Vertex Labs', 'password' => 'wrong'])
        ->assertSessionHasErrors('password');
    $this->actingAs($t->owner)->withSession($session)->post(route('settings.company.delete'), ['confirmation' => 'Vertex', 'password' => 'owner-password-123'])
        ->assertSessionHasErrors('confirmation');

    expect($t->labs->refresh()->archived_at)->toBeNull()->and($t->labs->trashed())->toBeFalse();
});

test('archiving frees the plan slot, leaves the switcher and makes the company read-only until restored', function () {
    $t = twoCompanies();
    $usage = app(PlanUsage::class);
    expect($usage->hasRoomFor($t->workspace, PlanLimit::Companies))->toBeFalse();

    $this->actingAs($t->owner)->withSession(inCompany($t, $t->labs))->post(route('settings.company.archive'), ['confirmation' => 'Vertex Labs'])
        ->assertRedirect(route('home'))
        ->assertInertiaFlash('toast.message', 'Vertex Labs archived. It’s read-only and doesn’t count toward the plan; restore it from Settings › Company.');

    $t->labs->refresh();
    expect($t->labs->archived_at)->not->toBeNull()
        ->and($t->labs->archived_by)->toBe($t->owner->id)
        ->and($usage->used($t->workspace, PlanLimit::Companies))->toBe(1)
        ->and($usage->hasRoomFor($t->workspace, PlanLimit::Companies))->toBeTrue();

    // The person lands in Northstar; Vertex Labs is gone from the switcher.
    $this->actingAs($t->owner)->get(route('settings.company'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentCompany.name', 'Northstar')
            ->has('companies', 1)
            ->where('companies.0.name', 'Northstar')
            ->where('lifecycle.others.0.name', 'Vertex Labs')
            ->where('lifecycle.others.0.archivedAt', '2026-11-17')
            ->where('lifecycle.others.0.canOpen', true));

    // Opened again, it shows read-only (still listed while current) and refuses changes.
    $this->actingAs($t->owner)->withSession(inCompany($t, $t->labs))->get(route('dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('currentCompany.archived', true)->has('companies', 2));
    $this->actingAs($t->owner)->withSession(inCompany($t, $t->labs))->put(route('settings.company.update'), ['name' => 'Renamed'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Vertex Labs is archived, so it’s read-only. An Admin can restore it in Settings › Company.');
    expect($t->labs->refresh()->name)->toBe('Vertex Labs');

    // Restored: back in the count, editable again.
    $this->actingAs($t->owner)->post(route('companies.restore', $t->labs))->assertInertiaFlash('toast.message', 'Vertex Labs restored. It’s back in the company switcher.');
    expect($t->labs->refresh()->archived_at)->toBeNull()
        ->and($usage->used($t->workspace, PlanLimit::Companies))->toBe(2);

    $logs = DB::table('activity_log')->where('subject_type', $t->labs->getMorphClass())->where('subject_id', $t->labs->id)->pluck('description')->all();
    expect($logs)->toContain('archived the company')->toContain('restored the company');
});

test('restoring needs room on the plan', function () {
    $t = twoCompanies();
    $t->labs->forceFill(['archived_at' => now()])->save();
    Company::factory()->create(['workspace_id' => $t->workspace->id, 'name' => 'Third', 'short_code' => 'TH']);

    $this->actingAs($t->owner)->withSession(inCompany($t, $t->north))->post(route('companies.restore', $t->labs))
        ->assertSessionHasErrors([PlanLimitReached::KEY => 'You’ve used all 2 companies on Starter. Upgrade to add more — nothing you already have is affected.']);
    expect($t->labs->refresh()->archived_at)->not->toBeNull();
});

test('a Member whose only company is archived still sees it, read-only', function () {
    $t = twoCompanies();
    $member = userWithRole(CompanyRole::Member, $t->labs);
    $t->labs->forceFill(['archived_at' => now()])->save();

    $this->actingAs($member)->get(route('home'))->assertRedirect();
    $this->actingAs($member)->get(route('timesheets.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('currentCompany.name', 'Vertex Labs')->where('currentCompany.archived', true));
    // Other people's restore or delete are out of reach.
    $this->actingAs($member)->post(route('companies.restore', $t->labs))->assertForbidden();
});

test('deleting soft-deletes the company, can be kept during the grace period, and is erased after it', function () {
    $t = twoCompanies();
    $other = singleBooks();
    $otherAccounts = DB::table('accounts')->where('company_id', $other->company->id)->count();
    $partner = userWithRole(CompanyRole::Partner, $t->labs);
    $invite = Invite::factory()->create(['company_id' => $t->labs->id, 'invited_by' => $t->owner->id]);
    Storage::disk('local')->put("companies/{$t->labs->id}/expenses/receipt.pdf", 'PDF');

    $this->actingAs($t->owner)->withSession(inCompany($t, $t->labs))->post(route('settings.company.delete'), ['confirmation' => 'Vertex Labs', 'password' => 'owner-password-123'])
        ->assertRedirect(route('home'))
        ->assertInertiaFlash('toast.message', 'Vertex Labs will be erased on 17 Dec 2026. Until then you can keep it from Settings › Company.');

    $labs = Company::query()->withTrashed()->findOrFail($t->labs->id);
    expect($labs->trashed())->toBeTrue()
        ->and($labs->deletion_scheduled_for?->toDateString())->toBe('2026-12-17')
        ->and(app(PlanUsage::class)->used($t->workspace, PlanLimit::Companies))->toBe(1)
        ->and($invite->refresh()->isUsable())->toBeFalse();

    // Everyone loses access at once: the partner had only this company.
    $this->actingAs($partner)->get(route('dashboard'))->assertRedirect(route('companies.create'));
    $this->actingAs($partner)->post(route('companies.keep', $labs))->assertForbidden();

    // The owner sees it on Settings › Company in Northstar and keeps it.
    $this->actingAs($t->owner)->get(route('settings.company'))
        ->assertInertia(fn (Assert $page) => $page->where('currentCompany.name', 'Northstar')
            ->where('lifecycle.others.0.name', 'Vertex Labs')
            ->where('lifecycle.others.0.deletionScheduledFor', '2026-12-17')
            ->where('lifecycle.others.0.canOpen', false));
    $this->actingAs($t->owner)->post(route('companies.keep', $labs))->assertInertiaFlash('toast.message', 'Vertex Labs stays. Nothing was deleted.');
    expect($labs->refresh()->trashed())->toBeFalse()->and($labs->deletion_scheduled_for)->toBeNull();

    // Deleted again and left past the grace period: every row of it goes, nothing else.
    $this->actingAs($t->owner)->withSession(inCompany($t, $t->labs))->post(route('settings.company.delete'), ['confirmation' => 'Vertex Labs', 'password' => 'owner-password-123'])->assertSessionHasNoErrors();
    CarbonImmutable::setTestNow('2026-12-16 23:00:00');
    $this->artisan('coffer:privacy-purge')->assertSuccessful();
    expect(DB::table('companies')->where('id', $labs->id)->exists())->toBeTrue();

    CarbonImmutable::setTestNow('2026-12-17 04:30:00');
    $this->artisan('coffer:privacy-purge')->assertSuccessful();

    expect(DB::table('companies')->where('id', $labs->id)->exists())->toBeFalse();

    foreach (app(CompanyTables::class)->scoped() as $table) {
        expect(DB::table($table)->where('company_id', $labs->id)->count())->toBe(0, "{$table} kept rows of an erased company");
    }

    Storage::disk('local')->assertMissing("companies/{$labs->id}/expenses/receipt.pdf");
    expect(Workspace::query()->whereKey($t->workspace->id)->exists())->toBeTrue()
        ->and(Company::query()->whereKey($t->north->id)->exists())->toBeTrue()
        ->and(DB::table('accounts')->where('company_id', $other->company->id)->count())->toBe($otherAccounts)
        ->and($partner->refresh()->exists)->toBeTrue();
    $this->artisan('coffer:ledger-integrity')->assertSuccessful();
});

test('a kept company comes back archived when the plan has no room', function () {
    $t = twoCompanies();
    $this->actingAs($t->owner)->withSession(inCompany($t, $t->labs))->post(route('settings.company.delete'), ['confirmation' => 'Vertex Labs', 'password' => 'owner-password-123'])->assertSessionHasNoErrors();
    Company::factory()->create(['workspace_id' => $t->workspace->id, 'name' => 'Third', 'short_code' => 'TH']);

    $labs = Company::query()->withTrashed()->findOrFail($t->labs->id);
    $this->actingAs($t->owner)->post(route('companies.keep', $labs))
        ->assertInertiaFlash('toast.message', 'Vertex Labs stays, archived: the plan has no room for another company. Upgrade, then restore it.');

    expect($labs->refresh()->trashed())->toBeFalse()->and($labs->archived_at)->not->toBeNull();
});

test('a person’s only company can’t be deleted; the workspace is deleted instead', function () {
    $owner = User::factory()->create(['password' => 'owner-password-123']);
    $workspace = app(CreateWorkspace::class)->handle($owner, 'Solo');
    $company = Company::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Solo Studio']);
    app(SetCompanyRole::class)->handle($company, $owner, CompanyRole::Admin);

    $this->actingAs($owner)->get(route('settings.company'))
        ->assertInertia(fn (Assert $page) => $page->where('lifecycle.onlyCompany', true)->where('lifecycle.canManage', true)->where('lifecycle.owner', true));
    $this->actingAs($owner)->post(route('settings.company.delete'), ['confirmation' => 'Solo Studio', 'password' => 'owner-password-123'])
        ->assertSessionHasErrors(['confirmation' => 'Solo Studio is your only company. To erase it, delete the workspace in Profile › Your data.']);

    expect($company->refresh()->trashed())->toBeFalse();
});

test('a deleted company’s short code stays taken until it is erased', function () {
    $t = twoCompanies();
    $t->workspace->plan->forceFill(['max_companies' => 5])->save();
    $code = $t->labs->short_code;
    $this->actingAs($t->owner)->withSession(inCompany($t, $t->labs))->post(route('settings.company.delete'), ['confirmation' => 'Vertex Labs', 'password' => 'owner-password-123'])->assertSessionHasNoErrors();

    $this->actingAs($t->owner)->withSession(inCompany($t, $t->north))->put(route('settings.company.update'), ['short_code' => $code])
        ->assertSessionHasErrors(['short_code' => "Another company in this workspace uses {$code}. Pick another code."]);
});

test('salaries and reminders skip archived companies', function () {
    $t = twoCompanies();
    $t->labs->forceFill(['archived_at' => now()])->save();

    $this->artisan('coffer:salaries', ['--month' => '2026-10'])->assertSuccessful()
        ->doesntExpectOutputToContain('Vertex Labs');
    expect(Expense::query()->withoutGlobalScopes()->where('company_id', $t->labs->id)->count())->toBe(0);
});
