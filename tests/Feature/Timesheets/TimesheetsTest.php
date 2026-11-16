<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\MemberStatus;
use App\Ledger\FiscalYears;
use App\Models\Member;
use App\Models\Project;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

/** A Single company with Aarav and Ishita, an Admin, Rohan (₹500/h) and the Internal project. */
function timesheetCompany(): object
{
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $rohan = Member::factory()->create(['partner_id' => $books->aarav->id, 'name' => 'Rohan Desai', 'rate_paise' => 500_00]);
    $project = Project::query()->withoutGlobalScope('company')->where('company_id', $books->company->id)->firstOrFail();

    return (object) ['company' => $books->company, 'admin' => $admin, 'aarav' => $books->aarav, 'ishita' => $books->ishita, 'rohan' => $rohan, 'project' => $project];
}

/** @return array<string, mixed> */
function dayPayload(object $t, array $overrides = []): array
{
    return $overrides + [
        'member_id' => $t->rohan->id,
        'mode' => 'day',
        'date' => '2026-11-14',
        'hours' => '7:30',
        'project_id' => $t->project->id,
        'billable' => true,
    ];
}

function entriesOf(Member $member): Collection
{
    return TimeEntry::query()->withoutGlobalScope('company')->where('member_id', $member->id)->orderBy('date')->get();
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('logging a day saves one entry with the member’s partner', function () {
    $t = timesheetCompany();

    $this->actingAs($t->admin)->post(route('timesheets.store'), dayPayload($t, ['notes' => 'Saarthi payments API']))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', '7.5 h logged for Rohan Desai');

    $entry = entriesOf($t->rohan)->sole();

    expect($entry->hours_centi)->toBe(750)
        ->and($entry->date->toDateString())->toBe('2026-11-14')
        ->and($entry->partner_id)->toBe($t->aarav->id)
        ->and($entry->billable)->toBeTrue()
        ->and($entry->notes)->toBe('Saarthi payments API')
        ->and($entry->batch)->toBeNull();
});

test('month mode spreads the total over working days and sums exactly', function (bool $saturdays, int $days) {
    $t = timesheetCompany();

    $this->actingAs($t->admin)->post(route('timesheets.store'), [
        'member_id' => $t->rohan->id,
        'mode' => 'month',
        'month' => '2026-11',
        'hours' => '57:20',
        'saturdays' => $saturdays,
        'project_id' => $t->project->id,
        'partner_id' => $t->ishita->id,
        'billable' => true,
    ])->assertSessionHasNoErrors();

    $entries = entriesOf($t->rohan);

    expect($entries)->toHaveCount($days)
        ->and($entries->sum('hours_centi'))->toBe(5733)
        ->and($entries->pluck('batch')->unique())->toHaveCount(1)
        ->and($entries->pluck('partner_id')->unique()->all())->toBe([$t->ishita->id])
        ->and($entries->every(fn (TimeEntry $e) => ! $e->date->isSunday() && ($saturdays || ! $e->date->isSaturday())))->toBeTrue();
})->with([
    'weekdays' => [false, 21],
    'with Saturdays' => [true, 25],
]);

test('hours are validated: format, 0.25–24 a day, and 24 a day over a month', function () {
    $t = timesheetCompany();

    $this->actingAs($t->admin)->post(route('timesheets.store'), dayPayload($t, ['hours' => 'lots']))
        ->assertSessionHasErrors(['hours' => 'Enter hours like 2.5 or 2:30.']);
    $this->actingAs($t->admin)->post(route('timesheets.store'), dayPayload($t, ['hours' => '0.1']))
        ->assertSessionHasErrors(['hours' => 'Hours can’t be less than 0.25.']);
    $this->actingAs($t->admin)->post(route('timesheets.store'), dayPayload($t, ['hours' => '25:00']))
        ->assertSessionHasErrors(['hours' => 'Hours can’t be more than 24 in a day.']);
    $this->actingAs($t->admin)->post(route('timesheets.store'), ['member_id' => $t->rohan->id, 'mode' => 'month', 'month' => '2026-11', 'hours' => '600', 'project_id' => $t->project->id])
        ->assertSessionHasErrors(['hours' => 'That’s more than 24 h a day over 21 working days.']);

    expect(entriesOf($t->rohan))->toBeEmpty();
});

test('more than 12 h in a day is saved with a warning', function () {
    $t = timesheetCompany();
    $this->actingAs($t->admin)->post(route('timesheets.store'), dayPayload($t, ['hours' => '8']));

    $this->actingAs($t->admin)->post(route('timesheets.store'), dayPayload($t, ['hours' => '5.5']))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', '5.5 h logged for Rohan Desai. Rohan Desai has 13.5 h on 14 Nov 2026, more than 12 h.');

    expect(entriesOf($t->rohan))->toHaveCount(2);
});

test('nothing can be logged, edited or deleted inside a closed fiscal year', function () {
    $t = timesheetCompany();
    $entry = TimeEntry::query()->create(['company_id' => $t->company->id, 'member_id' => $t->rohan->id, 'partner_id' => $t->aarav->id, 'project_id' => $t->project->id, 'date' => '2026-03-10', 'hours_centi' => 800, 'billable' => true]);
    app(FiscalYears::class)->forDate($t->company, CarbonImmutable::parse('2026-03-10'))->update(['closed_at' => now()]);

    $this->actingAs($t->admin)->post(route('timesheets.store'), dayPayload($t, ['date' => '2026-03-12']))
        ->assertSessionHasErrors(['date' => 'FY 2025-26 is closed, so nothing dated inside it can change. An Admin can reopen it in Settings.']);
    $this->actingAs($t->admin)->put(route('timesheets.update', $entry), dayPayload($t, ['mode' => null, 'date' => '2026-04-02']))
        ->assertSessionHasErrors('date');
    $this->actingAs($t->admin)->delete(route('timesheets.destroy', $entry))->assertSessionHasErrors('date');

    expect($entry->fresh()->date->toDateString())->toBe('2026-03-10');
});

test('an entry can be edited', function () {
    $t = timesheetCompany();
    $this->actingAs($t->admin)->post(route('timesheets.store'), dayPayload($t));
    $entry = entriesOf($t->rohan)->sole();

    $this->actingAs($t->admin)->put(route('timesheets.update', $entry), [
        'member_id' => $t->rohan->id,
        'date' => '2026-11-13',
        'hours' => '6',
        'project_id' => $t->project->id,
        'billable' => false,
        'notes' => 'QA',
    ])->assertSessionHasNoErrors();

    expect($entry->fresh())->hours_centi->toBe(600)->billable->toBeFalse()->notes->toBe('QA')
        ->and($entry->fresh()->date->toDateString())->toBe('2026-11-13');
});

test('a month batch can be deleted together, or one day of it', function () {
    $t = timesheetCompany();
    $this->actingAs($t->admin)->post(route('timesheets.store'), ['member_id' => $t->rohan->id, 'mode' => 'month', 'month' => '2026-11', 'hours' => '42', 'project_id' => $t->project->id]);
    $first = entriesOf($t->rohan)->first();

    $this->actingAs($t->admin)->delete(route('timesheets.destroy', $first))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Entry deleted');
    expect(entriesOf($t->rohan))->toHaveCount(20);

    $this->actingAs($t->admin)->delete(route('timesheets.destroy', entriesOf($t->rohan)->first()), ['batch' => 1])
        ->assertInertiaFlash('toast.message', '20 entries deleted');
    expect(entriesOf($t->rohan))->toBeEmpty();
});

test('entries paginate at 25 and filter by billable, project and search', function () {
    $t = timesheetCompany();
    $other = Project::query()->create(['company_id' => $t->company->id, 'name' => 'Saarthi portal']);
    $this->actingAs($t->admin)->post(route('timesheets.store'), ['member_id' => $t->rohan->id, 'mode' => 'month', 'month' => '2026-11', 'hours' => '168', 'saturdays' => true, 'project_id' => $t->project->id]);
    $this->actingAs($t->admin)->post(route('timesheets.store'), dayPayload($t, ['date' => '2026-11-15', 'hours' => '2.5', 'billable' => false, 'project_id' => $other->id, 'notes' => 'standup']));

    $this->actingAs($t->admin)->get(route('timesheets.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('timesheets/index')
            ->where('month', '2026-11-01')
            ->where('kpis.totalCenti', 17050)
            ->where('kpis.billableCenti', 16800)
            ->where('kpis.todayCenti', 250)
            ->where('kpis.todayNames', ['Rohan Desai'])
            ->where('team.0.hoursCenti', 17050)
            ->where('team.0.earningsPaise', 84_000_00)
            ->has('entries.data', 25)
            ->where('entries.total', 26)
            ->where('entries.last_page', 2));

    $this->actingAs($t->admin)->get(route('timesheets.index', ['billable' => 'non']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1));
    $this->actingAs($t->admin)->get(route('timesheets.index', ['project' => $other->id]))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1));
    $this->actingAs($t->admin)->get(route('timesheets.index', ['q' => 'stand']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 1)->where('entries.data.0.notes', 'standup'));
    $this->actingAs($t->admin)->get(route('timesheets.index', ['month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page->where('entries.total', 0)->where('kpis.totalCenti', 0));
});

test('Members see and log only their own hours', function () {
    $t = timesheetCompany();
    $user = userWithRole(CompanyRole::Member, $t->company);
    $t->rohan->update(['user_id' => $user->id]);
    $neha = Member::factory()->create(['partner_id' => $t->ishita->id, 'name' => 'Neha Joshi']);
    $this->actingAs($t->admin)->post(route('timesheets.store'), dayPayload($t, ['member_id' => $neha->id]));
    $nehas = entriesOf($neha)->sole();

    $this->actingAs($user)->post(route('timesheets.store'), dayPayload($t))->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('timesheets.store'), dayPayload($t, ['member_id' => $neha->id]))->assertForbidden();
    $this->actingAs($user)->delete(route('timesheets.destroy', $nehas))->assertForbidden();

    $this->actingAs($user)->get(route('timesheets.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.total', 1)
            ->where('entries.data.0.memberName', 'Rohan Desai')
            ->has('team', 1)
            ->has('options.members', 1)
            ->where('can.log', true));
});

test('viewers can’t open timesheets', function () {
    $t = timesheetCompany();

    $this->actingAs(userWithRole(CompanyRole::Viewer, $t->company))->get(route('timesheets.index'))->assertForbidden();
    $this->actingAs(userWithRole(CompanyRole::Viewer, $t->company))->post(route('timesheets.store'), dayPayload($t))->assertForbidden();
});

test('inactive members and other companies’ members can’t get new hours', function () {
    $t = timesheetCompany();
    $t->rohan->update(['status' => MemberStatus::Inactive]);
    $theirs = Member::factory()->create(['partner_id' => singleBooks()->aarav->id]);

    $this->actingAs($t->admin)->post(route('timesheets.store'), dayPayload($t))
        ->assertSessionHasErrors(['member_id' => 'Pick an active team member.']);
    $this->actingAs($t->admin)->post(route('timesheets.store'), dayPayload($t, ['member_id' => $theirs->id]))
        ->assertSessionHasErrors('member_id');
});

test('another company’s entry is out of reach', function () {
    $t = timesheetCompany();
    $other = timesheetCompany();
    $this->actingAs($other->admin)->post(route('timesheets.store'), dayPayload($other));
    $theirs = entriesOf($other->rohan)->sole();

    $this->actingAs($t->admin)->delete(route('timesheets.destroy', $theirs))->assertNotFound();
    expect($theirs->fresh())->not->toBeNull();
});
