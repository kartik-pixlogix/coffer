<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Ledger\FiscalYears;
use App\Ledger\PayerLine;
use App\Ledger\Period;
use App\Ledger\Postings;
use App\Ledger\SettlementCalculator;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\SettlementTransfer;
use App\Models\Side;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** JV books where Vertex Labs holds ₹4,00,000 more than it's entitled to. */
function settlementBooks(): object
{
    $books = jvBooks();
    $postings = app(Postings::class);
    $postings->incomeToAccount($books->company, fyDay(10), 10_00_000_00, category($books->company, 'income', 'Project'), $books->vertexAccount, $books->aarav);
    $postings->incomeToAccount($books->company, fyDay(12), 2_00_000_00, category($books->company, 'income', 'Project'), $books->bluefinAccount, $books->kabir);

    return $books;
}

/** @return array<string, mixed> */
function transferPayload(object $books, array $overrides = []): array
{
    return $overrides + [
        'from_side_id' => $books->vertex->id,
        'to_side_id' => $books->bluefin->id,
        'amount_paise' => 1_85_000_00,
        'date' => '2026-07-10',
        'reference' => 'UTR HDFC260710001834',
    ];
}

function transfersOf(Company $company)
{
    return SettlementTransfer::query()->withoutGlobalScope('company')->where('company_id', $company->id)->get();
}

test('page and action access follow the matrix', function (CompanyRole $role, int $page, bool $record) {
    $books = settlementBooks();
    $user = userWithRole($role, $books->company);

    $this->actingAs($user)->get(route('settlements.index'))->assertStatus($page);

    $response = $this->actingAs($user)->post(route('settlements.store'), transferPayload($books));
    $record ? $response->assertSessionHasNoErrors() : $response->assertForbidden();
})->with([
    'admin' => [CompanyRole::Admin, 200, true],
    'partner' => [CompanyRole::Partner, 200, false],
    'member' => [CompanyRole::Member, 403, false],
    'viewer' => [CompanyRole::Viewer, 403, false],
]);

test('the page shows each side, the banner amount and the transfers; remainders sum to zero after transfers', function () {
    $books = settlementBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    app(Postings::class)->expense($books->company, fyDay(20), category($books->company, 'expense', 'Software'), [PayerLine::partner(50_000_00, $books->meera)]);

    $this->actingAs($admin)->get(route('settlements.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settlements/index')
            ->where('company.jv', true)
            ->where('netProfitPaise', 11_50_000_00)
            ->where('sides.0.name', 'Vertex Labs')
            ->where('sides.0.collectedPaise', 10_00_000_00)
            ->where('sides.0.entitledPaise', 5_75_000_00)
            ->where('sides.0.remainingPaise', 4_25_000_00)
            ->where('sides.0.status', 'owes')
            ->where('sides.1.paidPaise', 50_000_00)
            ->where('sides.1.status', 'owed')
            ->where('suggested.0.fromName', 'Vertex Labs')
            ->where('suggested.0.amountPaise', 4_25_000_00)
            ->where('balance.currentPaise', 12_00_000_00)
            ->where('abilities.record', true));

    $this->actingAs($admin)->post(route('settlements.store'), transferPayload($books))
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Transfer of ₹1,85,000 recorded');

    $transfer = transfersOf($books->company)->sole();
    $v = $books->vertex->id;
    $b = $books->bluefin->id;
    expect(journalLines($transfer->journal_entry_id))->toBe(["Bluefin Infotech account: Dr 18500000 [s={$b}]", "Vertex Labs account: Cr 18500000 [s={$v}]"]);

    $this->actingAs($admin)->get(route('settlements.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sides.0.paidOutPaise', 1_85_000_00)
            ->where('sides.1.paidInPaise', 1_85_000_00)
            ->where('sides.0.remainingPaise', 2_40_000_00)
            ->where('transfers.0.reference', 'UTR HDFC260710001834')
            ->where('transfers.0.by', $admin->name));

    // Settle the rest; every remainder is then within ₹1 and they sum to zero.
    $this->actingAs($admin)->post(route('settlements.store'), transferPayload($books, ['amount_paise' => 2_40_000_00, 'date' => '2026-11-15']));
    $result = app(SettlementCalculator::class)->calculate($books->company, Period::fiscalYearOf($books->company, fyDay()));

    expect($result->remainderSum())->toBe(0)
        ->and(collect($result->sides)->every(fn ($s) => $s->status() === 'settled'))->toBeTrue()
        ->and($result->transfers)->toBe([]);
});

