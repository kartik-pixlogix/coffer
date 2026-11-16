<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\CostType;
use App\Ledger\FiscalYears;
use App\Ledger\PayerLine;
use App\Ledger\Postings;
use App\Models\Company;
use App\Models\Member;
use App\Models\Reimbursement;
use App\Services\Finance\PersonalCardBalances;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** JV books where Rohan (billed to Aarav) paid ₹4,200 and Kabir ₹12,980 on their own cards. */
function cardBooks(): object
{
    $books = jvBooks();
    $books->rohan = Member::factory()->for($books->company)->create(['name' => 'Rohan Desai', 'partner_id' => $books->aarav->id, 'cost_type' => CostType::Hourly, 'rate_paise' => 500_00]);
    app(Postings::class)->expense($books->company, fyDay(150), category($books->company, 'expense', 'Travel'), [PayerLine::member(4_200_00, $books->rohan)]);
    app(Postings::class)->expense($books->company, fyDay(160), category($books->company, 'expense', 'Software'), [PayerLine::partner(12_980_00, $books->kabir)]);

    return $books;
}

/** @return array<string, mixed> */
function payoutPayload(object $books, array $overrides = []): array
{
    return $overrides + [
        'payee' => 'member:'.$books->rohan->id,
        'amount_paise' => 4_200_00,
        'date' => '2026-09-11',
        'money_account_id' => $books->vertexAccount->id,
        'note' => 'NEFT 7731',
    ];
}

function payoutsOf(Company $company)
{
    return Reimbursement::query()->withoutGlobalScope('company')->where('company_id', $company->id)->get();
}

test('payouts need “Manage capital and loans”; the card shows only to those who have it', function (CompanyRole $role, bool $manage) {
    $books = cardBooks();
    $user = userWithRole($role, $books->company);

    $response = $this->actingAs($user)->post(route('expenses.reimburse'), payoutPayload($books));
    $manage ? $response->assertSessionHasNoErrors() : $response->assertForbidden();

    if ($role !== CompanyRole::Viewer) {
        $this->actingAs($user)->get(route('expenses.index'))
            ->assertInertia(fn (Assert $page) => $manage ? $page->has('cards.owed', 1) : $page->where('cards', null));
    }
})->with([
    'admin' => [CompanyRole::Admin, true],
    'partner' => [CompanyRole::Partner, false],
    'member' => [CompanyRole::Member, false],
    'viewer' => [CompanyRole::Viewer, false],
]);

test('the Expenses card lists who is owed, and a payout posts Dr reimbursements payable, Cr account', function () {
    $books = cardBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->get(route('expenses.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('cards.owed', 2)
            ->where('cards.owed.0.key', 'partner:'.$books->kabir->id)
            ->where('cards.owed.0.owedPaise', 12_980_00)
            ->where('cards.owed.0.accountId', $books->bluefinAccount->id)
            ->where('cards.owed.1.key', 'member:'.$books->rohan->id)
            ->where('cards.owed.1.billedTo', 'Aarav Mehta')
            ->where('cards.owed.1.accountId', $books->vertexAccount->id));

    $this->actingAs($admin)->post(route('expenses.reimburse'), payoutPayload($books))
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', '₹4,200 paid back to Rohan Desai');

    $payout = payoutsOf($books->company)->sole();
    $a = $books->aarav->id;
    $v = $books->vertex->id;
    $r = $books->rohan->id;

    expect(journalLines($payout->journal_entry_id))->toBe(["Reimbursements payable — Rohan Desai: Dr 420000 [p={$a} s={$v} m={$r}]", "Vertex Labs account: Cr 420000 [s={$v}]"])
        ->and(app(PersonalCardBalances::class)->owedTo($books->rohan))->toBe(0);

    $this->actingAs($admin)->get(route('expenses.index'))
        ->assertInertia(fn (Assert $page) => $page->has('cards.owed', 1)->where('cards.recent.0.name', 'Rohan Desai'));

    // Paying a partner works the same way, against their current account.
    $this->actingAs($admin)->post(route('expenses.reimburse'), payoutPayload($books, ['payee' => 'partner:'.$books->kabir->id, 'amount_paise' => 12_980_00, 'money_account_id' => $books->bluefinAccount->id]))
        ->assertSessionHasNoErrors();
    expect(app(PersonalCardBalances::class)->owed($books->company))->toBe([]);
});

test('a payout can’t exceed what is owed and needs a real payee and account', function () {
    $books = cardBooks();
    $other = cardBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->post(route('expenses.reimburse'), payoutPayload($books, ['amount_paise' => 4_200_01]))
        ->assertSessionHasErrors(['amount_paise' => 'That’s more than the ₹4,200 owed to Rohan Desai.']);
    $this->actingAs($admin)->post(route('expenses.reimburse'), payoutPayload($books, ['payee' => 'member:'.$other->rohan->id, 'money_account_id' => $other->vertexAccount->id, 'date' => 'x']))
        ->assertSessionHasErrors(['payee' => 'Pick who to pay.', 'money_account_id', 'date']);
    $this->actingAs($admin)->post(route('expenses.reimburse'), payoutPayload($books, ['payee' => 'someone']))
        ->assertSessionHasErrors(['payee' => 'Pick who to pay.']);

    expect(payoutsOf($books->company))->toHaveCount(0);
});

test('undoing a payout reverses it, and a closed year blocks both', function () {
    $books = cardBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $partner = userWithRole(CompanyRole::Partner, $books->company);
    $this->actingAs($admin)->post(route('expenses.reimburse'), payoutPayload($books));
    $payout = payoutsOf($books->company)->sole();

    $this->actingAs($partner)->delete(route('expenses.reimbursements.destroy', $payout))->assertForbidden();
    $this->actingAs($admin)->delete(route('expenses.reimbursements.destroy', $payout))
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Payout deleted');
    expect(app(PersonalCardBalances::class)->owedTo($books->rohan))->toBe(4_200_00);

    app(FiscalYears::class)->forDate($books->company, CarbonImmutable::parse('2025-12-01'))->update(['closed_at' => now()]);
    $this->actingAs($admin)->post(route('expenses.reimburse'), payoutPayload($books, ['date' => '2026-01-05']))
        ->assertSessionHasErrors(['date' => 'FY 2025-26 is closed, so nothing dated inside it can change. An Admin can reopen it in Settings.']);
});

test('another company’s payouts are not found', function () {
    $books = cardBooks();
    $other = cardBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $this->actingAs(userWithRole(CompanyRole::Admin, $other->company))->post(route('expenses.reimburse'), payoutPayload($other));

    $this->actingAs($admin)->delete(route('expenses.reimbursements.destroy', payoutsOf($other->company)->sole()))->assertNotFound();
});
