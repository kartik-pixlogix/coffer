<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use Inertia\Testing\AssertableInertia as Assert;

// A page prop called `can` would replace the shared `can` the nav rail reads, and the
// layout would crash in the browser while prop assertions still pass.
test('pages keep the shared permission props', function (string $route) {
    $books = singleBooks();

    $this->actingAs(userWithRole(CompanyRole::Admin, $books->company))
        ->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('can.pages')->has('can.actions'));
})->with(['partners.index', 'team.index', 'timesheets.index', 'timesheets.import', 'settings.projects', 'settings.categories', 'income.index', 'expenses.index']);
