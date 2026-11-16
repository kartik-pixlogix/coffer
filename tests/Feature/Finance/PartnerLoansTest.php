<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\JournalType;
use App\Ledger\FiscalYears;
use App\Ledger\PayerLine;
use App\Ledger\Postings;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanRepayment;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** @return array<string, mixed> */
function loanPayload(object $books, array $overrides = []): array
{
    return $overrides + [
        'partner_id' => $books->aarav->id,
        'source' => 'account',
        'amount_paise' => 2_00_000_00,
        'date' => '2026-05-02',
        'money_account_id' => ($books->vertexAccount ?? $books->account)->id,
        'purpose' => 'salaries before Asha milestone 2',
        'instalment_paise' => 40_000_00,
    ];
}

function loansOf(Company $company)
{
    return Loan::query()->withoutGlobalScope('company')->where('company_id', $company->id)->orderBy('number')->get();
}

test('page and action access follow the matrix', function (CompanyRole $role, int $page, bool $manage) {
    $books = singleBooks();
    $user = userWithRole($role, $books->company);

    $this->actingAs($user)->get(route('partner-loans.index'))->assertStatus($page);

    $response = $this->actingAs($user)->post(route('partner-loans.store'), loanPayload($books));
    $manage ? $response->assertSessionHasNoErrors() : $response->assertForbidden();
})->with([
    'admin' => [CompanyRole::Admin, 200, true],
    'partner' => [CompanyRole::Partner, 200, false],
    'member' => [CompanyRole::Member, 403, false],
    'viewer' => [CompanyRole::Viewer, 403, false],
]);

test('record a loan and repayments: progress, next payment, auto-close and history', function () {
    $books = jvBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->post(route('partner-loans.store'), loanPayload($books))
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'L-01 recorded: ₹2,00,000 from Aarav Mehta');
    $loan = loansOf($books->company)->sole();

    expect(JournalEntry::query()->withoutGlobalScopes()->findOrFail($loan->journal_entry_id)->type)->toBe(JournalType::Loan);

    foreach (['2026-08-31', '2026-09-30', '2026-10-31'] as $day) {
        $this->actingAs($admin)->post(route('partner-loans.repay', $loan), [
            'amount_paise' => 40_000_00, 'date' => $day, 'money_account_id' => $books->vertexAccount->id,
        ])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', '₹40,000 repaid to Aarav Mehta');
    }

    $this->actingAs($admin)->get(route('partner-loans.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('loans/index')
            ->has('loans', 1)
            ->where('loans.0.code', 'L-01')
            ->where('loans.0.repaidPaise', 1_20_000_00)
            ->where('loans.0.outstandingPaise', 80_000_00)
            ->where('loans.0.next', ['amountPaise' => 40_000_00, 'date' => '2026-11-30', 'by' => false])
            ->where('loans.0.terms', '₹40,000 a month, interest-free')
            ->where('loans.0.fromAccountId', $books->vertexAccount->id)
            ->where('kpis.outstandingPaise', 80_000_00)
            ->where('kpis.borrowedPaise', 2_00_000_00)
            ->where('kpis.repaidPaise', 1_20_000_00)
            ->where('kpis.openCount', 1)
            ->where('kpis.repaymentsCount', 3)
            ->where('kpis.next.code', 'L-01')
            ->has('history', 3)
            ->where('history.0.date', '2026-10-31')
            ->where('history.0.fromName', 'Vertex Labs account'));

    // More than is outstanding is refused; paying the rest closes the loan.
    $this->actingAs($admin)->post(route('partner-loans.repay', $loan), [
        'amount_paise' => 80_000_01, 'date' => '2026-11-15', 'money_account_id' => $books->vertexAccount->id,
    ])->assertSessionHasErrors(['amount_paise' => 'That’s more than the ₹80,000 outstanding.']);

    $this->actingAs($admin)->post(route('partner-loans.repay', $loan), [
        'amount_paise' => 80_000_00, 'date' => '2026-11-15', 'money_account_id' => $books->vertexAccount->id,
    ])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'L-01 repaid in full');

    expect($loan->refresh()->closed_on?->toDateString())->toBe('2026-11-15');
    $this->actingAs($admin)->get(route('partner-loans.index'))
        ->assertInertia(fn (Assert $page) => $page->where('loans.0.closedOn', '2026-11-15')->where('loans.0.next', null)->where('kpis.openCount', 0));

    // Deleting the last repayment reopens it.
    $last = LoanRepayment::query()->withoutGlobalScope('company')->where('loan_id', $loan->id)->latest('id')->firstOrFail();
    $this->actingAs($admin)->delete(route('partner-loans.repayments.destroy', $last))->assertSessionHasNoErrors();
    expect($loan->refresh()->closed_on)->toBeNull();
});

test('a personal-card balance converts into a loan, never more than is owed', function () {
    $books = jvBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    app(Postings::class)->expense($books->company, fyDay(20), category($books->company, 'expense', 'Software'), [PayerLine::partner(18_450_00, $books->meera)]);

    $this->actingAs($admin)->get(route('partner-loans.index'))
        ->assertInertia(fn (Assert $page) => $page->where('options.partners.3.name', 'Meera Iyer')->where('options.partners.3.owedPaise', 18_450_00));

    $card = ['partner_id' => $books->meera->id, 'source' => 'card', 'money_account_id' => null, 'instalment_paise' => null, 'purpose' => 'AWS — October bill'];

    $this->actingAs($admin)->post(route('partner-loans.store'), loanPayload($books, ['amount_paise' => 20_000_00] + $card))
        ->assertSessionHasErrors(['amount_paise' => 'That’s more than the ₹18,450 owed to Meera Iyer.']);
    $this->actingAs($admin)->post(route('partner-loans.store'), loanPayload($books, ['amount_paise' => 18_450_00] + $card))
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', '₹18,450 of Meera Iyer’s card spending is now loan L-01');

    $loan = loansOf($books->company)->sole();
    $m = $books->meera->id;
    $b = $books->bluefin->id;

    expect($loan->converted)->toBeTrue()
        ->and($loan->money_account_id)->toBeNull()
        ->and(journalLines($loan->journal_entry_id))->toBe(["Partner current — Meera Iyer: Dr 1845000 [p={$m} s={$b}]", "Loans payable — Meera Iyer: Cr 1845000 [p={$m} s={$b}]"]);
});

