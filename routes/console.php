<?php

use App\Jobs\QueueHeartbeat;
use App\Services\Ops\Health;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Spec §4.5: a nightly check that every company's books still balance; Admins hear about failures.
Schedule::command('coffer:ledger-integrity')->dailyAt('02:30')->withoutOverlapping()->onOneServer();

// Spec §8.3: salaries for the previous month, on the 1st.
Schedule::command('coffer:salaries')->monthlyOn(1, '03:00')->withoutOverlapping()->onOneServer();

// Spec §14.2: grants past their "Access ends" date are removed and audited.
Schedule::command('coffer:vault-expire-grants')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// Spec §14.4: expiry alerts at 30, 7 and 1 days; the optional breach check (D74).
Schedule::command('coffer:vault-expiry-alerts')->dailyAt('08:00')->withoutOverlapping()->onOneServer();
Schedule::command('coffer:vault-breach-check')->weeklyOn(1, '04:00')->withoutOverlapping()->onOneServer();

// VaultAudit.dc.html: vault audit events are kept for 12 months (D75).
Schedule::command('coffer:vault-audit-prune')->monthlyOn(2, '03:30')->withoutOverlapping()->onOneServer();

// D77: unfinished vault import uploads are deleted after a day.
Schedule::command('coffer:vault-imports-clean')->hourly()->withoutOverlapping()->onOneServer();

// Spec §19 / D78, D80: settlement-due, fiscal-year-close and trial-ending reminders; 30-day retention.
Schedule::command('coffer:reminders')->dailyAt('08:30')->withoutOverlapping()->onOneServer();
Schedule::command('coffer:notifications-prune')->dailyAt('03:45')->withoutOverlapping()->onOneServer();

// D98: DPDPA erasure after the grace period, and expired data export files.
Schedule::command('coffer:privacy-purge')->dailyAt('04:30')->withoutOverlapping()->onOneServer();

// D96: the nightly encrypted backup (database + private and public storage), then retention.
Schedule::command('coffer:backup')->dailyAt('01:30')->withoutOverlapping(120)->onOneServer();
// D150: prove the newest backup restores, monthly.
Schedule::command('coffer:backup-drill')->monthlyOn(3, '02:30')->withoutOverlapping(120)->onOneServer();

// D97: heartbeats for /health. The scheduler writes its own every minute; a queued job proves a
// worker is running. Horizon's metrics need a snapshot every five minutes.
Schedule::call(fn () => Cache::forever(Health::SCHEDULER_HEARTBEAT, now()->toIso8601String()))
    ->everyMinute()->name('scheduler-heartbeat')->onOneServer();
Schedule::job(new QueueHeartbeat)->everyFiveMinutes()->name('queue-heartbeat')->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer()
    ->when(fn (): bool => config('queue.default') === 'redis');
