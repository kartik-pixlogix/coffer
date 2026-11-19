<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\JournalType;
use App\Ledger\FiscalYears;
use App\Ledger\IntegrityCheck;
use App\Ledger\OpeningBalance;
use App\Ledger\PayerLine;
use App\Ledger\Period;
use App\Ledger\Postings;
use App\Ledger\Reports;
use App\Ledger\YearEnd;
use App\Models\CapitalMovement;
use App\Models\Company;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Income;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Project;
use App\Models\SettlementTransfer;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** @return array<string, mixed> */
function companyForm(Company $company, array $overrides = []): array
{
    return $overrides + [
        'name' => $company->name,
        'legal_name' => 'Vertex Labs Private Limited',
        'gstin' => '27ABCDE1234F1Z5',
        'address' => 'Office 402, Baner Road, Pune 411045',
        'short_code' => 'VLX',
        'opening_balance' => '0',
        'currency' => 'INR',
        'attribution_mode' => $company->attribution_mode->value,
        'fy_start_month' => 4,
        'working_days' => 31,
        'hours_per_day' => '8',
    ];
}

/** A year (FY 2025-26) with income, an expense and capital, plus a year in progress. */
function yearEndBooks(): object
{
    $books = singleBooks(openingPaise: 1_00_000_00);
    $p = app(Postings::class);
    $start = CarbonImmutable::create(2025, 4, 1);
    app(OpeningBalance::class)->post($books->company, $start);
    $p->incomeToAccount($books->company, $start->addDays(30), 5_00_000_00, category($books->company, 'income', 'Project'), $books->account, $books->aarav);
    $p->expense($books->company, $start->addDays(60), category($books->company, 'expense', 'Software'), [PayerLine::company(1_00_000_00, $books->account)]);
    $p->contribution($books->company, $start->addDays(90), $books->ishita, 50_000_00, $books->account);
    $p->incomeToAccount($books->company, fyDay(10), 2_00_000_00, category($books->company, 'income', 'Project'), $books->account, $books->ishita);

    return $books;
}

test('only roles with the Settings page open Settings › Company', function (CompanyRole $role, int $status) {
    $books = singleBooks();

    $this->actingAs(userWithRole($role, $books->company))->get(route('settings.company'))->assertStatus($status);
    $this->actingAs(userWithRole($role, $books->company))->put(route('settings.company.update'), companyForm($books->company))->assertStatus($status === 200 ? 302 : 403);
})->with([
    'admin' => [CompanyRole::Admin, 200],
    'partner' => [CompanyRole::Partner, 403],
    'member' => [CompanyRole::Member, 403],
    'viewer' => [CompanyRole::Viewer, 403],
]);

test('settings start at Company', function () {
    $books = singleBooks();

    $this->actingAs(userWithRole(CompanyRole::Admin, $books->company))->get('/settings')->assertRedirect('/settings/company');
});

test('the page shows every field, the locks and the fiscal years', function () {
    $books = yearEndBooks();

    $this->actingAs(userWithRole(CompanyRole::Admin, $books->company))->get(route('settings.company'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/company')
            ->where('settings.name', 'Vertex Labs Pvt Ltd')
            ->where('settings.type', 'single')
            ->where('settings.openingBalancePaise', 1_00_000_00)
            ->where('settings.fyStartMonth', 4)
            ->where('settings.workingDays', 31)
            ->where('settings.hoursPerDayCenti', 800)
            ->where('locks.opening', false)
            ->where('locks.currency', true)
            ->has('years', 2)
            ->where('years.0.label', 'FY 2026-27')
            ->where('years.0.closable', false)
            ->where('years.1.label', 'FY 2025-26')
            ->where('years.1.closable', true)
            ->where('years.1.entries', 4)
            ->where('abilities.closeYear', true)
            ->where('abilities.reopenYear', true)
            ->missing('closing')
            ->has('can.pages'));
});

test('saving updates the details, attribution and calendar', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->put(route('settings.company.update'), companyForm($books->company, [
        'name' => 'Vertex Labs',
        'gstin' => '27abcde1234f1z5',
        'short_code' => 'vlx',
        'attribution_mode' => 'jv_pooled',
        'working_days' => 63,
        'hours_per_day' => '7.5',
    ]))->assertRedirect()->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Company settings saved');

    $company = $books->company->fresh();
    expect($company->name)->toBe('Vertex Labs')
        ->and($company->gstin)->toBe('27ABCDE1234F1Z5')
        ->and($company->short_code)->toBe('VLX')
        ->and($company->attribution_mode->value)->toBe('jv_pooled')
        ->and($company->working_days)->toBe(63)
        ->and($company->hours_per_day_centi)->toBe(750)
        ->and(Activity::query()->where('description', 'updated company settings')->exists())->toBeTrue();
});

