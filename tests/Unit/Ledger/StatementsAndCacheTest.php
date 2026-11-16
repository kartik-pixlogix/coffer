<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Ledger\CachedReports;
use App\Ledger\DriftFinder;
use App\Ledger\FiscalYears;
use App\Ledger\LedgerPoster;
use App\Ledger\OpeningBalance;
use App\Ledger\PayerLine;
use App\Ledger\Period;
use App\Ledger\Postings;
use App\Ledger\ReportCache;
use App\Ledger\Reports;
use App\Ledger\YearEnd;
use App\Models\JournalEntry;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Vertex Labs with opening cash, income, expenses, salary, capital, a loan and a personal card. */
function statementBooks(): object
{
    $books = singleBooks(openingPaise: 2_50_000_00);
    $p = app(Postings::class);
    $neha = Member::factory()->for($books->company)->create(['name' => 'Neha Joshi', 'partner_id' => $books->ishita->id]);

    app(OpeningBalance::class)->post($books->company, fyDay());
    $p->incomeToAccount($books->company, fyDay(10), 4_00_000_00, category($books->company, 'income', 'Project'), $books->account, $books->aarav);
    $p->incomeToAccount($books->company, fyDay(45), 1_50_000_00, category($books->company, 'income', 'Consulting'), $books->account);
    $p->expense($books->company, fyDay(12), category($books->company, 'expense', 'Software'), [PayerLine::company(30_000_00, $books->account)]);
    $p->expense($books->company, fyDay(50), category($books->company, 'expense', 'Travel'), [PayerLine::partner(12_000_00, $books->ishita)]);
    $p->salary($books->company, $neha, 32_000_00, fyDay(29));
    $p->contribution($books->company, fyDay(20), $books->aarav, 1_00_000_00, $books->account);
    $p->drawing($books->company, fyDay(70), $books->ishita, 40_000_00, $books->account);
    $p->loan($books->company, fyDay(75), $books->aarav, 60_000_00, $books->account);

    return $books;
}

test('the balance formula names every term: capital & loans and personal cards add up to the residual (D58)', function () {
    $books = statementBooks();
    $f = app(Reports::class)->figures($books->company, Period::between(fyDay(), fyDay(200)));

    // ₹1,00,000 in − ₹40,000 drawn + ₹60,000 lent = ₹1,20,000; Ishita's card is owed ₹12,000.
    expect($f['capital'])->toBe(1_20_000_00)
        ->and($f['personal'])->toBe(12_000_00)
        ->and($f['capital'] + $f['personal'])->toBe($f['capitalAndLoans'])
        ->and($f['opening'] + $f['income'] - $f['expenses'] + $f['capital'] + $f['personal'])->toBe($f['currentBalance']);
});

test('the trial balance as of a date balances and shows the year’s income before closing', function () {
    $books = statementBooks();
    $reports = app(Reports::class);
    $tb = $reports->trialBalanceAsOf($books->company, fyDay(), fyDay(200));
    $income = collect($tb['accounts'])->where('type', 'income')->sum(fn ($a) => $a['credit'] - $a['debit']);

    expect($tb['drift'])->toBe(0)
        ->and($tb['debit'])->toBe($tb['credit'])
        ->and($income)->toBe($reports->profitAndLoss($books->company, Period::between(fyDay(), fyDay(200)))['income']);

    // Closed, the year still shows its income (its own close is left out) and still balances.
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $year = app(FiscalYears::class)->forDate($books->company, fyDay());
    app(YearEnd::class)->close($books->company, $year, $admin);
    $closed = $reports->trialBalanceAsOf($books->company, fyDay(), $year->ends_on);

    expect($closed['drift'])->toBe(0)
        ->and(collect($closed['accounts'])->where('type', 'income')->sum(fn ($a) => $a['credit'] - $a['debit']))->toBe(5_50_000_00);

    // The next year starts with no income and capital carried forward.
    $next = $reports->trialBalanceAsOf($books->company, $year->ends_on->addDay(), $year->ends_on->addDays(10));
    expect($next['drift'])->toBe(0)
        ->and(collect($next['accounts'])->where('type', 'income')->count())->toBe(0)
        ->and(collect($next['accounts'])->firstWhere('code', '3900'))->toBeNull();
});

test('profit of an earlier year that was never closed shows as its own row and the columns still agree', function () {
    $books = statementBooks();
    $tb = app(Reports::class)->trialBalanceAsOf($books->company, fyDay(365), fyDay(380));
    $row = collect($tb['accounts'])->firstWhere('code', '3900');

    expect($tb['drift'])->toBe(0)
        ->and($row)->not->toBeNull()
        ->and($row['credit'])->toBe(5_50_000_00 - 30_000_00 - 12_000_00 - 32_000_00);
});

