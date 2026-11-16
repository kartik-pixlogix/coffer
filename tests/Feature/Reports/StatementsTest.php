<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Ledger\OpeningBalance;
use App\Ledger\PayerLine;
use App\Ledger\Postings;
use App\Ledger\ReportCache;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** Opening ₹1,00,000, ₹3,00,000 income, a ₹50,000 expense on Ishita's card and a ₹40,000 loan. */
function sheetBooks(): object
{
    $books = singleBooks(openingPaise: 1_00_000_00);
    $p = app(Postings::class);
    app(OpeningBalance::class)->post($books->company, fyDay());
    $p->incomeToAccount($books->company, fyDay(15), 3_00_000_00, category($books->company, 'income', 'Project'), $books->account, $books->aarav);
    $p->expense($books->company, fyDay(20), category($books->company, 'expense', 'Software'), [PayerLine::partner(50_000_00, $books->ishita)]);
    $p->loan($books->company, fyDay(40), $books->aarav, 40_000_00, $books->account);

    return $books;
}

test('trial balance and balance sheet follow the matrix (Viewers see the balance sheet only)', function (CompanyRole $role, int $tb, int $bs) {
    $books = sheetBooks();
    $user = userWithRole($role, $books->company);

    $this->actingAs($user)->get(route('reports.trial-balance'))->assertStatus($tb);
    $this->actingAs($user)->get(route('reports.trial-balance.export', ['format' => 'xlsx']))->assertStatus($tb);
    $this->actingAs($user)->get(route('reports.balance-sheet'))->assertStatus($bs);
    $this->actingAs($user)->get(route('reports.balance-sheet.export', ['format' => 'xlsx']))->assertStatus($bs);
})->with([
    'admin' => [CompanyRole::Admin, 200, 200],
    'partner' => [CompanyRole::Partner, 200, 200],
    'viewer' => [CompanyRole::Viewer, 403, 200],
    'member' => [CompanyRole::Member, 403, 403],
]);

test('the trial balance lists each account’s balance and the totals agree', function () {
    $books = sheetBooks();

    $this->actingAs(userWithRole(CompanyRole::Admin, $books->company))->get(route('reports.trial-balance'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/trial-balance')
            ->where('date.asOf', '2026-11-15')
            ->where('date.fy.label', 'FY 2026-27')
            ->where('trial.driftPaise', 0)
            ->where('trial.debitPaise', 4_40_000_00 + 50_000_00)
            ->where('trial.creditPaise', 4_40_000_00 + 50_000_00)
            ->missing('drift')
            ->has('can.pages'));
});

test('the as-of date moves the balances and stays inside the year', function () {
    $books = sheetBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->get(route('reports.balance-sheet', ['asof' => fyDay(16)->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('date.asOf', '2026-04-17')
            ->where('sheet.totalAssetsPaise', 4_00_000_00)
            ->where('sheet.totalLiabilitiesPaise', 0)
            ->where('sheet.totalEquityPaise', 4_00_000_00)
            ->where('sheet.driftPaise', 0));

    $this->actingAs($admin)->get(route('reports.balance-sheet'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sheet.totalAssetsPaise', 4_40_000_00)
            ->where('sheet.totalLiabilitiesPaise', 90_000_00)
            ->where('sheet.totalEquityPaise', 3_50_000_00)
            ->where('sheet.currentYearProfitPaise', 2_50_000_00)
            ->where('capitalClosingPaise', 3_50_000_00)
            ->where('sheet.liabilities.0.name', fn (string $name) => str_contains($name, 'Ishita') || str_contains($name, 'Aarav')));
});

test('an out-of-balance ledger shows the drift, and Find the drift lists the culprit on demand', function () {
    $books = sheetBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $entry = JournalEntry::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->where('type', 'income')->sole();
    DB::table('journal_lines')->where('journal_entry_id', $entry->id)->where('credit_paise', '>', 0)->update(['credit_paise' => 2_87_500_00]);
    app(ReportCache::class)->flush($books->company);

    $this->actingAs($admin)->get(route('reports.trial-balance'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('trial.driftPaise', 12_500_00)
            ->missing('drift')
            ->reloadOnly('drift', fn (Assert $reload) => $reload
                ->has('drift', 1)
                ->where('drift.0.entryId', $entry->id)
                ->where('drift.0.kind', 'unbalanced')
                ->where('drift.0.amount', 12_500_00)));

    $this->actingAs($admin)->get(route('reports.balance-sheet'))
        ->assertInertia(fn (Assert $page) => $page->where('sheet.driftPaise', fn (int $d) => $d !== 0));
});

test('recheck recomputes past the cache', function () {
    $books = sheetBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->get(route('reports.trial-balance'))->assertInertia(fn (Assert $page) => $page->where('trial.driftPaise', 0));

    // A change that bypasses the ledger (and so the cache bump) shows after Recheck.
    $entry = JournalEntry::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->where('type', 'loan')->sole();
    DB::table('journal_lines')->where('journal_entry_id', $entry->id)->where('debit_paise', '>', 0)->update(['debit_paise' => 40_000_01]);

    $this->actingAs($admin)->get(route('reports.trial-balance', ['recheck' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('trial.driftPaise', 1));
});

test('statements export as PDF and Excel', function () {
    $books = sheetBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $pdf = $this->actingAs($admin)->get(route('reports.trial-balance.export', ['format' => 'pdf']))->assertOk()->streamedContent();
    expect(str_starts_with($pdf, '%PDF'))->toBeTrue();

    $bs = $this->actingAs($admin)->get(route('reports.balance-sheet.export', ['format' => 'pdf']))->assertOk()->streamedContent();
    expect(str_starts_with($bs, '%PDF'))->toBeTrue();

    $file = $this->actingAs($admin)->get(route('reports.trial-balance.export', ['format' => 'xlsx']))->assertOk()->baseResponse->getFile()->getPathname();
    $copy = tempnam(sys_get_temp_dir(), 'tb').'.xlsx';
    copy($file, $copy);
    $rows = SimpleExcelReader::create($copy)->noHeaderRow()->getRows()->all();

    expect($rows[0])->toBe(['Code', 'Account', 'Type', 'Debit', 'Credit'])
        ->and(end($rows))->toBe(['', 'Total', '', 490000, 490000]);
    @unlink($copy);

    $this->actingAs($admin)->get(route('reports.balance-sheet.export', ['format' => 'csv']))->assertSessionHasErrors('format');
});