test('validation: GSTIN format, a unique short code, at least one working day', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    Company::factory()->create(['workspace_id' => $books->company->workspace_id, 'short_code' => 'TAKEN']);

    $this->actingAs($admin)->put(route('settings.company.update'), companyForm($books->company, [
        'name' => '',
        'gstin' => '12345',
        'short_code' => 'taken',
        'working_days' => 0,
        'currency' => 'GBP',
        'opening_balance' => '-5',
        'hours_per_day' => '30',
    ]))->assertSessionHasErrors([
        'name' => 'Add the company’s name.',
        'gstin' => 'A GSTIN is 15 characters, like 27ABCDE1234F1Z5.',
        'short_code' => 'Another company in this workspace uses TAKEN. Pick another code.',
        'working_days' => 'Pick at least one working day.',
        'currency',
        'opening_balance',
        'hours_per_day',
    ]);
});

test('changing the opening balance reverses the opening entry and posts the new one; a closed year locks it', function () {
    $books = yearEndBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->put(route('settings.company.update'), companyForm($books->company, ['opening_balance' => '1,50,000']))
        ->assertSessionHasNoErrors();

    $figures = app(Reports::class)->figures($books->company, Period::between(CarbonImmutable::create(2025, 4, 1), CarbonImmutable::create(2026, 3, 31)));
    expect($figures['opening'])->toBe(1_50_000_00)
        ->and(app(IntegrityCheck::class)->run($books->company))->toBe([]);

    $this->actingAs($admin)->post(route('settings.company.close', FiscalYear::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->where('label', 'FY 2025-26')->sole()), ['confirm' => 'CLOSE FY 2025-26'])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)->put(route('settings.company.update'), companyForm($books->company->fresh(), ['opening_balance' => '2,00,000']))
        ->assertSessionHasErrors(['opening_balance' => 'The opening balance can’t change once a fiscal year is closed.']);
});

test('the currency locks once the books have transactions', function () {
    $books = yearEndBooks();

    $this->actingAs(userWithRole(CompanyRole::Admin, $books->company))
        ->put(route('settings.company.update'), companyForm($books->company, ['currency' => 'USD', 'opening_balance' => '1,00,000']))
        ->assertSessionHasErrors(['currency' => 'The currency is locked once the books have transactions.']);
});

test('moving the fiscal year start rebuilds the open years', function () {
    $books = yearEndBooks();

    $this->actingAs(userWithRole(CompanyRole::Admin, $books->company))
        ->put(route('settings.company.update'), companyForm($books->company, ['fy_start_month' => 1, 'opening_balance' => '1,00,000']))
        ->assertSessionHasNoErrors();

    expect(FiscalYear::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->orderBy('starts_on')->pluck('label')->all())
        ->toBe(['FY 2025', 'FY 2026']);
});

