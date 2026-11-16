<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\CompanyType;
use App\Enums\CostType;
use App\Models\Company;
use App\Models\CompanyDraft;
use App\Models\Invite;
use App\Models\Member;
use App\Models\Partner;
use App\Models\Side;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\CompanyInvitation;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/** A verified sign-up with their workspace and no company yet. */
function founder(): User
{
    $user = User::factory()->create(['name' => 'Aarav Mehta', 'email' => 'aarav@vertexlabs.in']);
    Workspace::factory()->create(['name' => 'Vertex Group', 'owner_id' => $user->id]);

    return $user;
}

function singlePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'company' => ['type' => 'single', 'name' => 'Vertex Labs Pvt Ltd', 'short_code' => 'vl', 'opening_balance' => '2,50,000', 'currency' => 'INR'],
        'sides' => [],
        'partners' => [
            ['key' => 'owner', 'name' => 'Aarav Mehta', 'email' => null, 'role' => 'admin', 'colour' => 1, 'equity' => '60.00', 'side_key' => null],
            ['key' => 'p2', 'name' => 'Ishita Rao', 'email' => 'ishita@vertexlabs.in', 'role' => 'partner', 'colour' => 2, 'equity' => '40.00', 'side_key' => null],
        ],
        'members' => [
            ['name' => 'Rohan Desai', 'designation' => 'Senior developer', 'email' => 'rohan@vertexlabs.in', 'cost_type' => 'hourly', 'rate' => '500', 'partner_key' => 'owner'],
            ['name' => 'Dev Patel', 'designation' => 'Project manager', 'email' => null, 'cost_type' => 'fixed_monthly', 'rate' => '40,000', 'partner_key' => 'p2'],
        ],
        'invite_members' => true,
    ], $overrides);
}

function jvPayload(): array
{
    return [
        'company' => ['type' => 'jv', 'name' => 'Saarthi Portal JV', 'short_code' => 'SPJV', 'opening_balance' => '0', 'currency' => 'INR'],
        'sides' => [
            ['key' => 'vl', 'name' => 'Vertex Labs', 'equity' => '50.00'],
            ['key' => 'bf', 'name' => 'Bluefin Infotech', 'equity' => '50.00'],
        ],
        'partners' => [
            ['key' => 'owner', 'name' => 'Aarav Mehta', 'role' => 'admin', 'colour' => 1, 'equity' => '60', 'side_key' => 'vl'],
            ['key' => 'p2', 'name' => 'Ishita Rao', 'role' => 'partner', 'colour' => 2, 'equity' => '40', 'side_key' => 'vl'],
            ['key' => 'p3', 'name' => 'Kabir Shah', 'email' => 'kabir@bluefininfotech.in', 'role' => 'partner', 'colour' => 3, 'equity' => '50', 'side_key' => 'bf'],
            ['key' => 'p4', 'name' => 'Meera Iyer', 'role' => 'partner', 'colour' => 4, 'equity' => '50', 'side_key' => 'bf'],
        ],
        'members' => [],
        'invite_members' => false,
    ];
}

