<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Ledger\OpeningBalance;
use App\Models\JournalLine;
use App\Notifications\LedgerIntegrityFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

test('the nightly check passes when the books balance', function () {
    Notification::fake();
    $books = singleBooks(openingPaise: 1_00_000_00);
    app(OpeningBalance::class)->post($books->company, fyDay());

    $this->artisan('coffer:ledger-integrity')->assertSuccessful();

    Notification::assertNothingSent();
});

test('a broken line fails the check and emails the company’s Admins', function () {
    Notification::fake();
    $books = singleBooks(openingPaise: 1_00_000_00);
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $entry = app(OpeningBalance::class)->post($books->company, fyDay());

    // Simulate a bug that slipped past the poster.
    JournalLine::query()->withoutGlobalScopes()->where('journal_entry_id', $entry->id)->where('debit_paise', '>', 0)->update(['debit_paise' => 99_999_00]);

    $this->artisan('coffer:ledger-integrity')->assertFailed();

    Notification::assertSentTo($admin, LedgerIntegrityFailed::class, fn ($n) => str_contains($n->failures[0], 'Invariant 1'));
});

test('the check runs every night', function () {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($e) => $e->command)
        ->filter(fn ($c) => str_contains((string) $c, 'coffer:ledger-integrity'));

    expect($events)->toHaveCount(1);
});
