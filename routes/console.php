<?php

use Illuminate\Support\Facades\Schedule;

// Spec §4.5: a nightly check that every company's books still balance; Admins hear about failures.
Schedule::command('coffer:ledger-integrity')->dailyAt('02:30')->withoutOverlapping()->onOneServer();

// Spec §8.3: salaries for the previous month, on the 1st.
Schedule::command('coffer:salaries')->monthlyOn(1, '03:00')->withoutOverlapping()->onOneServer();

// Spec §14.2: grants past their "Access ends" date are removed and audited.
Schedule::command('coffer:vault-expire-grants')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