test('find the drift lists the entry a corrupted line unbalances, and the trial balance reports it', function () {
    $books = statementBooks();
    $entry = JournalEntry::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->where('type', 'expense')->orderBy('id')->firstOrFail();

    // Simulate a bad manual fix: one line's debit changes behind LedgerPoster's back.
    DB::table('journal_lines')->where('journal_entry_id', $entry->id)->where('debit_paise', '>', 0)->limit(1)->update(['debit_paise' => 42_500_00]);
    app(ReportCache::class)->flush($books->company);

    $tb = app(Reports::class)->trialBalanceAsOf($books->company, fyDay(), fyDay(200));
    $issues = app(DriftFinder::class)->find($books->company, fyDay(200));

    expect($tb['drift'])->toBe(12_500_00)
        ->and($issues)->toHaveCount(1)
        ->and($issues[0]['kind'])->toBe('unbalanced')
        ->and($issues[0]['entryId'])->toBe($entry->id)
        ->and($issues[0]['amount'])->toBe(12_500_00)
        ->and($issues[0]['message'])->toBe('Debits are higher than credits by ₹12,500.');
});

test('find the drift also catches lines dated apart from their entry and accounts of the wrong type', function () {
    $books = statementBooks();
    $entry = JournalEntry::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->where('type', 'income')->orderBy('id')->firstOrFail();
    DB::table('journal_lines')->where('journal_entry_id', $entry->id)->limit(1)->update(['date' => fyDay(300)->toDateString()]);
    DB::table('accounts')->where('company_id', $books->company->id)->where('role', 'money')->update(['type' => 'income']);

    $kinds = collect(app(DriftFinder::class)->find($books->company, fyDay(400)))->pluck('kind');

    expect($kinds)->toContain('line')->toContain('account');
});

test('sound books have nothing to find', function () {
    $books = statementBooks();

    expect(app(DriftFinder::class)->find($books->company, fyDay(400)))->toBe([]);
});

test('cached aggregates refresh after a posting, a reversal and an equity change (D62)', function () {
    $books = statementBooks();
    $cached = app(CachedReports::class);
    $period = Period::between(fyDay(), fyDay(200));

    $before = $cached->figures($books->company, $period)['income'];
    $entry = app(Postings::class)->incomeToAccount($books->company, fyDay(80), 25_000_00, category($books->company, 'income', 'Other'), $books->account);
    expect($cached->figures($books->company, $period)['income'])->toBe($before + 25_000_00);

    app(LedgerPoster::class)->reverse($entry);
    expect($cached->figures($books->company, $period)['income'])->toBe($before);

    $shares = $cached->profitShares($books->company, $period);
    $books->aarav->update(['equity_bps' => 5000]);
    $books->ishita->update(['equity_bps' => 5000]);
    expect($cached->profitShares($books->company, $period))->not->toBe($shares);
});

test('the report cache works on the database store, which has no tags', function () {
    $books = statementBooks();
    $cache = new ReportCache(Cache::store('database'));
    $calls = 0;
    $compute = function () use (&$calls) {
        $calls++;

        return ['net' => 1];
    };

    $cache->remember($books->company, 'figures', ['a'], $compute);
    $cache->remember($books->company, 'figures', ['a'], $compute);
    expect($calls)->toBe(1);

    $cache->flush($books->company);
    $cache->remember($books->company, 'figures', ['a'], $compute);
    expect($calls)->toBe(2);

    // Another company's version is untouched.
    $other = singleBooks();
    $cache->remember($other->company, 'figures', ['a'], $compute);
    $cache->flush($books->company);
    $cache->remember($other->company, 'figures', ['a'], $compute);
    expect($calls)->toBe(3);
});

test('cached figures match the uncached reports', function () {
    $books = statementBooks();
    $period = Period::between(fyDay(), fyDay(200));
    $cached = app(CachedReports::class);
    $reports = app(Reports::class);

    expect($cached->figures($books->company, $period))->toBe($reports->figures($books->company, $period))
        ->and($cached->balanceSheet($books->company, fyDay(200)))->toBe($reports->balanceSheet($books->company, fyDay(200)))
        ->and($cached->capitalStatement($books->company, $period))->toBe($reports->capitalStatement($books->company, $period))
        ->and($cached->trialBalance($books->company, fyDay(), fyDay(200)))->toBe($reports->trialBalanceAsOf($books->company, fyDay(), fyDay(200)));

    CarbonImmutable::setTestNow();
});
