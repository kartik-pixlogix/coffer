<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Ledger\ChartOfAccounts;
use App\Ledger\OpeningBalance;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The Journal / day book (D154): Admins and Partners by default, every voucher with its
| lines, filters by type, account and search, CSV / PDF exports that are audited.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function journalBooks(): object
{
    $books = singleBooks(openingPaise: 2_50_000_00);
    app(OpeningBalance::class)->post($books->company, fyDay());
    $books->admin = userWithRole(CompanyRole::Admin, $books->company);

    return $books;
}

test('who may open the journal', function (CompanyRole $role, int $status) {
    $books = journalBooks();

    $this->actingAs(userWithRole($role, $books->company))->get(route('reports.journal'))->assertStatus($status);
})->with([
    'admin' => [CompanyRole::Admin, 200],
    'partner' => [CompanyRole::Partner, 200],
    'member' => [CompanyRole::Member, 403],
    'viewer' => [CompanyRole::Viewer, 403],
]);

test('it lists each voucher with balanced lines', function () {
    $books = journalBooks();

    $this->actingAs($books->admin)->get(route('reports.journal'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/journal')
            ->where('total', 1)
            ->where('entries.0.voucher', 'OPN/2026-27/0001')
            ->where('entries.0.typeLabel', 'Opening balance')
            ->where('entries.0.totalPaise', 2_50_000_00)
            ->has('entries.0.lines', 3)
            ->where('date.from', '2026-04-01'));
});

test('filters narrow by type, account, search and period', function () {
    $books = journalBooks();
    $capital = app(ChartOfAccounts::class)->capital($books->ishita);

    $this->actingAs($books->admin)->get(route('reports.journal', ['type' => 'income']))
        ->assertInertia(fn (Assert $page) => $page->where('total', 0));
    $this->actingAs($books->admin)->get(route('reports.journal', ['account' => $capital->id]))
        ->assertInertia(fn (Assert $page) => $page->where('total', 1)->where('account.name', 'Capital — Ishita Rao'));
    $this->actingAs($books->admin)->get(route('reports.journal', ['q' => 'OPN/2026']))
        ->assertInertia(fn (Assert $page) => $page->where('total', 1));
    $this->actingAs($books->admin)->get(route('reports.journal', ['fy' => 2026, 'from' => '2026-05-01', 'asof' => '2026-11-15']))
        ->assertInertia(fn (Assert $page) => $page->where('total', 0)->where('date.from', '2026-05-01'));
});

test('the CSV and PDF exports work and are audited', function () {
    $books = journalBooks();

    $csv = $this->actingAs($books->admin)->get(route('reports.journal.export', ['format' => 'csv']));
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('OPN/2026-27/0001')->toContain('Capital — Aarav Mehta');

    $pdf = $this->actingAs($books->admin)->get(route('reports.journal.export', ['format' => 'pdf']));
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect(AuditEvent::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->where('action', 'exported')->count())->toBe(2);
});

test('a member can’t export the journal', function () {
    $books = journalBooks();

    $this->actingAs(userWithRole(CompanyRole::Member, $books->company))
        ->get(route('reports.journal.export', ['format' => 'csv']))->assertForbidden();
});
