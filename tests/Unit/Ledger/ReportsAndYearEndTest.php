<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\Exceptions\PeriodLockedException;
use App\Ledger\FiscalYears;
use App\Ledger\IntegrityCheck;
use App\Ledger\OpeningBalance;
use App\Ledger\PayerLine;
use App\Ledger\Period;
use App\Ledger\Postings;
use App\Ledger\Reports;
use App\Ledger\YearEnd;
use App\Models\Member;

/** Vertex Labs with a year of activity: opening ₹2,50,000, income, expenses, salary, capital and a loan. */
function busySingle(): object
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
    $p->salary($books->company, $neha, 32_000_00, fyDay(60));
    $p->contribution($books->company, fyDay(20), $books->aarav, 1_00_000_00, $books->account);
    $p->drawing($books->company, fyDay(70), $books->ishita, 40_000_00, $books->account);
    $p->loan($books->company, fyDay(75), $books->aarav, 60_000_00, $books->account);

    return $books;
}

test('profit and loss totals, by month and by category', function () {
    $books = busySingle();
    $reports = app(Reports::class);
    $fy = Period::fiscalYearOf($books->company, fyDay());

    expect($reports->profitAndLoss($books->company, $fy))->toBe([
        'income' => 5_50_000_00, 'expenses' => 1_06_000_00, 'salary' => 64_000_00, 'other' => 42_000_00, 'net' => 4_44_000_00,
    ]);

    $months = collect($reports->profitAndLossByMonth($books->company, $fy))->keyBy('month');
    expect($months->keys()->all())->toBe(['2026-04', '2026-05'])
        ->and($months['2026-04']['income'])->toBe(4_00_000_00)
        ->and($months['2026-04']['salary'])->toBe(32_000_00)
        ->and($months['2026-05']['net'])->toBe(1_50_000_00 - 12_000_00 - 32_000_00);

    $categories = collect($reports->byCategory($books->company, $fy))->keyBy('name');
    expect($categories['Project']['amount'])->toBe(4_00_000_00)
        ->and($categories['Salary']['amount'])->toBe(64_000_00);
});

test('the trial balance balances and the balance sheet adds up', function () {
    $books = busySingle();
    $reports = app(Reports::class);

    $trial = $reports->trialBalance($books->company, Period::asOf(fyDay(100)));
    $sheet = $reports->balanceSheet($books->company, fyDay(100));

    // Cash: 2,50,000 + 4,00,000 + 1,50,000 − 30,000 − 64,000 + 1,00,000 − 40,000 + 60,000 = 8,26,000.
    expect($trial['drift'])->toBe(0)
        ->and($trial['debit'])->toBe($trial['credit'])
        ->and($sheet['balanced'])->toBeTrue()
        ->and(collect($sheet['assets'])->firstWhere('name', 'Company account')['amount'])->toBe(8_26_000_00)
        ->and(collect($sheet['liabilities'])->pluck('amount', 'name')->all())->toBe([
            'Partner current — Ishita Rao' => 12_000_00,
            'Loans payable — Aarav Mehta' => 60_000_00,
        ])
        ->and($sheet['currentYearProfit'])->toBe(4_44_000_00);
});

test('the capital statement closes at the balance sheet’s equity', function () {
    $books = busySingle();
    $reports = app(Reports::class);
    $fy = Period::fiscalYearOf($books->company, fyDay());

    $statement = collect($reports->capitalStatement($books->company, $fy))->keyBy('name');

    // Per partner: Aarav brought in 4,00,000; the unattributed 1,50,000 − 30,000 = 1,20,000 splits 60/40.
    expect($statement['Aarav Mehta'])->toMatchArray(['opening' => 1_50_000_00, 'contributions' => 1_00_000_00, 'drawings' => 0, 'profit' => 4_00_000_00 + 72_000_00])
        ->and($statement['Ishita Rao'])->toMatchArray(['opening' => 1_00_000_00, 'drawings' => 40_000_00, 'profit' => -12_000_00 - 64_000_00 + 48_000_00])
        ->and($statement->sum('closing'))->toBe($reports->balanceSheet($books->company, fyDay()->addYear()->subDay())['totalEquity']);
});

test('the figures strip: opening + income − expenses ± capital and loans = current balance', function () {
    $books = busySingle();
    $f = app(Reports::class)->figures($books->company, Period::fiscalYearOf($books->company, fyDay()));

    expect($f['currentBalance'])->toBe(8_26_000_00)
        ->and($f['opening'])->toBe(2_50_000_00)
        ->and($f['opening'] + $f['income'] - $f['expenses'] + $f['capitalAndLoans'])->toBe($f['currentBalance']);
});

test('closing a year moves profit into capital and locks it; reopening undoes both', function () {
    $books = busySingle();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $year = app(FiscalYears::class)->forDate($books->company, fyDay());
    $yearEnd = app(YearEnd::class);
    $reports = app(Reports::class);

    $preview = $yearEnd->preview($books->company, $year);
    $entry = $yearEnd->close($books->company, $year, $admin);

    expect($preview['net'])->toBe(4_44_000_00)
        ->and($entry->date->toDateString())->toBe('2027-03-31')
        ->and(YearEnd::profitAccountsZeroed($books->company, $year))->toBeTrue()
        ->and($reports->balanceSheet($books->company, fyDay(400))['currentYearProfit'])->toBe(0)
        ->and(collect($reports->capitalStatement($books->company, Period::fiscalYear($year)))->sum('profit'))->toBe(4_44_000_00)
        ->and(app(IntegrityCheck::class)->run($books->company, fyDay(400)))->toBe([]);

    expect(fn () => app(Postings::class)->incomeToAccount($books->company, fyDay(80), 100, category($books->company, 'income', 'Other'), $books->account))
        ->toThrow(PeriodLockedException::class);

    // Next year's statement opens with last year's closing capital.
    $next = collect($reports->capitalStatement($books->company, Period::fiscalYearOf($books->company, fyDay(400))))->sum('opening');
    expect($next)->toBe(collect($reports->capitalStatement($books->company, Period::fiscalYear($year)))->sum('closing'));

    $yearEnd->reopen($books->company, $year->fresh(), $admin);

    expect($year->fresh()->isClosed())->toBeFalse()
        ->and($reports->balanceSheet($books->company, fyDay(400))['currentYearProfit'])->toBe(4_44_000_00)
        ->and(app(IntegrityCheck::class)->run($books->company, fyDay(400)))->toBe([]);
});

test('years close in order and reopen in reverse order', function () {
    $books = busySingle();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $fys = app(FiscalYears::class);
    $first = $fys->forDate($books->company, fyDay());
    $second = $fys->forDate($books->company, fyDay(400));
    $yearEnd = app(YearEnd::class);

    expect(fn () => $yearEnd->close($books->company, $second, $admin))->toThrow(LedgerException::class, 'Close FY 2026-27 first.');

    $yearEnd->close($books->company, $first, $admin);
    $yearEnd->close($books->company, $second, $admin);

    expect(fn () => $yearEnd->reopen($books->company, $first->fresh(), $admin))->toThrow(LedgerException::class, 'Reopen FY 2027-28 first.')
        ->and(fn () => $yearEnd->close($books->company, $first->fresh(), $admin))->toThrow(LedgerException::class, 'already closed');
});
