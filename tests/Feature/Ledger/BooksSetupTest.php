<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\JournalType;
use App\Ledger\ChartOfAccounts;
use App\Ledger\OpeningBalance;
use App\Ledger\Postings;
use App\Models\Account;
use App\Models\Category;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\Side;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

test('launching a company sets up its books and posts the opening balance', function () {
    $user = User::factory()->create(['name' => 'Aarav Mehta']);
    Workspace::factory()->create(['owner_id' => $user->id]);

    $this->actingAs($user)->post(route('companies.store'), [
        'company' => ['type' => 'jv', 'name' => 'Saarthi Portal JV', 'short_code' => 'SPJV', 'opening_balance' => '1,00,000', 'currency' => 'INR'],
        'sides' => [['key' => 'a', 'name' => 'Vertex Labs', 'equity' => '50'], ['key' => 'b', 'name' => 'Bluefin Infotech', 'equity' => '50']],
        'partners' => [
            ['key' => 'owner', 'name' => 'Aarav Mehta', 'role' => 'admin', 'colour' => 1, 'equity' => '100', 'side_key' => 'a'],
            ['key' => 'k', 'name' => 'Kabir Shah', 'role' => 'partner', 'colour' => 3, 'equity' => '100', 'side_key' => 'b'],
        ],
        'members' => [],
        'invite_members' => false,
    ])->assertRedirect(route('dashboard'));

    $company = Company::query()->sole();

    expect(MoneyAccount::query()->withoutGlobalScopes()->where('company_id', $company->id)->orderBy('position')->pluck('name')->all())
        ->toBe(['Company account', 'Vertex Labs account', 'Bluefin Infotech account'])
        ->and(Category::query()->withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(10)
        ->and(app(OpeningBalance::class)->current($company)?->type)->toBe(JournalType::Opening)
        ->and(Account::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('role', 'capital')->count())->toBe(2);
});

test('a company with no opening balance gets no opening entry', function () {
    $books = singleBooks();

    expect(app(OpeningBalance::class)->post($books->company))->toBeNull()
        ->and(JournalEntry::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('adding a side gives it an account; a side whose account has entries stays', function () {
    $books = jvBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->put(route('settings.sides.update'), ['sides' => [
        ['id' => $books->vertex->id, 'name' => 'Vertex Labs', 'equity' => '40'],
        ['id' => $books->bluefin->id, 'name' => 'Bluefin', 'equity' => '30'],
        ['id' => null, 'name' => 'Kriya', 'equity' => '30'],
    ]])->assertSessionHasNoErrors();

    $kriya = Side::query()->withoutGlobalScopes()->where('name', 'Kriya')->sole();
    $kriyaAccount = app(ChartOfAccounts::class)->sideAccount($kriya);

    expect($kriyaAccount->name)->toBe('Kriya account')
        ->and(app(ChartOfAccounts::class)->sideAccount($books->bluefin->fresh())->name)->toBe('Bluefin account');

    app(Postings::class)->incomeToAccount($books->company, fyDay(), 1_000_00, category($books->company, 'income', 'Other'), $kriyaAccount);

    $this->put(route('settings.sides.update'), ['sides' => [
        ['id' => $books->vertex->id, 'name' => 'Vertex Labs', 'equity' => '50'],
        ['id' => $books->bluefin->id, 'name' => 'Bluefin', 'equity' => '50'],
    ]])->assertSessionHasErrors(['sides' => 'Kriya’s account has entries, so the side can’t be removed.']);
});

test('a partner with entries in the books is archived, one without goes with their empty accounts', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $kabir = Partner::factory()->for($books->company)->create(['name' => 'Kabir Shah', 'equity_bps' => 0, 'position' => 2]);
    $meera = Partner::factory()->for($books->company)->create(['name' => 'Meera Iyer', 'equity_bps' => 0, 'position' => 3]);
    $chart = app(ChartOfAccounts::class);
    $chart->capital($meera);
    app(Postings::class)->incomeToPartner($books->company, fyDay(), 5_000_00, category($books->company, 'income', 'Other'), $kabir);

    $this->actingAs($admin)->delete(route('settings.partners.destroy', $kabir))->assertSessionHasNoErrors();
    $this->delete(route('settings.partners.destroy', $meera))->assertSessionHasNoErrors();

    expect($kabir->fresh()->archived_at)->not->toBeNull()
        ->and(Partner::query()->withoutGlobalScopes()->find($meera->id))->toBeNull()
        ->and(Account::query()->withoutGlobalScopes()->where('partner_id', $meera->id)->exists())->toBeFalse();
});

test('partner cards show revenue brought in and profit due this year', function () {
    $this->travelTo(fyDay(40));
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $p = app(Postings::class);
    $p->incomeToAccount($books->company, fyDay(5), 1_00_000_00, category($books->company, 'income', 'Project'), $books->account, $books->aarav);

    $this->actingAs($admin)
        ->get(route('settings.partners'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('partners.0.stats.revenuePaise', 1_00_000_00)
            ->where('partners.0.stats.profitDuePaise', 1_00_000_00)
            ->where('partners.1.stats.profitDuePaise', 0));
});

test('the sync command sets up books for companies made before the ledger', function () {
    $company = Company::factory()->create(['opening_balance_paise' => 50_000_00]);
    Partner::factory()->for($company)->create(['equity_bps' => 10000]);

    $this->artisan('coffer:ledger-sync')->assertSuccessful();
    $this->artisan('coffer:ledger-sync')->assertSuccessful();

    expect(JournalEntry::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('type', 'opening')->count())->toBe(1)
        ->and(MoneyAccount::query()->withoutGlobalScopes()->where('company_id', $company->id)->count())->toBe(1);
});
