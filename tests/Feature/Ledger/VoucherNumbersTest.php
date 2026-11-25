<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\JournalType;
use App\Ledger\ChartOfAccounts;
use App\Ledger\EntryData;
use App\Ledger\LedgerPoster;
use App\Ledger\LineData;
use App\Ledger\Vouchers;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/*
| Voucher numbers (D154): INC/2026-27/0001, gapless per company, fiscal year and type,
| taken inside the posting transaction under a row lock.
*/

function voucherEntry(object $books, JournalType $type = JournalType::Contribution, ?CarbonImmutable $date = null): JournalEntry
{
    $chart = app(ChartOfAccounts::class);

    return app(LedgerPoster::class)->post($books->company, new EntryData(
        date: $date ?? fyDay(30),
        type: $type,
        lines: [new LineData($chart->money($books->account), 1_000_00), new LineData($chart->capital($books->aarav), 0, 1_000_00, $books->aarav->id)],
        memo: 'Test',
    ));
}

test('each type has its own series per fiscal year', function () {
    $books = singleBooks();

    expect(voucherEntry($books)->voucher_no)->toBe('CAP/2026-27/0001')
        ->and(voucherEntry($books)->voucher_no)->toBe('CAP/2026-27/0002')
        ->and(voucherEntry($books, JournalType::Drawing)->voucher_no)->toBe('DRW/2026-27/0001')
        ->and(voucherEntry($books, JournalType::Contribution, CarbonImmutable::create(2027, 4, 2))->voucher_no)->toBe('CAP/2027-28/0001')
        ->and(voucherEntry($books)->voucher_seq)->toBe(3);
});

test('each company has its own series', function () {
    $one = singleBooks();
    $two = singleBooks();

    expect(voucherEntry($one)->voucher_no)->toBe('CAP/2026-27/0001')
        ->and(voucherEntry($two)->voucher_no)->toBe('CAP/2026-27/0001');
});

test('a calendar-year company’s vouchers carry the year alone', function () {
    $books = singleBooks();
    $books->company->forceFill(['fy_start_month' => 1])->save();

    expect(voucherEntry($books, JournalType::Contribution, CarbonImmutable::create(2026, 6, 1))->voucher_no)->toBe('CAP/2026/0001');
});

test('a reversal takes the next number in its type, and its memo names the original', function () {
    $books = singleBooks();
    $entry = voucherEntry($books);

    $reversal = app(LedgerPoster::class)->reverse($entry);

    expect($reversal->voucher_no)->toBe('CAP/2026-27/0002')
        ->and($reversal->memo)->toStartWith('Reversal of CAP/2026-27/0001');
});

test('a posting that rolls back gives its number back', function () {
    $books = singleBooks();
    voucherEntry($books);

    try {
        DB::transaction(function () use ($books): void {
            voucherEntry($books);

            throw new RuntimeException('Something after the posting failed');
        });
    } catch (RuntimeException) {
    }

    expect(voucherEntry($books)->voucher_no)->toBe('CAP/2026-27/0002');
});

test('a voucher number can’t be changed once set', function () {
    $books = singleBooks();
    $entry = voucherEntry($books);

    expect(fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['voucher_no' => 'CAP/2026-27/9999']))
        ->toThrow(QueryException::class, 'posted journal rows cannot be changed');
    expect(fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['voucher_seq' => 9]))
        ->toThrow(QueryException::class);
});

test('backfill numbers entries without a voucher in posting order and continues the series', function () {
    $books = singleBooks();
    $first = voucherEntry($books);
    $second = voucherEntry($books);
    $third = voucherEntry($books, JournalType::Drawing);

    // As if they were posted before vouchers existed.
    tamperWithLedger(fn () => DB::table('journal_entries')->whereIn('id', [$first->id, $second->id, $third->id])->update(['voucher_no' => null, 'voucher_seq' => null]));
    DB::table('voucher_sequences')->where('company_id', $books->company->id)->delete();

    expect(app(Vouchers::class)->backfill($books->company))->toBe(3)
        ->and($first->refresh()->voucher_no)->toBe('CAP/2026-27/0001')
        ->and($second->refresh()->voucher_no)->toBe('CAP/2026-27/0002')
        ->and($third->refresh()->voucher_no)->toBe('DRW/2026-27/0001')
        ->and(voucherEntry($books)->voucher_no)->toBe('CAP/2026-27/0003');
});

test('the voucher is in the audit trail and on the journal page', function () {
    $books = singleBooks();
    voucherEntry($books);
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    expect(DB::table('audit_events')->where('company_id', $books->company->id)->where('action', 'posted')->value('voucher_no'))->toBe('CAP/2026-27/0001');

    $this->actingAs($admin)->get(route('reports.journal', ['fy' => 2026, 'asof' => '2027-03-31']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('reports/journal')->where('entries.0.voucher', 'CAP/2026-27/0001'));
});

test('vouchers stay gapless when separate processes post at the same time', function () {
    $php = PHP_BINARY;
    $script = base_path('tests/Support/voucher-worker.php');
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_DATABASE' => DB::connection()->getDatabaseName(),
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        'SESSION_DRIVER' => 'array',
        'VAULT_MASTER_KEY' => (string) config('vault.master_key'),
        'VAULT_FINGERPRINT_KEY' => (string) config('vault.fingerprint_key'),
    ];

    $setup = Process::env($env)->run([$php, $script, 'setup']);
    expect($setup->successful())->toBeTrue($setup->errorOutput());
    $companyId = (int) trim($setup->output());

    try {
        $pool = Process::pool(function ($pool) use ($php, $script, $env, $companyId): void {
            foreach (range(1, 4) as $i) {
                $pool->as("w{$i}")->env($env)->command([$php, $script, 'post', (string) $companyId, '10']);
            }
        })->start()->wait();

        foreach ($pool as $name => $result) {
            expect($result->successful())->toBeTrue("{$name}: ".$result->errorOutput());
        }

        // The workers committed outside this test's transaction: read them on a fresh connection.
        config(['database.connections.voucher_race' => config('database.connections.'.config('database.default'))]);
        $seqs = DB::connection('voucher_race')->table('journal_entries')
            ->where('company_id', $companyId)->where('type', JournalType::Contribution->value)
            ->orderBy('voucher_seq')->pluck('voucher_seq')->map(fn ($s) => (int) $s)->all();

        // The warm-up took 1; the four workers took 2 to 41 between them, each exactly once.
        expect($seqs)->toBe(range(1, 41));
    } finally {
        Process::env($env)->run([$php, $script, 'erase', (string) $companyId]);
        DB::purge('voucher_race');
    }
});
