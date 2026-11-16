<?php

use Illuminate\Support\Facades\Schedule;

// Spec §4.5: a nightly check that every company's books still balance; Admins hear about failures.
Schedule::command('coffer:ledger-integrity')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