test('validation covers every field', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->post(route('partner-loans.store'), [
        'partner_id' => 999999, 'source' => 'gift', 'amount_paise' => -5, 'date' => 'soon',
        'instalment_paise' => 0, 'due_on' => '2020-01-01', 'purpose' => str_repeat('x', 201),
    ])->assertSessionHasErrors(['partner_id', 'source' => 'Pick where the money came from.', 'amount_paise', 'date', 'instalment_paise', 'purpose']);

    $this->actingAs($admin)->post(route('partner-loans.store'), loanPayload($books, ['money_account_id' => null, 'instalment_paise' => 3_00_000_00, 'due_on' => '2026-05-01']))
        ->assertSessionHasErrors(['money_account_id' => 'Pick the account it went into.', 'instalment_paise' => 'The instalment can’t be more than the loan.', 'due_on' => 'The due date can’t be before the loan.']);

    $this->actingAs($admin)->post(route('partner-loans.store'), loanPayload($books));
    $loan = loansOf($books->company)->sole();

    $this->actingAs($admin)->post(route('partner-loans.repay', $loan), ['amount_paise' => 0, 'date' => '2026-05-01', 'money_account_id' => 0])
        ->assertSessionHasErrors(['amount_paise' => 'Enter the amount paid back.', 'money_account_id']);
    $this->actingAs($admin)->post(route('partner-loans.repay', $loan), ['amount_paise' => 100, 'date' => '2026-05-01', 'money_account_id' => $books->account->id])
        ->assertSessionHasErrors(['date' => 'A repayment can’t be dated before the loan (2 May 2026).']);
});

test('a loan with repayments can’t be deleted; one without is reversed', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $partner = userWithRole(CompanyRole::Partner, $books->company);

    $this->actingAs($admin)->post(route('partner-loans.store'), loanPayload($books));
    $this->actingAs($admin)->post(route('partner-loans.store'), loanPayload($books, ['amount_paise' => 50_000_00]));
    [$paid, $unpaid] = loansOf($books->company)->all();
    $this->actingAs($admin)->post(route('partner-loans.repay', $paid), ['amount_paise' => 100, 'date' => '2026-06-01', 'money_account_id' => $books->account->id]);

    $this->actingAs($partner)->delete(route('partner-loans.destroy', $unpaid))->assertForbidden();
    $this->actingAs($admin)->delete(route('partner-loans.destroy', $paid))->assertSessionHasErrors(['loan' => 'L-01 has repayments. Delete those first.']);
    $this->actingAs($admin)->delete(route('partner-loans.destroy', $unpaid))->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'L-02 deleted');

    expect($unpaid->refresh()->trashed())->toBeTrue()
        ->and(JournalEntry::query()->withoutGlobalScopes()->findOrFail($unpaid->journal_entry_id)->isReversed())->toBeTrue();
});

test('a closed fiscal year blocks loans and repayments dated in it', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $this->actingAs($admin)->post(route('partner-loans.store'), loanPayload($books, ['date' => '2025-12-01']));
    $loan = loansOf($books->company)->sole();

    app(FiscalYears::class)->forDate($books->company, CarbonImmutable::parse('2025-12-01'))->update(['closed_at' => now()]);
    $locked = 'FY 2025-26 is closed, so nothing dated inside it can change. An Admin can reopen it in Settings.';

    $this->actingAs($admin)->post(route('partner-loans.store'), loanPayload($books, ['date' => '2026-01-01']))->assertSessionHasErrors(['date' => $locked]);
    $this->actingAs($admin)->post(route('partner-loans.repay', $loan), ['amount_paise' => 100, 'date' => '2026-02-01', 'money_account_id' => $books->account->id])
        ->assertSessionHasErrors(['date' => $locked]);
    $this->actingAs($admin)->delete(route('partner-loans.destroy', $loan))->assertSessionHasErrors(['date' => $locked]);

    // Repaying it in the open year still works.
    $this->actingAs($admin)->post(route('partner-loans.repay', $loan), ['amount_paise' => 100, 'date' => '2026-06-01', 'money_account_id' => $books->account->id])
        ->assertSessionHasNoErrors();
});

test('another company’s loans are not found', function () {
    $books = singleBooks();
    $other = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $this->actingAs(userWithRole(CompanyRole::Admin, $other->company))->post(route('partner-loans.store'), loanPayload($other));
    $theirs = loansOf($other->company)->sole();

    $this->actingAs($admin)->post(route('partner-loans.repay', $theirs), ['amount_paise' => 100, 'date' => '2026-06-01', 'money_account_id' => $books->account->id])->assertNotFound();
    $this->actingAs($admin)->delete(route('partner-loans.destroy', $theirs))->assertNotFound();
    $this->actingAs($admin)->get(route('partner-loans.index'))->assertInertia(fn (Assert $page) => $page->has('loans', 0));
});
