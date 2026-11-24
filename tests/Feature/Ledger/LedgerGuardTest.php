<?php

declare(strict_types=1);

use App\Ledger\LedgerGuard;
use App\Ledger\LedgerPoster;
use App\Ledger\OpeningBalance;
use App\Models\JournalEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The database itself refuses changes to posted journal rows (Phase 10, D148): the triggers
| are installed by the migrations, so these run against the real test database.
*/

function guardedEntry(): JournalEntry
{
    $books = singleBooks(openingPaise: 1_00_000_00);

    return app(OpeningBalance::class)->post($books->company, fyDay()) ?? throw new RuntimeException('No opening entry.');
}

test('the guard is installed by the migrations', function () {
    expect(LedgerGuard::installed())->toBeTrue();

    $this->artisan('coffer:ledger-guard')->assertSuccessful();
});

test('the guard covers every journal_entries column', function () {
    // A new column has to be added to ENTRY_COLUMNS_FIXED (or _MUTABLE) and the triggers reinstalled.
    $columns = Schema::getColumnListing('journal_entries');
    sort($columns);
    $known = [...LedgerGuard::ENTRY_COLUMNS_FIXED, ...LedgerGuard::ENTRY_COLUMNS_MUTABLE];
    sort($known);

    expect($columns)->toBe($known);
});

test('an UPDATE on a posted journal line fails at the database', function () {
    $entry = guardedEntry();

    expect(fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->update(['debit_paise' => 1]))
        ->toThrow(QueryException::class, 'posted journal rows cannot be changed');

    expect(fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->update(['account_id' => DB::raw('account_id')]))
        ->toThrow(QueryException::class);
});

test('a DELETE of a posted line or entry fails at the database', function () {
    $entry = guardedEntry();
    $lines = DB::table('journal_lines')->where('journal_entry_id', $entry->id)->count();

    expect(fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('journal_entries')->where('id', $entry->id)->delete())->toThrow(QueryException::class);
    expect(DB::table('journal_lines')->where('journal_entry_id', $entry->id)->count())->toBe($lines)
        ->and(DB::table('journal_entries')->where('id', $entry->id)->exists())->toBeTrue();
});

test('an entry’s date, amount-bearing fields and memo can’t be edited', function (string $column, mixed $value) {
    $entry = guardedEntry();

    expect(fn () => DB::table('journal_entries')->where('id', $entry->id)->update([$column => $value]))->toThrow(QueryException::class);
})->with([
    'date' => ['date', '2020-01-01'],
    'type' => ['type', 'income'],
    'memo' => ['memo', 'Edited'],
    'company' => ['company_id', 999_999],
    'created_at' => ['created_at', '2020-01-01 00:00:00'],
]);

test('a reversal still links the two entries, and the links can’t be moved afterwards', function () {
    $entry = guardedEntry();

    $reversal = app(LedgerPoster::class)->reverse($entry);

    expect($entry->refresh()->reversed_by_id)->toBe($reversal->id)
        ->and($reversal->refresh()->reverses_id)->toBe($entry->id);

    expect(fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['reversed_by_id' => $entry->id]))->toThrow(QueryException::class);
    expect(fn () => DB::table('journal_entries')->where('id', $reversal->id)->update(['reverses_id' => null]))->toThrow(QueryException::class);
});

test('the guard lifts only inside unlocked(), and comes back afterwards', function () {
    $entry = guardedEntry();

    LedgerGuard::unlocked(fn () => DB::table('journal_lines')->where('journal_entry_id', $entry->id)->update(['debit_paise' => DB::raw('debit_paise')]));
    LedgerGuard::unlocked(fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['memo' => 'Fixed']));

    expect(DB::table('journal_entries')->where('id', $entry->id)->value('memo'))->toBe('Fixed');
    expect(fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['memo' => 'Again']))->toThrow(QueryException::class);
});

test('the guard comes back even when the unlocked work fails', function () {
    $entry = guardedEntry();

    expect(fn () => LedgerGuard::unlocked(fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);
    expect(fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['memo' => 'Again']))->toThrow(QueryException::class);
});

test('the guard can be removed and reinstalled', function () {
    // Trigger DDL commits implicitly on MySQL, so it runs on its own connection, outside the
    // test's transaction.
    config(['database.connections.guard_ddl' => config('database.connections.'.config('database.default'))]);
    $ddl = DB::connection('guard_ddl');

    try {
        LedgerGuard::uninstall($ddl);
        expect(LedgerGuard::installed($ddl))->toBeFalse();
    } finally {
        LedgerGuard::install($ddl);
    }

    expect(LedgerGuard::installed($ddl))->toBeTrue();
});