test('editing re-posts and deleting reverses a transfer', function () {
    $books = settlementBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $partner = userWithRole(CompanyRole::Partner, $books->company);
    $this->actingAs($admin)->post(route('settlements.store'), transferPayload($books));
    $transfer = transfersOf($books->company)->sole();
    $first = $transfer->journal_entry_id;

    $this->actingAs($partner)->put(route('settlements.update', $transfer), transferPayload($books))->assertForbidden();
    $this->actingAs($partner)->delete(route('settlements.destroy', $transfer))->assertForbidden();

    $this->actingAs($admin)->put(route('settlements.update', $transfer), transferPayload($books, ['amount_paise' => 2_00_000_00]))
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Transfer updated');

    expect(JournalEntry::query()->withoutGlobalScopes()->findOrFail($first)->isReversed())->toBeTrue()
        ->and($transfer->refresh()->journal_entry_id)->not->toBe($first)
        ->and($transfer->amount_paise)->toBe(2_00_000_00);

    $this->actingAs($admin)->delete(route('settlements.destroy', $transfer))
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Transfer deleted');

    expect($transfer->refresh()->trashed())->toBeTrue()
        ->and(JournalEntry::query()->withoutGlobalScopes()->findOrFail($transfer->journal_entry_id)->isReversed())->toBeTrue()
        ->and(app(SettlementCalculator::class)->calculate($books->company, Period::fiscalYearOf($books->company, fyDay()))->sides[$books->vertex->id]->remaining)->toBe(4_00_000_00);
});

test('validation: two different sides of this JV, an amount and a date', function () {
    $books = settlementBooks();
    $other = jvBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->post(route('settlements.store'), transferPayload($books, ['to_side_id' => $books->vertex->id, 'amount_paise' => 0, 'date' => '']))
        ->assertSessionHasErrors(['to_side_id' => 'From and to must be different sides.', 'amount_paise', 'date' => 'Pick a date.']);
    $this->actingAs($admin)->post(route('settlements.store'), transferPayload($books, ['from_side_id' => $other->vertex->id, 'reference' => str_repeat('x', 101)]))
        ->assertSessionHasErrors(['from_side_id' => 'Pick a side of this joint venture.', 'reference']);

    expect(transfersOf($books->company))->toHaveCount(0);
});

test('a closed fiscal year blocks recording, editing and deleting', function () {
    $books = settlementBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $this->actingAs($admin)->post(route('settlements.store'), transferPayload($books, ['date' => '2025-12-01', 'confirm_over' => true]));
    $transfer = transfersOf($books->company)->sole();

    app(FiscalYears::class)->forDate($books->company, CarbonImmutable::parse('2025-12-01'))->update(['closed_at' => now()]);
    $locked = 'FY 2025-26 is closed, so nothing dated inside it can change. An Admin can reopen it in Settings.';

    $this->actingAs($admin)->post(route('settlements.store'), transferPayload($books, ['date' => '2026-01-01', 'confirm_over' => true]))->assertSessionHasErrors(['date' => $locked]);
    $this->actingAs($admin)->put(route('settlements.update', $transfer), transferPayload($books, ['confirm_over' => true]))->assertSessionHasErrors(['date' => $locked]);
    $this->actingAs($admin)->delete(route('settlements.destroy', $transfer))->assertSessionHasErrors(['date' => $locked]);
});

test('a Single company has no Settlements page (404, as the menu hides it) and can’t record transfers (P12)', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->get(route('settlements.index'))->assertNotFound();
    $this->actingAs($admin)->post(route('settlements.store'), ['from_side_id' => 1, 'to_side_id' => 2, 'amount_paise' => 100, 'date' => '2026-11-15'])
        ->assertSessionHasErrors(['from_side_id', 'to_side_id']);
});

test('another company’s transfers are not found', function () {
    $books = settlementBooks();
    $other = settlementBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $this->actingAs(userWithRole(CompanyRole::Admin, $other->company))->post(route('settlements.store'), transferPayload($other));
    $theirs = transfersOf($other->company)->sole();

    $this->actingAs($admin)->put(route('settlements.update', $theirs), transferPayload($books))->assertNotFound();
    $this->actingAs($admin)->delete(route('settlements.destroy', $theirs))->assertNotFound();
    $this->actingAs($admin)->get(route('settlements.index'))->assertInertia(fn (Assert $page) => $page->has('transfers', 0));
    expect(Side::query()->withoutGlobalScopes()->count())->toBe(4);
});

test('a transfer above what is owed needs confirm_over (F7)', function () {
    $books = settlementBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    app(Postings::class)->expense($books->company, fyDay(20), category($books->company, 'expense', 'Software'), [PayerLine::partner(50_000_00, $books->meera)]);

    // Vertex Labs owes Bluefin ₹4,25,000.
    $this->actingAs($admin)->post(route('settlements.store'), transferPayload($books, ['amount_paise' => 5_00_000_00]))
        ->assertSessionHasErrors(['confirm_over' => 'That is more than is owed (₹4,25,000). Tick “Record anyway” to record it as it is.']);
    expect(transfersOf($books->company))->toHaveCount(0);

    $this->actingAs($admin)->post(route('settlements.store'), transferPayload($books, ['amount_paise' => 5_00_000_00, 'confirm_over' => true]))
        ->assertSessionHasNoErrors();

    // Editing counts the transfer being edited as owed again.
    $transfer = transfersOf($books->company)->sole();
    $this->actingAs($admin)->put(route('settlements.update', $transfer), transferPayload($books, ['amount_paise' => 4_00_000_00]))->assertSessionHasNoErrors();
});
