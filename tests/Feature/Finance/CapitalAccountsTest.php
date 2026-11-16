<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Ledger\FiscalYears;
use App\Ledger\PayerLine;
use App\Ledger\Postings;
use App\Models\CapitalMovement;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** @return array<string, mixed> */
function capitalPayload(object $books, array $overrides = []): array
{
    return $overrides + [
        'kind' => 'contribution',
        'partner_id' => $books->aarav->id,
        'amount_paise' => 2_00_000_00,
        'date' => '2026-04-12',
        'money_account_id' => ($books->vertexAccount ?? $books->account)->id,
        'note' => 'Initial capital',
    ];
}

function movementsOf(Company $company)
{
    return CapitalMovement::query()->withoutGlobalScope('company')->where('company_id', $company->id)->get();
}

test('page and action access follow the matrix', function (CompanyRole $role, int $page, bool $manage) {
    $books = singleBooks();
    $user = userWithRole($role, $books->company);

    $this->actingAs($user)->get(route('capital-accounts.index'))->assertStatus($page);

    $response = $this->actingAs($user)->post(route('capital-accounts.store'), capitalPayload($books));
    $manage ? $response->assertSessionHasNoErrors() : $response->assertForbidden();
})->with([
    'admin' => [CompanyRole::Admin, 200, true],
    'partner' => [CompanyRole::Partner, 200, false],
    'member' => [CompanyRole::Member, 403, false],
    'viewer' => [CompanyRole::Viewer, 403, false],
]);

test('the statement comes from the books: opening + contributions − drawings + profit share = closing', function () {
    $books = jvBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    app(Postings::class)->incomeToAccount($books->company, fyDay(10), 10_00_000_00, category($books->company, 'income', 'Project'), $books->vertexAccount, $books->aarav);
    app(Postings::class)->expense($books->company, fyDay(20), category($books->company, 'expense', 'Software'), [PayerLine::company(2_00_000_00, $books->bluefinAccount)]);

    $this->actingAs($admin)->post(route('capital-accounts.store'), capitalPayload($books))
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', '₹2,00,000 contribution recorded for Aarav Mehta');
    $this->actingAs($admin)->post(route('capital-accounts.store'), capitalPayload($books, [
        'kind' => 'drawing', 'amount_paise' => 1_50_000_00, 'date' => '2026-09-30', 'note' => 'Q2 drawing',
    ]))->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', '₹1,50,000 drawing recorded for Aarav Mehta');

    // Net profit ₹8,00,000, JV pooled: 30 / 20 / 25 / 25.
    $this->actingAs($admin)->get(route('capital-accounts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('capital/index')
            ->where('fy.label', 'FY 2026-27')
            ->where('fy.closed', false)
            ->has('statement', 4)
            ->where('statement.0.name', 'Aarav Mehta')
            ->where('statement.0.sideName', 'Vertex Labs')
            ->where('statement.0.equityBps', 3000)
            ->where('statement.0.openingPaise', 0)
            ->where('statement.0.contributionsPaise', 2_00_000_00)
            ->where('statement.0.drawingsPaise', 1_50_000_00)
            ->where('statement.0.profitPaise', 2_40_000_00)
            ->where('statement.0.closingPaise', 2_90_000_00)
            ->where('statement.3.profitPaise', 2_00_000_00)
            ->where('kpis.closingPaise', 8_50_000_00)
            ->where('kpis.contributionsPaise', 2_00_000_00)
            ->where('kpis.contributionsCount', 1)
            ->where('kpis.drawingsPaise', 1_50_000_00)
            ->where('kpis.netProfitPaise', 8_00_000_00)
            ->has('contributions', 1)
            ->where('drawings.0.note', 'Q2 drawing')
            ->where('abilities.manage', true));
});

test('validation covers every field', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $other = singleBooks();

    $this->actingAs($admin)->post(route('capital-accounts.store'), [
        'kind' => 'gift', 'partner_id' => $other->aarav->id, 'amount_paise' => 0, 'date' => '15/11/2026', 'money_account_id' => $other->account->id,
        'note' => str_repeat('x', 501),
    ])->assertSessionHasErrors(['kind', 'partner_id' => 'Pick an active partner.', 'amount_paise' => 'Enter an amount above ₹0.', 'date' => 'Pick a date.', 'money_account_id' => 'Pick an active money account.', 'note']);

    expect(movementsOf($books->company))->toHaveCount(0);
});

test('deleting reverses the entry; a closed year blocks both', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $partner = userWithRole(CompanyRole::Partner, $books->company);

    $this->actingAs($admin)->post(route('capital-accounts.store'), capitalPayload($books, ['date' => '2025-12-01']));
    $movement = movementsOf($books->company)->sole();

    $this->actingAs($partner)->delete(route('capital-accounts.destroy', $movement))->assertForbidden();

    app(FiscalYears::class)->forDate($books->company, CarbonImmutable::parse('2025-12-01'))->update(['closed_at' => now()]);
    $locked = 'FY 2025-26 is closed, so nothing dated inside it can change. An Admin can reopen it in Settings.';

    $this->actingAs($admin)->post(route('capital-accounts.store'), capitalPayload($books, ['date' => '2026-01-10']))->assertSessionHasErrors(['date' => $locked]);
    $this->actingAs($admin)->delete(route('capital-accounts.destroy', $movement))->assertSessionHasErrors(['date' => $locked]);
    $this->actingAs($admin)->get(route('capital-accounts.index', ['fy' => 2025]))
        ->assertInertia(fn (Assert $page) => $page->where('fy.closed', true)->where('abilities.manage', false));

    app(FiscalYears::class)->forDate($books->company, CarbonImmutable::parse('2025-12-01'))->update(['closed_at' => null]);
    $this->actingAs($admin)->delete(route('capital-accounts.destroy', $movement))
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Contribution deleted');

    expect($movement->refresh()->trashed())->toBeTrue()
        ->and(journalLines($movement->journal_entry_id))->toHaveCount(2);
});

test('another company’s records are not found', function () {
    $books = singleBooks();
    $other = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $theirAdmin = userWithRole(CompanyRole::Admin, $other->company);

    $this->actingAs($theirAdmin)->post(route('capital-accounts.store'), capitalPayload($other));
    $theirs = movementsOf($other->company)->sole();

    $this->actingAs($admin)->delete(route('capital-accounts.destroy', $theirs))->assertNotFound();
    $this->actingAs($admin)->get(route('capital-accounts.index'))
        ->assertInertia(fn (Assert $page) => $page->has('contributions', 0)->where('kpis.contributionsPaise', 0));
});

test('export downloads the statement as CSV', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $this->actingAs($admin)->post(route('capital-accounts.store'), capitalPayload($books));

    $csv = $this->actingAs($admin)->get(route('capital-accounts.export'))
        ->assertOk()->assertDownload('capital-statements-fy-2026-27.csv')->streamedContent();

    expect($csv)->toContain('Partner,Side,"Equity (%)"')
        ->and($csv)->toContain('"Aarav Mehta",,60.00,0.00,200000.00,0.00,0.00,200000.00');
});
