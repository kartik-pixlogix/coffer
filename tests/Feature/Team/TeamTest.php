<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\CostType;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\MemberRate;
use App\Models\Partner;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/** A company with Aarav and Ishita (spec §24) and an Admin signed in. */
function teamCompany(): object
{
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    return (object) ['company' => $books->company, 'admin' => $admin, 'aarav' => $books->aarav, 'ishita' => $books->ishita];
}

function memberOf(Partner $partner, array $attributes = []): Member
{
    return Member::factory()->create(['partner_id' => $partner->id] + $attributes);
}

function logHours(Member $member, string $date, int $centi, bool $billable = true): TimeEntry
{
    $project = Project::query()->withoutGlobalScope('company')->where('company_id', $member->company_id)->firstOrFail();

    return TimeEntry::query()->withoutGlobalScope('company')->create([
        'company_id' => $member->company_id,
        'member_id' => $member->id,
        'partner_id' => $member->partner_id,
        'project_id' => $project->id,
        'date' => $date,
        'hours_centi' => $centi,
        'billable' => $billable,
    ]);
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('the team page lists members with this month’s billable hours and earnings', function () {
    $t = teamCompany();
    $arjun = memberOf($t->aarav, ['name' => 'Arjun Nair', 'cost_type' => CostType::Hourly, 'rate_paise' => 600_00]);
    $rohan = memberOf($t->aarav, ['name' => 'Rohan Desai', 'cost_type' => CostType::FixedMonthly, 'rate_paise' => 80_000_00]);
    logHours($arjun, '2026-11-02', 800);
    logHours($arjun, '2026-11-03', 650);
    logHours($arjun, '2026-11-04', 300, billable: false);
    logHours($arjun, '2026-10-30', 800); // last month: not counted

    $this->actingAs($t->admin)
        ->get(route('team.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('team/index')
            ->where('month', '2026-11-01')
            ->has('partners', 2)
            ->has('members', 2)
            ->where('members.0.name', 'Arjun Nair')
            ->where('members.0.billableCenti', 1450)
            ->where('members.0.earningsPaise', 8_700_00)
            ->where('members.0.hasHistory', true)
            ->where('members.1.name', 'Rohan Desai')
            ->where('members.1.earningsPaise', 80_000_00)
            ->where('members.1.hasHistory', false)
            ->where('abilities.manage', true)
            ->where('abilities.editRates', true));
});

test('partners see the team but can’t manage it; viewers are denied', function () {
    $t = teamCompany();
    $partner = userWithRole(CompanyRole::Partner, $t->company);

    $this->actingAs($partner)->get(route('team.index'))
        ->assertInertia(fn (Assert $page) => $page->where('abilities.manage', false)->where('users', []));
    $this->actingAs($partner)->post(route('team.store'), [])->assertForbidden();

    $this->actingAs(userWithRole(CompanyRole::Viewer, $t->company))->get(route('team.index'))->assertForbidden();
});

test('a Member only sees their own profile', function () {
    $t = teamCompany();
    $user = userWithRole(CompanyRole::Member, $t->company);
    $own = memberOf($t->aarav, ['name' => 'Rohan Desai', 'user_id' => $user->id]);
    $other = memberOf($t->ishita, ['name' => 'Neha Joshi']);

    $this->actingAs($user)->get(route('team.index'))->assertRedirect(route('team.show', $own));
    $this->actingAs($user)->get(route('team.show', $own))
        ->assertInertia(fn (Assert $page) => $page->component('team/show')->where('member.name', 'Rohan Desai')->where('abilities.manage', false));
    $this->actingAs($user)->get(route('team.show', $other))->assertForbidden();

    // A Member with no linked team member has no profile to see.
    $this->actingAs(userWithRole(CompanyRole::Member, $t->company))->get(route('team.index'))->assertForbidden();
});

test('adding a member validates and starts their rate history', function () {
    $t = teamCompany();

    $this->actingAs($t->admin)->post(route('team.store'), ['name' => '', 'cost_type' => 'hourly', 'rate_paise' => 0])
        ->assertSessionHasErrors(['name' => 'Add their name.', 'partner_id' => 'Pick the partner they bill to.', 'rate_paise' => 'Add their rate.']);

    $this->actingAs($t->admin)->post(route('team.store'), [
        'name' => 'Kiran Bose',
        'designation' => 'Frontend developer',
        'cost_type' => 'hourly',
        'rate_paise' => 550_00,
        'partner_id' => $t->ishita->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    app(CurrentCompany::class)->set($t->company, CompanyRole::Admin);
    $kiran = Member::query()->where('name', 'Kiran Bose')->firstOrFail();

    expect($kiran->partner_id)->toBe($t->ishita->id)
        ->and($kiran->status)->toBe(MemberStatus::Active)
        ->and($kiran->rates)->toHaveCount(1)
        ->and($kiran->rates->first()->effective_from->toDateString())->toBe('2026-04-01')
        ->and($kiran->rates->first()->rate_paise)->toBe(550_00);
});

test('a partner from another company can’t be picked', function () {
    $t = teamCompany();
    $elsewhere = singleBooks()->aarav;

    $this->actingAs($t->admin)->post(route('team.store'), ['name' => 'X', 'cost_type' => 'hourly', 'rate_paise' => 100, 'partner_id' => $elsewhere->id])
        ->assertSessionHasErrors('partner_id');
});

test('a rate change needs a start date and keeps the history', function () {
    $t = teamCompany();
    $arjun = memberOf($t->aarav, ['name' => 'Arjun Nair', 'rate_paise' => 600_00]);
    $payload = ['name' => 'Arjun Nair', 'cost_type' => 'hourly', 'rate_paise' => 700_00, 'partner_id' => $t->aarav->id];

    $this->actingAs($t->admin)->put(route('team.update', $arjun), $payload)
        ->assertSessionHasErrors(['effective_from' => 'Pick the date the new rate starts.']);

    $this->actingAs($t->admin)->put(route('team.update', $arjun), $payload + ['effective_from' => '2026-11-10'])
        ->assertSessionHasNoErrors();

    $rates = MemberRate::query()->where('member_id', $arjun->id)->orderBy('effective_from')->get();

    expect($rates->map(fn (MemberRate $r) => [$r->effective_from->toDateString(), $r->rate_paise])->all())
        ->toBe([['2026-04-01', 600_00], ['2026-11-10', 700_00]])
        ->and($arjun->fresh()->rate_paise)->toBe(700_00);
});

test('changing a rate needs the Edit rates permission', function () {
    $t = teamCompany();
    $manager = userWithRole(CompanyRole::Partner, $t->company);
    Role::query()->where('company_id', $t->company->id)->where('name', 'partner')->firstOrFail()->givePermissionTo('action.team.manage');
    $arjun = memberOf($t->aarav, ['name' => 'Arjun Nair', 'rate_paise' => 600_00]);
    $base = ['name' => 'Arjun N.', 'cost_type' => 'hourly', 'partner_id' => $t->aarav->id];

    // Details can change without it…
    $this->actingAs($manager)->put(route('team.update', $arjun), $base + ['rate_paise' => 600_00])->assertSessionHasNoErrors();
    expect($arjun->fresh()->name)->toBe('Arjun N.');

    // …the rate can't.
    $this->actingAs($manager)->put(route('team.update', $arjun), $base + ['rate_paise' => 900_00, 'effective_from' => '2026-11-01'])->assertForbidden();
    expect($arjun->fresh()->rate_paise)->toBe(600_00);
});

test('members are deactivated and reactivated', function () {
    $t = teamCompany();
    $tanvi = memberOf($t->aarav, ['name' => 'Tanvi Shah']);

    $this->actingAs($t->admin)->patch(route('team.status', $tanvi))->assertRedirect();
    expect($tanvi->fresh())->status->toBe(MemberStatus::Inactive)
        ->and($tanvi->fresh()->deactivated_on->toDateString())->toBe('2026-11-15');

    $this->actingAs($t->admin)->patch(route('team.status', $tanvi))->assertRedirect();
    expect($tanvi->fresh())->status->toBe(MemberStatus::Active)->deactivated_on->toBeNull();
});

test('only members without hours or salary can be deleted', function () {
    $t = teamCompany();
    $busy = memberOf($t->aarav, ['name' => 'Busy']);
    logHours($busy, '2026-11-02', 800);
    $idle = memberOf($t->aarav, ['name' => 'Idle']);

    $this->actingAs($t->admin)->delete(route('team.destroy', $busy))
        ->assertSessionHasErrors(['member' => 'Busy has hours or salary on record, so they can only be deactivated.']);
    $this->actingAs($t->admin)->delete(route('team.destroy', $idle))->assertRedirect(route('team.index'));

    expect(Member::withTrashed()->withoutGlobalScope('company')->find($idle->id))->toBeNull()
        ->and(MemberRate::query()->where('member_id', $idle->id)->exists())->toBeFalse();
});

test('another company’s member is out of reach', function () {
    $t = teamCompany();
    $theirs = memberOf(singleBooks()->aarav, ['name' => 'Theirs']);

    $this->actingAs($t->admin)->get(route('team.show', $theirs))->assertNotFound();
    $this->actingAs($t->admin)->patch(route('team.status', $theirs))->assertNotFound();
});

test('the profile shows rate history and monthly hours for the fiscal year', function () {
    $t = teamCompany();
    $arjun = memberOf($t->aarav, ['name' => 'Arjun Nair', 'rate_paise' => 600_00]);
    logHours($arjun, '2026-10-05', 1000);

    $this->actingAs($t->admin)->get(route('team.show', $arjun))
        ->assertInertia(fn (Assert $page) => $page
            ->component('team/show')
            ->has('rates', 1)
            ->where('rates.0.current', true)
            ->has('months', 8) // April–November
            ->where('months.6.month', '2026-10-01')
            ->where('months.6.billableCenti', 1000)
            ->where('months.6.earningsPaise', 6_000_00));
});
