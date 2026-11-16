<?php

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
