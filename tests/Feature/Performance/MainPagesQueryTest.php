<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/*
| Phase 9 performance pass (D92). The demo workspace has many rows per page, so a relation
| loaded inside a loop shows up here: lazy loading is off outside production
| (Model::preventLazyLoading) and the request fails, and the query count stays bounded.
*/

test('the main pages render the demo books without lazy loading and with a bounded query count', function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    $this->seed(DatabaseSeeder::class);

    $aarav = User::query()->where('email', 'aarav@vertexlabs.in')->sole();
    $saarthi = Company::query()->where('name', 'Saarthi Portal JV')->sole();

    $pages = ['dashboard', 'expenses.index', 'income.index', 'timesheets.index', 'team.index', 'partners.index',
        'capital-accounts.index', 'partner-loans.index', 'settlements.index', 'reports.index', 'reports.trial-balance',
        'reports.balance-sheet', 'vault.index', 'vault.health', 'vault.audit', 'notifications.index', 'settings.members'];

    foreach ($pages as $route) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($aarav)
            ->withSession([CurrentCompany::SESSION_KEY => $saarthi->id])
            ->get(route($route))
            ->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queries)->toBeLessThan(80, "{$route} ran {$queries} queries");
    }
});

test('the dashboard reads its ledger aggregates from the report cache on the next load (D62)', function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    $this->seed(DatabaseSeeder::class);

    $aarav = User::query()->where('email', 'aarav@vertexlabs.in')->sole();
    $saarthi = Company::query()->where('name', 'Saarthi Portal JV')->sole();

    $ledgerQueries = function () use ($aarav, $saarthi): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($aarav)->withSession([CurrentCompany::SESSION_KEY => $saarthi->id])->get(route('dashboard'))->assertOk();
        $count = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'journal_lines'))->count();
        DB::disableQueryLog();

        return $count;
    };

    $first = $ledgerQueries();
    $second = $ledgerQueries();

    expect($first)->toBeGreaterThan(0)->and($second)->toBeLessThan($first);
});
