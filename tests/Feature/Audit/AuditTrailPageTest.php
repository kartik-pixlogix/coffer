<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Ledger\OpeningBalance;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The Audit trail page (D153): Admins and Partners by default, filters, the chain banner,
| Verify now, and CSV / PDF exports that are themselves audited.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function auditPageBooks(): object
{
    $books = singleBooks(openingPaise: 1_00_000_00);
    app(OpeningBalance::class)->post($books->company, fyDay());
    $books->admin = userWithRole(CompanyRole::Admin, $books->company, ['name' => 'Admin User']);

    return $books;
}

test('who may open the audit trail', function (CompanyRole $role, int $status) {
    $books = auditPageBooks();

    $this->actingAs(userWithRole($role, $books->company))->get(route('audit.index'))->assertStatus($status);
})->with([
    'admin' => [CompanyRole::Admin, 200],
    'partner' => [CompanyRole::Partner, 200],
    'member' => [CompanyRole::Member, 403],
    'viewer' => [CompanyRole::Viewer, 403],
]);

test('guests are sent to sign in', function () {
    $this->get(route('audit.index'))->assertRedirect(route('login'));
});

test('the page lists the company’s events, newest first, with the chain verified', function () {
    $books = auditPageBooks();

    $this->actingAs($books->admin)->get(route('audit.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('audit/index')
            ->where('chain.ok', true)
            ->where('rows.0.action', 'posted')
            ->has('rows.0.changes')
            ->where('filters.from', '2026-10-17')
            ->where('filters.to', '2026-11-15'));
});

test('filters narrow the list by action, person and search', function () {
    $books = auditPageBooks();

    $this->actingAs($books->admin)->get(route('audit.index', ['action' => 'posted']))
        ->assertInertia(fn (Assert $page) => $page->where('total', 1)->where('rows.0.action', 'posted'));

    $this->actingAs($books->admin)->get(route('audit.index', ['q' => 'OPN/2026-27']))
        ->assertInertia(fn (Assert $page) => $page->where('total', 1));

    $this->actingAs($books->admin)->get(route('audit.index', ['user' => $books->admin->id]))
        ->assertInertia(fn (Assert $page) => $page->where('total', 0));
});

test('another company’s events never show', function () {
    $books = auditPageBooks();
    $other = auditPageBooks();

    $this->actingAs($books->admin)->get(route('audit.index'))
        ->assertInertia(fn (Assert $page) => $page->where('rows', fn ($rows) => collect($rows)->every(
            fn ($row) => AuditEvent::query()->withoutGlobalScopes()->find($row['id'])->company_id === $books->company->id,
        )));

    expect(AuditEvent::query()->withoutGlobalScopes()->where('company_id', $other->company->id)->exists())->toBeTrue();
});

test('a broken chain shows on the page, and Verify now rechecks it', function () {
    $books = auditPageBooks();
    $event = AuditEvent::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->orderBy('seq')->firstOrFail();

    $this->actingAs($books->admin)->get(route('audit.index'))->assertInertia(fn (Assert $page) => $page->where('chain.ok', true));

    tamperWithLedger(fn () => DB::table('audit_events')->where('id', $event->id)->update(['subject_label' => 'Edited']));

    $this->actingAs($books->admin)->post(route('audit.verify'))->assertRedirect();
    $this->actingAs($books->admin)->get(route('audit.index'))
        ->assertInertia(fn (Assert $page) => $page->where('chain.ok', false)->where('chain.problem', "event {$event->seq} was changed after it was written."));
});

test('the CSV export lists the events and is itself audited', function () {
    $books = auditPageBooks();

    $response = $this->actingAs($books->admin)->get(route('audit.export', ['format' => 'csv']));
    $csv = $response->streamedContent();

    $response->assertOk();
    expect($csv)->toContain('Posted')->toContain('Voucher OPN/2026-27/0001');

    $exported = AuditEvent::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->where('action', 'exported')->first();
    expect($exported)->not->toBeNull()
        ->and($exported->user_id)->toBe($books->admin->id);
});

test('the PDF export renders', function () {
    $books = auditPageBooks();

    $response = $this->actingAs($books->admin)->get(route('audit.export', ['format' => 'pdf']));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->streamedContent())->toStartWith('%PDF');
});

test('members can’t export or verify', function () {
    $books = auditPageBooks();
    $member = userWithRole(CompanyRole::Member, $books->company);

    $this->actingAs($member)->get(route('audit.export', ['format' => 'csv']))->assertForbidden();
    $this->actingAs($member)->post(route('audit.verify'))->assertForbidden();
});