test('a new sign-up lands in the wizard with their name and workspace', function () {
    $this->actingAs(founder())
        ->get(route('companies.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('companies/create')
            ->where('workspace.name', 'Vertex Group')
            ->where('owner.name', 'Aarav Mehta')
            ->where('suggestedName', 'Vertex Group')
            ->where('hasCompanies', false)
            ->where('draft', null));
});

test('guests are sent to sign in', function () {
    $this->get(route('companies.create'))->assertRedirect(route('login'));
    $this->post(route('companies.store'), singlePayload())->assertRedirect(route('login'));
});

test('step 1 needs partners to total exactly 100%', function () {
    $this->actingAs(founder())
        ->post(route('companies.check'), ['step' => 1, ...singlePayload(['partners' => [1 => ['equity' => '35.00']]])])
        ->assertSessionHasErrors(['partners' => 'Equity adds up to 95.00%. Add 5.00% so it totals 100%.']);

    $this->post(route('companies.check'), ['step' => 1, ...singlePayload()])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
});

test('a JV needs sides and each side’s partners to total 100%', function () {
    $user = founder();
    $payload = jvPayload();
    $payload['sides'][1]['equity'] = '40';
    $payload['partners'][3]['equity'] = '40';

    $this->actingAs($user)
        ->post(route('companies.check'), ['step' => 1, ...$payload])
        ->assertSessionHasErrors([
            'sides' => 'Sides add up to 90.00%. Add 10.00% so they total 100%.',
            'sides.1' => 'Bluefin Infotech partners add up to 90.00%. Add 10.00% so this side totals 100%.',
        ]);

    $this->post(route('companies.check'), ['step' => 1, ...jvPayload()])->assertSessionHasNoErrors();
});

test('step 1 is validated with the design’s copy', function (array $company, string $field, string $message) {
    $user = founder();
    Company::factory()->create(['workspace_id' => $user->ownedWorkspaces()->first()->id, 'short_code' => 'TAKE']);

    $this->actingAs($user)
        ->post(route('companies.check'), ['step' => 1, ...singlePayload(['company' => $company])])
        ->assertSessionHasErrors([$field => $message]);
})->with([
    'no name' => [['name' => ''], 'company.name', 'Add a company name.'],
    'code too long' => [['short_code' => 'ABCDEFG'], 'company.short_code', 'Use 2–6 letters, like VL or SPJV.'],
    'code with digits' => [['short_code' => 'V1'], 'company.short_code', 'Use 2–6 letters, like VL or SPJV.'],
    'code taken' => [['short_code' => 'take'], 'company.short_code', 'Another company in Vertex Group uses TAKE. Pick another code.'],
    'bad balance' => [['opening_balance' => '-5'], 'company.opening_balance', 'Use an amount in rupees, like 2,50,000.'],
]);

test('launching a Single company creates everything in one go', function () {
    Notification::fake();
    $user = founder();

    $this->actingAs($user)
        ->post(route('companies.store'), singlePayload())
        ->assertRedirect(route('dashboard'));

    $company = Company::query()->sole();

    expect($company->name)->toBe('Vertex Labs Pvt Ltd')
        ->and($company->short_code)->toBe('VL')
        ->and($company->type)->toBe(CompanyType::Single)
        ->and($company->opening_balance_paise)->toBe(2_50_000_00)
        ->and($company->roleOf($user))->toBe(CompanyRole::Admin)
        ->and(session(CurrentCompany::SESSION_KEY))->toBe($company->id)
        ->and(Role::query()->where('company_id', $company->id)->count())->toBe(4);

    $partners = Partner::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('position')->get();
    expect($partners->pluck('name')->all())->toBe(['Aarav Mehta', 'Ishita Rao'])
        ->and($partners[0]->user_id)->toBe($user->id)
        ->and($partners[0]->role)->toBe(CompanyRole::Admin)
        ->and($partners->pluck('equity_bps')->all())->toBe([6000, 4000]);

    $members = Member::query()->withoutGlobalScopes()->where('company_id', $company->id)->get();
    expect($members)->toHaveCount(2)
        ->and($members->firstWhere('name', 'Dev Patel')->cost_type)->toBe(CostType::FixedMonthly)
        ->and($members->firstWhere('name', 'Dev Patel')->rate_paise)->toBe(40_000_00)
        ->and($members->firstWhere('name', 'Dev Patel')->partner_id)->toBe($partners[1]->id);

    // D23: invites for everyone with an email, linked to their record.
    $invites = Invite::query()->withoutGlobalScopes()->where('company_id', $company->id)->get();
    expect($invites->pluck('email')->sort()->values()->all())->toBe(['ishita@vertexlabs.in', 'rohan@vertexlabs.in'])
        ->and($invites->firstWhere('email', 'ishita@vertexlabs.in')->partner_id)->toBe($partners[1]->id)
        ->and($invites->firstWhere('email', 'rohan@vertexlabs.in')->role)->toBe(CompanyRole::Member);

    Notification::assertSentOnDemand(CompanyInvitation::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'ishita@vertexlabs.in');
    Notification::assertSentOnDemandTimes(CompanyInvitation::class, 2);
});

test('team members get no invite with the switch off', function () {
    Notification::fake();

    $this->actingAs(founder())->post(route('companies.store'), singlePayload(['invite_members' => false]));

    expect(Invite::query()->withoutGlobalScopes()->pluck('email')->all())->toBe(['ishita@vertexlabs.in']);
});

test('launching a JV creates its sides and puts partners on them', function () {
    $this->actingAs(founder())->post(route('companies.store'), jvPayload())->assertRedirect(route('dashboard'));

    $company = Company::query()->sole();
    $sides = Side::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('position')->get();

    expect($company->type)->toBe(CompanyType::Jv)
        ->and($sides->pluck('name')->all())->toBe(['Vertex Labs', 'Bluefin Infotech'])
        ->and(Partner::query()->withoutGlobalScopes()->where('side_id', $sides[1]->id)->pluck('name')->all())->toBe(['Kabir Shah', 'Meera Iyer']);

    $aarav = Partner::query()->withoutGlobalScopes()->where('name', 'Aarav Mehta')->sole();
    expect($aarav->effectiveEquityBps())->toBe(3000);
});

test('the first row is always the creator, as Admin', function () {
    $user = founder();
    $payload = singlePayload();
    $payload['partners'][0]['role'] = 'partner';

    $this->actingAs($user)->post(route('companies.store'), $payload);

    $owner = Partner::query()->withoutGlobalScopes()->orderBy('position')->first();
    expect($owner->role)->toBe(CompanyRole::Admin)
        ->and($owner->user_id)->toBe($user->id)
        ->and($owner->email)->toBe('aarav@vertexlabs.in');
});

test('launch is all or nothing', function () {
    $user = founder();
    // Fail late: after the company, roles and partners exist, while adding the team.
    Member::creating(fn () => throw new RuntimeException('Disk full'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)->post(route('companies.store'), singlePayload()))->toThrow(RuntimeException::class);

    expect(Company::query()->count())->toBe(0)
        ->and(Partner::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($user->companies()->count())->toBe(0);
});

test('save and exit keeps a draft that the wizard reopens', function () {
    $user = founder();
    $data = ['type' => 'single', 'name' => 'Northstar Studio', 'partners' => []];

    $this->actingAs($user)
        ->put(route('companies.draft'), ['step' => 2, 'data' => $data])
        ->assertRedirect(route('companies.create'));

    $this->get(route('companies.create'))
        ->assertInertia(fn (Assert $page) => $page->where('draft.step', 2)->where('draft.data.name', 'Northstar Studio'));

    $this->post(route('companies.store'), singlePayload());

    expect(CompanyDraft::query()->count())->toBe(0);
});

test('someone who only joined other companies gets their own workspace for a new company', function () {
    $user = userWithRole(CompanyRole::Partner);

    $this->actingAs($user)->post(route('companies.store'), singlePayload())->assertRedirect(route('dashboard'));

    $company = Company::query()->latest('id')->first();
    expect($company->workspace->owner_id)->toBe($user->id)
        ->and($company->roleOf($user))->toBe(CompanyRole::Admin);
});
