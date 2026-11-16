<?php

declare(strict_types=1);

use App\Actions\Companies\SetCompanyRole;
use App\Enums\CompanyRole;
use App\Models\AccessRequest;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Support\CurrentCompany;
use Inertia\Testing\AssertableInertia as Assert;

test('company-scoped models only see the current company’s rows', function () {
    $mine = Company::factory()->create();
    $theirs = Company::factory()->create();
    $ours = Partner::factory()->for($mine)->create();
    $foreign = Partner::factory()->for($theirs)->create();

    app(CurrentCompany::class)->set($mine, CompanyRole::Admin);

    expect(Partner::query()->pluck('id')->all())->toBe([$ours->id])
        ->and(Partner::query()->find($foreign->id))->toBeNull();
});

test('new records are stamped with the current company', function () {
    $company = Company::factory()->create();
    app(CurrentCompany::class)->set($company, CompanyRole::Admin);

    $partner = Partner::query()->create(['name' => 'Ishita Rao', 'role' => CompanyRole::Partner, 'colour' => 2, 'equity_bps' => 4000]);

    expect($partner->company_id)->toBe($company->id);
});

test('a request can’t touch another company’s record, even with its id', function () {
    $user = userWithRole(CompanyRole::Member);
    $stranger = userWithRole(CompanyRole::Member);
    $theirs = AccessRequest::query()->create([
        'company_id' => companyOf($stranger)->id,
        'user_id' => $user->id,
        'page' => 'reports',
    ]);

    $this->actingAs($user)
        ->delete(route('access-requests.destroy', $theirs))
        ->assertNotFound();

    expect($theirs->fresh()->cancelled_at)->toBeNull();
});

test('the switcher moves to a company the user belongs to', function () {
    $user = userWithRole(CompanyRole::Admin);
    $second = Company::factory()->create(['name' => 'Zeta Works']);
    app(SetCompanyRole::class)->handle($second, $user, CompanyRole::Viewer);

    $this->actingAs($user)
        ->post(route('companies.switch', $second))
        ->assertRedirect(route('home'))
        ->assertSessionHas(CurrentCompany::SESSION_KEY, $second->id);

    $this->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentCompany.id', $second->id)
            ->where('currentCompany.role', 'viewer')
            ->has('companies', 2));
});

test('the switcher refuses a company the user doesn’t belong to', function () {
    $user = userWithRole(CompanyRole::Admin);
    $foreign = Company::factory()->create();

    $this->actingAs($user)
        ->post(route('companies.switch', $foreign))
        ->assertNotFound();

    expect(session(CurrentCompany::SESSION_KEY))->not->toBe($foreign->id);
});

test('a stale company in the session falls back to one the user belongs to', function () {
    $user = userWithRole(CompanyRole::Partner);
    $foreign = Company::factory()->create();

    $this->actingAs($user)
        ->withSession([CurrentCompany::SESSION_KEY => $foreign->id])
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('currentCompany.id', companyOf($user)->id));
});

test('a user with no company is sent to the create-company wizard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('companies.create'));
    $this->actingAs($user)->get(route('home'))->assertRedirect(route('companies.create'));
});
