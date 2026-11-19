<?php

declare(strict_types=1);

use App\Actions\Internal\SetUpUnboundGroup;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Income;
use App\Models\JournalLine;
use App\Models\Membership;
use App\Models\Partner;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceBilling;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::create(2026, 11, 19, 12));
});

test('coffer:unbound sets up the group: three companies, the partners’ equity, roles and logins (D145)', function () {
    $this->artisan('coffer:unbound', ['--structure-only' => true])->assertSuccessful();

    $workspace = Workspace::query()->where('name', SetUpUnboundGroup::WORKSPACE)->sole();
    expect($workspace->owner->email)->toBe('kartik@unbound.test')
        ->and(app(WorkspaceBilling::class)->for($workspace)->status)->toBe('unmanaged');

    $companies = Company::query()->where('workspace_id', $workspace->id)->get()->keyBy('short_code');
    expect($companies->keys()->all())->toBe(['SOL', 'SVC', 'INN']);

    foreach (SetUpUnboundGroup::COMPANIES as $code => $definition) {
        $partners = Partner::query()->withoutGlobalScopes()->where('company_id', $companies[$code]->id)->pluck('equity_bps', 'name')->all();
        expect($partners)->toEqual($definition['partners']);
    }

    $role = fn (string $name, string $code) => Membership::query()
        ->where('company_id', $companies[$code]->id)
        ->where('user_id', User::query()->where('email', strtolower($name).'@unbound.test')->value('id'))
        ->value('role');

    expect($role('Kartik', 'SVC'))->toBe(CompanyRole::Admin)
        ->and($role('Karan', 'INN'))->toBe(CompanyRole::Admin)
        ->and($role('Shyam', 'SOL'))->toBe(CompanyRole::Admin)
        ->and($role('Faiz', 'SVC'))->toBe(CompanyRole::Partner)
        ->and($role('Harshal', 'INN'))->toBe(CompanyRole::Partner)
        ->and($role('Khyati', 'SOL'))->toBe(CompanyRole::Partner)
        ->and($role('Faiz', 'SOL'))->toBeNull();
});

test('the sample books post balanced entries in every company', function () {
    $this->artisan('coffer:unbound')->assertSuccessful();

    foreach (Company::query()->get() as $company) {
        $lines = JournalLine::query()->withoutGlobalScopes()->where('company_id', $company->id);
        expect((int) (clone $lines)->sum('debit_paise'))->toBe((int) (clone $lines)->sum('credit_paise'))
            ->and(Income::query()->withoutGlobalScopes()->where('company_id', $company->id)->count())->toBeGreaterThan(10)
            ->and(Expense::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'pending')->count())->toBe(2);
    }

    $this->artisan('coffer:ledger-integrity')->assertSuccessful();
});

test('a new login can sign in with the printed password', function () {
    $logins = app(SetUpUnboundGroup::class)->handle('unbound.test', withSample: false);
    $faiz = collect($logins)->firstWhere('name', 'Faiz');

    $this->post(route('login'), ['email' => $faiz['email'], 'password' => $faiz['password']]);

    $this->assertAuthenticated();
});

test('coffer:unbound runs once', function () {
    $this->artisan('coffer:unbound', ['--structure-only' => true])->assertSuccessful();
    $this->artisan('coffer:unbound', ['--structure-only' => true])->assertFailed();

    expect(Workspace::query()->where('name', SetUpUnboundGroup::WORKSPACE)->count())->toBe(1);
});
