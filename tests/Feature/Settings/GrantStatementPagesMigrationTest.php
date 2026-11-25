<?php

declare(strict_types=1);

use App\Enums\CompanyRole;

/*
| The Phase 11 permission migration (D158) gives existing companies' roles the new pages
| with the spec defaults, and runs over real roles (they load their permissions eagerly).
*/

test('existing roles get the new statement pages with the defaults', function () {
    $books = singleBooks();
    $partner = userWithRole(CompanyRole::Partner, $books->company);
    $viewer = userWithRole(CompanyRole::Viewer, $books->company);
    $member = userWithRole(CompanyRole::Member, $books->company);

    foreach (['audit_trail', 'journal', 'cash_flow', 'chart_of_accounts'] as $page) {
        foreach ([CompanyRole::Partner, CompanyRole::Viewer, CompanyRole::Member] as $role) {
            revokeFromRole($books->company, $role, 'page.'.$page);
        }
    }

    $this->actingAs($partner)->get(route('reports.journal'))->assertForbidden();

    $migration = require database_path('migrations/2026_12_05_130000_grant_statement_pages.php');
    $migration->up();

    $this->actingAs($partner)->get(route('reports.journal'))->assertOk();
    $this->actingAs($partner)->get(route('audit.index'))->assertOk();
    $this->actingAs($partner)->get(route('reports.chart-of-accounts'))->assertOk();
    $this->actingAs($viewer)->get(route('reports.cash-flow'))->assertOk();
    $this->actingAs($viewer)->get(route('reports.journal'))->assertForbidden();
    $this->actingAs($member)->get(route('reports.cash-flow'))->assertForbidden();
});
