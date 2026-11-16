<?php

declare(strict_types=1);

use App\Enums\JournalType;
use App\Ledger\ChartOfAccounts;
use App\Ledger\EntryData;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\Exceptions\PeriodLockedException;
use App\Ledger\Exceptions\UnbalancedEntryException;
use App\Ledger\FiscalYears;
use App\Ledger\LedgerPoster;
use App\Ledger\LineData;
use App\Models\JournalEntry;
use App\Models\JournalLine;

function poster(): LedgerPoster
{
    return app(LedgerPoster::class);
}

test('a balanced entry posts with its lines, dimensions and fiscal year', function () {
    $books = singleBooks();
    $chart = app(ChartOfAccounts::class);
    $money = $chart->money($books->account);
    $income = $chart->income(category($books->company, 'income', 'Project'));

    $entry = poster()->post($books->company, new EntryData(fyDay(10), JournalType::Income, [
        LineData::debit($money, 50_000_00),
        LineData::credit($income, 50_000_00, partnerId: $books->aarav->id),
    ], 'Asha Retail store'));

    expect($entry->lines()->withoutGlobalScopes()->count())->toBe(2)
        ->and(JournalLine::query()->withoutGlobalScopes()->where('account_id', $income->id)->value('partner_id'))->toBe($books->aarav->id)
        ->and($books->company->fresh()->id)->toBe($entry->company_id)
        ->and(app(FiscalYears::class)->forDate($books->company, fyDay(10))->label)->toBe('FY 2026-27');
});

test('unbalanced, one-sided and malformed entries are refused', function (array $amounts, string $exception) {
    $books = singleBooks();
    $chart = app(ChartOfAccounts::class);
    $money = $chart->money($books->account);
    $income = $chart->income(category($books->company, 'income', 'Project'));

    $lines = array_map(fn (array $a, int $i) => new LineData($i % 2 === 0 ? $money : $income, $a[0], $a[1]), $amounts, array_keys($amounts));

    poster()->post($books->company, new EntryData(fyDay(), JournalType::Income, $lines));
})->with([
    'unbalanced' => [[[100, 0], [0, 99]], UnbalancedEntryException::class],
    'single line' => [[[100, 0]], UnbalancedEntryException::class],
    'both sides' => [[[100, 100], [0, 0]], UnbalancedEntryException::class],
    'negative' => [[[-100, 0], [0, -100]], UnbalancedEntryException::class],
])->throws(LedgerException::class);

test('an account from another company is refused', function () {
    $mine = singleBooks();
    $theirs = singleBooks();
    $chart = app(ChartOfAccounts::class);

    poster()->post($mine->company, new EntryData(fyDay(), JournalType::Income, [
        LineData::debit($chart->money($theirs->account), 100),
        LineData::credit($chart->income(category($mine->company, 'income', 'Project')), 100),
    ]));
})->throws(LedgerException::class, 'An account from another company can’t be used.');

test('nothing can be posted into a closed fiscal year', function () {
    $books = singleBooks();
    $chart = app(ChartOfAccounts::class);
    app(FiscalYears::class)->forDate($books->company, fyDay())->update(['closed_at' => now()]);

    poster()->post($books->company, new EntryData(fyDay(20), JournalType::Income, [
        LineData::debit($chart->money($books->account), 100),
        LineData::credit($chart->income(category($books->company, 'income', 'Project')), 100),
    ]));
})->throws(PeriodLockedException::class, 'FY 2026-27 is closed');

test('a reversal mirrors the lines and links both entries; it can’t happen twice', function () {
    $books = singleBooks();
    $chart = app(ChartOfAccounts::class);
    $money = $chart->money($books->account);

    $entry = poster()->post($books->company, new EntryData(fyDay(5), JournalType::Income, [
        LineData::debit($money, 7_500_00),
        LineData::credit($chart->income(category($books->company, 'income', 'Consulting')), 7_500_00, partnerId: $books->ishita->id),
    ], 'Kiran Exports'));

    $reversal = poster()->reverse($entry);

    expect($reversal->type)->toBe(JournalType::Reversal)
        ->and($reversal->reverses_id)->toBe($entry->id)
        ->and($entry->fresh()->reversed_by_id)->toBe($reversal->id)
        ->and((int) JournalLine::query()->withoutGlobalScopes()->where('account_id', $money->id)->sum('debit_paise'))->toBe(7_500_00)
        ->and((int) JournalLine::query()->withoutGlobalScopes()->where('account_id', $money->id)->sum('credit_paise'))->toBe(7_500_00)
        ->and(JournalLine::query()->withoutGlobalScopes()->where('journal_entry_id', $reversal->id)->whereNotNull('partner_id')->value('partner_id'))->toBe($books->ishita->id);

    expect(fn () => poster()->reverse($entry->fresh()))->toThrow(LedgerException::class, 'already been reversed');
    expect(fn () => poster()->reverse($reversal))->toThrow(LedgerException::class);
});

test('an entry in a closed year can’t be reversed', function () {
    $books = singleBooks();
    $chart = app(ChartOfAccounts::class);
    $entry = poster()->post($books->company, new EntryData(fyDay(5), JournalType::Income, [
        LineData::debit($chart->money($books->account), 100),
        LineData::credit($chart->income(category($books->company, 'income', 'Project')), 100),
    ]));
    app(FiscalYears::class)->forDate($books->company, fyDay())->update(['closed_at' => now()]);

    poster()->reverse($entry, fyDay(400));
})->throws(PeriodLockedException::class);

test('zero lines are left out', function () {
    $books = singleBooks();
    $chart = app(ChartOfAccounts::class);

    $entry = poster()->post($books->company, new EntryData(fyDay(), JournalType::Income, [
        LineData::debit($chart->money($books->account), 100),
        LineData::credit($chart->capital($books->aarav), 0),
        LineData::credit($chart->income(category($books->company, 'income', 'Project')), 100),
    ]));

    expect(JournalEntry::query()->withoutGlobalScopes()->findOrFail($entry->id)->lines()->withoutGlobalScopes()->count())->toBe(2);
});