test('the logo uploads to the private disk, is served to the company and can be removed', function () {
    Storage::fake('local');
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->post(route('settings.company.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png', 300, 300)])
        ->assertSessionHasNoErrors();
    $path = $books->company->fresh()->logo_path;
    Storage::disk('local')->assertExists($path);

    $this->actingAs($admin)->get(route('settings.company.logo'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    // Exported reports carry it.
    $pdf = $this->actingAs($admin)->get(route('reports.export', ['format' => 'pdf']))->assertOk()->streamedContent();
    expect(str_starts_with($pdf, '%PDF'))->toBeTrue()->and($pdf)->toContain('/Subtype /Image');

    $this->actingAs($admin)->post(route('settings.company.logo.store'), ['logo' => UploadedFile::fake()->image('tiny.png', 100, 100)])
        ->assertSessionHasErrors(['logo' => 'The logo needs to be at least 256 px on each side.']);
    $this->actingAs($admin)->post(route('settings.company.logo.store'), ['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])
        ->assertSessionHasErrors('logo');

    $this->actingAs($admin)->delete(route('settings.company.logo.destroy'))->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($path);
    expect($books->company->fresh()->logo_path)->toBeNull();
});

test('closing a year: the preview, then the typed phrase posts the closing entry, carries capital forward and locks', function () {
    $books = yearEndBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $year = FiscalYear::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->where('label', 'FY 2025-26')->sole();

    $this->actingAs($admin)->get(route('settings.company'))
        ->assertInertia(fn (Assert $page) => $page->reloadOnly('closing', fn (Assert $reload) => $reload->where('closing', null)));

    $this->actingAs($admin)->get(route('settings.company', ['year' => $year->id]))
        ->assertInertia(fn (Assert $page) => $page->reloadOnly('closing', fn (Assert $reload) => $reload
            ->where('closing.label', 'FY 2025-26')
            ->where('closing.phrase', 'CLOSE FY 2025-26')
            ->where('closing.nextLabel', 'FY 2026-27')
            ->where('closing.netPaise', 4_00_000_00)
            ->where('closing.early', false)
            ->where('closing.partners.0.name', 'Aarav Mehta')
            ->where('closing.partners.0.sharePaise', 4_40_000_00)
            ->where('closing.partners.1.closingPaise', 40_000_00 + 50_000_00 - 40_000_00)));

    $this->actingAs($admin)->post(route('settings.company.close', $year), ['confirm' => 'CLOSE FY 2025'])
        ->assertSessionHasErrors(['confirm' => 'Type CLOSE FY 2025-26 to confirm.']);
    expect($year->fresh()->isClosed())->toBeFalse();

    $this->actingAs($admin)->post(route('settings.company.close', $year), ['confirm' => ' close fy 2025-26 '])
        ->assertRedirect(route('settings.company'))
        ->assertInertiaFlash('toast.message', 'FY 2025-26 closed. Balances moved to FY 2026-27.');

    $year->refresh();
    $statement = app(Reports::class)->capitalStatement($books->company, Period::fiscalYear(app(FiscalYears::class)->forDate($books->company, fyDay())));

    expect($year->isClosed())->toBeTrue()
        ->and($year->closing_entry_id)->not->toBeNull()
        ->and(JournalEntry::query()->withoutGlobalScopes()->find($year->closing_entry_id)->type)->toBe(JournalType::FyClose)
        ->and(YearEnd::profitAccountsZeroed($books->company, $year))->toBeTrue()
        // Next year's opening capital = last year's closing.
        ->and($statement[0]['opening'])->toBe(60_000_00 + 4_40_000_00)
        ->and($statement[1]['opening'])->toBe(50_000_00)
        ->and(app(IntegrityCheck::class)->run($books->company))->toBe([])
        ->and(Activity::query()->where('description', 'closed the fiscal year')->exists())->toBeTrue();
});

test('only Admins (Close fiscal year) close; only Admins reopen', function () {
    $books = yearEndBooks();
    $year = FiscalYear::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->where('label', 'FY 2025-26')->sole();
    $partner = userWithRole(CompanyRole::Partner, $books->company);

    $this->actingAs($partner)->post(route('settings.company.close', $year), ['confirm' => 'CLOSE FY 2025-26'])->assertForbidden();
    $this->actingAs($partner)->post(route('settings.company.reopen', $year))->assertForbidden();

    // Another company's year is a 404.
    $this->actingAs(userWithRole(CompanyRole::Admin))->post(route('settings.company.close', $year), ['confirm' => 'CLOSE FY 2025-26'])->assertNotFound();
});

test('years close in order', function () {
    $books = yearEndBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $current = app(FiscalYears::class)->forDate($books->company, fyDay());

    $this->actingAs($admin)->post(route('settings.company.close', $current), ['confirm' => 'CLOSE FY 2026-27'])
        ->assertSessionHasErrors(['confirm' => 'Close FY 2025-26 first.']);
});

test('reopening reverses the closing entry, unlocks and is audited; the books stay sound', function () {
    $books = yearEndBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $year = FiscalYear::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->where('label', 'FY 2025-26')->sole();
    $this->actingAs($admin)->post(route('settings.company.close', $year), ['confirm' => 'CLOSE FY 2025-26'])->assertSessionHasNoErrors();
    $closing = $year->fresh()->closing_entry_id;

    $this->actingAs($admin)->post(route('settings.company.reopen', $year))
        ->assertRedirect(route('settings.company'))
        ->assertInertiaFlash('toast.message', 'FY 2025-26 reopened. Its closing entries were reversed.');

    expect($year->fresh()->isClosed())->toBeFalse()
        ->and(JournalEntry::query()->withoutGlobalScopes()->find($closing)->reversed_by_id)->not->toBeNull()
        ->and(app(IntegrityCheck::class)->run($books->company))->toBe([])
        ->and(Activity::query()->where('description', 'reopened the fiscal year')->exists())->toBeTrue();
});

test('a closed year blocks create, edit and delete in every module (spec §4.6)', function () {
    $books = jvBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $rohan = Member::factory()->create(['partner_id' => $books->aarav->id, 'name' => 'Rohan Desai', 'rate_paise' => 500_00]);
    $project = Project::query()->withoutGlobalScope('company')->where('company_id', $books->company->id)->firstOrFail();
    $vertex = 'account:'.$books->vertexAccount->id;
    $day = '2025-06-10';

    $income = fn (array $o = []) => $o + ['source' => 'Asha Retail', 'amount_paise' => 3_00_000_00, 'received_on' => $day, 'category_id' => category($books->company, 'income', 'Project')->id, 'brought_in_by' => $books->aarav->id, 'received_into' => $vertex];
    $expense = fn (array $o = []) => $o + ['description' => 'Figma seats', 'amount_paise' => 12_000_00, 'spent_on' => $day, 'category_id' => category($books->company, 'expense', 'Software')->id, 'payer_mode' => 'company', 'payers' => [['type' => 'company', 'partner_id' => null, 'account_id' => $books->vertexAccount->id, 'amount_paise' => 12_000_00]]];
    $time = fn (array $o = []) => $o + ['member_id' => $rohan->id, 'mode' => 'day', 'date' => $day, 'hours' => '6', 'project_id' => $project->id, 'billable' => true];
    $capital = fn (array $o = []) => $o + ['kind' => 'contribution', 'partner_id' => $books->aarav->id, 'amount_paise' => 1_00_000_00, 'date' => $day, 'money_account_id' => $books->vertexAccount->id, 'note' => 'Capital'];
    $loan = fn (array $o = []) => $o + ['partner_id' => $books->aarav->id, 'source' => 'account', 'amount_paise' => 50_000_00, 'date' => $day, 'money_account_id' => $books->vertexAccount->id, 'purpose' => 'Cash flow'];
    $transfer = fn (array $o = []) => $o + ['from_side_id' => $books->vertex->id, 'to_side_id' => $books->bluefin->id, 'amount_paise' => 10_000_00, 'date' => $day, 'reference' => 'UTR1'];

    // One record of each while the year is open.
    foreach ([['income.store', $income()], ['expenses.store', $expense()], ['timesheets.store', $time()], ['capital-accounts.store', $capital()], ['partner-loans.store', $loan()], ['settlements.store', $transfer()]] as [$route, $payload]) {
        $this->actingAs($admin)->post(route($route), $payload)->assertSessionHasNoErrors();
    }

    $scoped = fn (string $model) => $model::query()->withoutGlobalScopes()->where('company_id', $books->company->id);
    $theIncome = $scoped(Income::class)->sole();
    $theExpense = $scoped(Expense::class)->sole();
    $this->actingAs($admin)->post(route('expenses.approve', $theExpense))->assertSessionHasNoErrors();
    $theEntry = $scoped(TimeEntry::class)->sole();
    $theMovement = $scoped(CapitalMovement::class)->sole();
    $theLoan = $scoped(Loan::class)->sole();
    $theTransfer = $scoped(SettlementTransfer::class)->sole();

    $year = FiscalYear::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->where('label', 'FY 2025-26')->sole();
    $this->actingAs($admin)->post(route('settings.company.close', $year), ['confirm' => 'CLOSE FY 2025-26'])->assertSessionHasNoErrors();
    $entries = JournalEntry::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->count();

    $attempts = [
        // Create.
        fn () => $this->post(route('income.store'), $income()),
        fn () => $this->post(route('expenses.store'), $expense()),
        fn () => $this->post(route('timesheets.store'), $time()),
        fn () => $this->post(route('capital-accounts.store'), $capital()),
        fn () => $this->post(route('partner-loans.store'), $loan()),
        fn () => $this->post(route('settlements.store'), $transfer()),
        // Edit.
        fn () => $this->put(route('income.update', $theIncome), $income(['amount_paise' => 2_00_000_00])),
        fn () => $this->put(route('expenses.update', $theExpense), $expense(['amount_paise' => 10_000_00, 'payers' => [['type' => 'company', 'partner_id' => null, 'account_id' => $books->vertexAccount->id, 'amount_paise' => 10_000_00]]])),
        fn () => $this->put(route('timesheets.update', $theEntry), $time(['mode' => null, 'hours' => '4'])),
        fn () => $this->put(route('settlements.update', $theTransfer), $transfer(['amount_paise' => 5_000_00])),
        // Delete.
        fn () => $this->delete(route('income.destroy', $theIncome)),
        fn () => $this->delete(route('expenses.destroy', $theExpense)),
        fn () => $this->delete(route('timesheets.destroy', $theEntry)),
        fn () => $this->delete(route('capital-accounts.destroy', $theMovement)),
        fn () => $this->delete(route('partner-loans.destroy', $theLoan)),
        fn () => $this->delete(route('settlements.destroy', $theTransfer)),
    ];

    foreach ($attempts as $attempt) {
        $this->actingAs($admin);
        $attempt()->assertSessionHasErrors();
        session()->forget('errors');
    }

    expect(JournalEntry::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->count())->toBe($entries)
        ->and($scoped(Income::class)->count())->toBe(1)
        ->and($scoped(TimeEntry::class)->sole()->hours_centi)->toBe(600)
        ->and(app(IntegrityCheck::class)->run($books->company))->toBe([]);
});

test('an SVG logo gets a clear message, and company names stop at 60 characters (A5, G7)', function () {
    Storage::fake('local');
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->post(route('settings.company.logo.store'), ['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])
        ->assertSessionHasErrors(['logo' => 'Use a PNG, JPG or WebP image. SVG logos aren’t supported.']);

    $this->actingAs($admin)->put(route('settings.company.update'), companyForm($books->company, ['name' => str_repeat('A', 61)]))
        ->assertSessionHasErrors(['name' => 'Keep the company name to 60 characters.']);
    $this->actingAs($admin)->put(route('settings.company.update'), companyForm($books->company, ['name' => '  '.str_repeat('B', 60).'  ']))
        ->assertSessionHasNoErrors();
    expect($books->company->fresh()->name)->toBe(str_repeat('B', 60));
});
