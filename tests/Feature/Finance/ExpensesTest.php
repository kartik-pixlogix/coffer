<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\CostType;
use App\Enums\ExpenseStatus;
use App\Enums\JournalType;
use App\Ledger\FiscalYears;
use App\Ledger\IntegrityCheck;
use App\Models\Company;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Member;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Team\SalaryGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    Storage::fake('local');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * A JV with an Admin, Aarav and Kabir signed in as partners, and Rohan (a member billed
 * to Aarav) signed in with the Member role.
 */
function expenseBooks(): object
{
    $books = jvBooks();
    $books->admin = userWithRole(CompanyRole::Admin, $books->company, ['name' => 'Admin User']);
    $books->aaravUser = userWithRole(CompanyRole::Partner, $books->company, ['name' => 'Aarav Mehta']);
    $books->kabirUser = userWithRole(CompanyRole::Partner, $books->company, ['name' => 'Kabir Shah']);
    $books->aarav->update(['user_id' => $books->aaravUser->id]);
    $books->kabir->update(['user_id' => $books->kabirUser->id]);
    $books->rohanUser = userWithRole(CompanyRole::Member, $books->company, ['name' => 'Rohan Desai']);
    $books->rohan = Member::factory()->create(['partner_id' => $books->aarav->id, 'name' => 'Rohan Desai', 'user_id' => $books->rohanUser->id, 'rate_paise' => 500_00]);

    return $books;
}

/** @return array<string, mixed> */
function expensePayload(object $books, array $overrides = []): array
{
    return $overrides + [
        'description' => 'Client visit — Jaipur',
        'amount_paise' => 9_800_00,
        'spent_on' => '2026-10-12',
        'category_id' => category($books->company, 'expense', 'Travel')->id,
        'payer_mode' => 'full',
        'payers' => [['type' => 'partner', 'partner_id' => $books->kabir->id, 'account_id' => null, 'amount_paise' => 9_800_00]],
    ];
}

function expensesOf(Company $company)
{
    return Expense::query()->withoutGlobalScope('company')->where('company_id', $company->id)->orderBy('id')->get();
}

function lastExpense(Company $company): Expense
{
    return Expense::query()->withoutGlobalScopes()->where('company_id', $company->id)->latest('id')->firstOrFail();
}

/** @return list<array{account: string, debit: int, credit: int, partner: int|null, side: int|null, member: int|null}> */
function expenseLines(Expense $expense): array
{
    return JournalLine::query()->withoutGlobalScopes()->with('account')->where('journal_entry_id', $expense->refresh()->journal_entry_id)->orderBy('id')->get()
        ->map(fn (JournalLine $l) => ['account' => $l->account->name, 'debit' => $l->debit_paise, 'credit' => $l->credit_paise, 'partner' => $l->partner_id, 'side' => $l->side_id, 'member' => $l->member_id])
        ->all();
}

function companyEntries(Company $company): int
{
    return JournalEntry::query()->withoutGlobalScopes()->where('company_id', $company->id)->count();
}

test('submitting saves a pending expense with its payers and posts nothing', function () {
    $books = expenseBooks();
    $before = companyEntries($books->company);

    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Client visit — Jaipur sent for approval');

    $expense = lastExpense($books->company);

    expect($expense->status)->toBe(ExpenseStatus::Pending)
        ->and($expense->submitted_by)->toBe($books->kabirUser->id)
        ->and($expense->journal_entry_id)->toBeNull()
        ->and($expense->payers)->toHaveCount(1)
        ->and(companyEntries($books->company))->toBe($before);
});

test('page and action access follow the matrix', function (string $who, int $page, bool $create) {
    $books = expenseBooks();
    $viewer = userWithRole(CompanyRole::Viewer, $books->company);
    $user = ['admin' => $books->admin, 'partner' => $books->aaravUser, 'member' => $books->rohanUser, 'viewer' => $viewer][$who];

    $this->actingAs($user)->get(route('expenses.index'))->assertStatus($page);

    $payload = $who === 'member'
        ? expensePayload($books, ['payers' => [['type' => 'member', 'member_id' => $books->rohan->id, 'account_id' => null, 'amount_paise' => 9_800_00]]])
        : expensePayload($books);
    $response = $this->actingAs($user)->post(route('expenses.store'), $payload);
    $create ? $response->assertSessionHasNoErrors() : $response->assertForbidden();
})->with([
    'admin' => ['admin', 200, true],
    'partner' => ['partner', 200, true],
    'member' => ['member', 200, true],
    'viewer' => ['viewer', 403, false],
]);

test('approving a partner-card expense posts Dr expense, Cr the partner’s current account', function () {
    $books = expenseBooks();
    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books));
    $expense = lastExpense($books->company);

    $this->actingAs($books->aaravUser)->post(route('expenses.approve', $expense))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Client visit — Jaipur approved');

    $expense->refresh();
    $entry = JournalEntry::query()->withoutGlobalScopes()->findOrFail($expense->journal_entry_id);

    expect($expense->status)->toBe(ExpenseStatus::Approved)
        ->and($expense->decided_by)->toBe($books->aaravUser->id)
        ->and($entry->type)->toBe(JournalType::Expense)
        ->and($entry->source_id)->toBe($expense->id)
        ->and(expenseLines($expense))->toBe([
            ['account' => 'Expenses — Travel', 'debit' => 9_800_00, 'credit' => 0, 'partner' => $books->kabir->id, 'side' => $books->bluefin->id, 'member' => null],
            ['account' => 'Partner current — Kabir Shah', 'debit' => 0, 'credit' => 9_800_00, 'partner' => $books->kabir->id, 'side' => $books->bluefin->id, 'member' => null],
        ]);
});

test('paid from a money account and by a member’s card post the right lines', function () {
    $books = expenseBooks();

    // The company account alone.
    $this->actingAs($books->admin)->post(route('expenses.store'), expensePayload($books, [
        'description' => 'Hostinger Business hosting (yearly)',
        'amount_paise' => 6_000_00,
        'category_id' => category($books->company, 'expense', 'Software')->id,
        'payer_mode' => 'company',
        'payers' => [['type' => 'company', 'account_id' => $books->account->id, 'amount_paise' => 0]],
    ]))->assertSessionHasNoErrors();
    $hosting = lastExpense($books->company);
    $this->actingAs($books->aaravUser)->post(route('expenses.approve', $hosting))->assertSessionHasNoErrors();

    expect(expenseLines($hosting))->toBe([
        ['account' => 'Expenses — Software', 'debit' => 6_000_00, 'credit' => 0, 'partner' => null, 'side' => null, 'member' => null],
        ['account' => 'Company account', 'debit' => 0, 'credit' => 6_000_00, 'partner' => null, 'side' => null, 'member' => null],
    ]);

    // Rohan's own card: owed back to him (reimbursements payable), cost on Aarav's line.
    $this->actingAs($books->rohanUser)->post(route('expenses.store'), expensePayload($books, [
        'description' => 'Uber to Asha Retail',
        'amount_paise' => 640_00,
        'payers' => [['type' => 'member', 'member_id' => $books->rohan->id, 'account_id' => null, 'amount_paise' => 640_00]],
    ]))->assertSessionHasNoErrors();
    $uber = lastExpense($books->company);
    $this->actingAs($books->kabirUser)->post(route('expenses.approve', $uber))->assertSessionHasNoErrors();

    expect(expenseLines($uber))->toBe([
        ['account' => 'Expenses — Travel', 'debit' => 640_00, 'credit' => 0, 'partner' => $books->aarav->id, 'side' => $books->vertex->id, 'member' => $books->rohan->id],
        ['account' => 'Reimbursements payable — Rohan Desai', 'debit' => 0, 'credit' => 640_00, 'partner' => $books->aarav->id, 'side' => $books->vertex->id, 'member' => $books->rohan->id],
    ]);

    // A partner paying from their side's account.
    $this->actingAs($books->admin)->post(route('expenses.store'), expensePayload($books, [
        'payers' => [['type' => 'partner', 'partner_id' => $books->kabir->id, 'account_id' => $books->bluefinAccount->id, 'amount_paise' => 0]],
    ]))->assertSessionHasNoErrors();
    $visit = lastExpense($books->company);
    $this->actingAs($books->aaravUser)->post(route('expenses.approve', $visit));

    expect(expenseLines($visit)[1])->toBe(['account' => 'Bluefin Infotech account', 'debit' => 0, 'credit' => 9_800_00, 'partner' => null, 'side' => $books->bluefin->id, 'member' => null]);

    expect(app(IntegrityCheck::class)->run($books->company))->toBe([]);
});

test('Half splits 50/50 with the odd paisa to the first payer; Custom lines must add up', function () {
    $books = expenseBooks();

    $this->actingAs($books->admin)->post(route('expenses.store'), expensePayload($books, [
        'description' => 'Figma organisation seats',
        'amount_paise' => 14_201,
        'payer_mode' => 'half',
        'payers' => [
            ['type' => 'partner', 'partner_id' => $books->ishita->id, 'account_id' => null, 'amount_paise' => 0],
            ['type' => 'partner', 'partner_id' => $books->kabir->id, 'account_id' => null, 'amount_paise' => 0],
        ],
    ]))->assertSessionHasNoErrors();

    expect(lastExpense($books->company)->payers->pluck('amount_paise')->all())->toBe([7_101, 7_100]);

    $custom = fn (int $a, int $b) => expensePayload($books, [
        'amount_paise' => 1_14_900_00,
        'payer_mode' => 'custom',
        'payers' => [
            ['type' => 'partner', 'partner_id' => $books->aarav->id, 'account_id' => null, 'amount_paise' => $a],
            ['type' => 'partner', 'partner_id' => $books->kabir->id, 'account_id' => null, 'amount_paise' => $b],
        ],
    ]);

    $this->actingAs($books->admin)->post(route('expenses.store'), $custom(60_000_00, 60_000_00))
        ->assertSessionHasErrors(['payers' => 'Payers add up to ₹5,100 more than the amount.']);
    $this->actingAs($books->admin)->post(route('expenses.store'), $custom(60_000_00, 50_000_00))
        ->assertSessionHasErrors(['payers' => 'Payers add up to ₹4,900 less than the amount.']);
    $this->actingAs($books->admin)->post(route('expenses.store'), $custom(60_000_00, 54_900_00))->assertSessionHasNoErrors();

    $macbook = lastExpense($books->company);
    $this->actingAs($books->admin)->post(route('expenses.approve', $macbook))->assertSessionHasNoErrors();

    expect(collect(expenseLines($macbook))->where('debit', '>', 0)->pluck('debit')->all())->toBe([60_000_00, 54_900_00]);
});

test('validation covers the form and the payer shapes', function () {
    $books = expenseBooks();
    $other = jvBooks();

    $this->actingAs($books->admin)->post(route('expenses.store'), ['payers' => []])
        ->assertSessionHasErrors(['description', 'amount_paise', 'spent_on', 'category_id', 'payer_mode', 'payers']);

    $this->actingAs($books->admin)->post(route('expenses.store'), expensePayload($books, [
        'category_id' => category($books->company, 'income', 'Project')->id,
        'billable' => true,
        'payers' => [['type' => 'partner', 'partner_id' => $other->kabir->id, 'account_id' => null, 'amount_paise' => 1]],
        'receipt' => UploadedFile::fake()->create('r.gif', 5, 'image/gif'),
    ]))->assertSessionHasErrors([
        'category_id' => 'Pick an active expense category.',
        'project_id' => 'Pick the client’s project to bill.',
        'payers.0.partner_id' => 'Pick an active partner.',
        'receipt' => 'Attach a PDF, JPG or PNG.',
    ]);

    $this->actingAs($books->admin)->post(route('expenses.store'), expensePayload($books, [
        'payers' => [['type' => 'company', 'account_id' => null, 'amount_paise' => 9_800_00]],
        'payer_mode' => 'company',
    ]))->assertSessionHasErrors(['payers.0.account_id' => 'The company pays from one of its accounts.']);

    $this->actingAs($books->admin)->post(route('expenses.store'), expensePayload($books, ['payer_mode' => 'half']))
        ->assertSessionHasErrors(['payers' => 'Half splits it between two people.']);

    expect(expensesOf($books->company))->toHaveCount(0);
});

test('partners can’t approve their own; another partner or an Admin can', function () {
    $books = expenseBooks();

    // Kabir submitted it.
    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books));
    $expense = lastExpense($books->company);
    $this->actingAs($books->kabirUser)->post(route('expenses.approve', $expense))
        ->assertSessionHasErrors(['expense' => 'You can’t approve or reject your own expense. Another partner or an Admin can.']);

    // Aarav submitted it, but it reimburses Kabir's card: still Kabir's own.
    $this->actingAs($books->aaravUser)->post(route('expenses.store'), expensePayload($books, ['description' => 'Kabir’s card']));
    $reimburses = lastExpense($books->company);
    $this->actingAs($books->kabirUser)->post(route('expenses.reject', $reimburses), ['reason' => 'x'])->assertSessionHasErrors('expense');

    $this->actingAs($books->aaravUser)->post(route('expenses.approve', $expense))->assertSessionHasNoErrors();

    // An Admin approves their own.
    $this->actingAs($books->admin)->post(route('expenses.store'), expensePayload($books, ['description' => 'Admin’s own']));
    $this->actingAs($books->admin)->post(route('expenses.approve', lastExpense($books->company)))->assertSessionHasNoErrors();
    expect(lastExpense($books->company)->status)->toBe(ExpenseStatus::Approved);

    // Members can't decide at all.
    $this->actingAs($books->rohanUser)->post(route('expenses.approve', $reimburses))->assertForbidden();
});

test('rejecting needs a reason and posts nothing; editing a rejected expense resubmits it', function () {
    $books = expenseBooks();
    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books));
    $expense = lastExpense($books->company);
    $before = companyEntries($books->company);

    $this->actingAs($books->aaravUser)->post(route('expenses.reject', $expense), ['reason' => ''])
        ->assertSessionHasErrors(['reason' => 'Say why it’s rejected.']);
    $this->actingAs($books->aaravUser)->post(route('expenses.reject', $expense), ['reason' => 'Personal travel, not the JV’s'])
        ->assertInertiaFlash('toast.message', 'Client visit — Jaipur rejected');

    $expense->refresh();
    expect($expense->status)->toBe(ExpenseStatus::Rejected)
        ->and($expense->reject_reason)->toBe('Personal travel, not the JV’s')
        ->and(companyEntries($books->company))->toBe($before);

    // Approving a rejected expense isn't possible.
    $this->actingAs($books->aaravUser)->post(route('expenses.approve', $expense))->assertSessionHasErrors('expense');

    $this->actingAs($books->kabirUser)->put(route('expenses.update', $expense), expensePayload($books, ['description' => 'Client visit — Jaipur (Asha Retail)']))
        ->assertSessionHasNoErrors();
    expect($expense->refresh()->status)->toBe(ExpenseStatus::Pending)->and($expense->reject_reason)->toBeNull();
});

test('editing an approved expense is for Admins: it reverses the entry and returns it to Pending', function () {
    $books = expenseBooks();
    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books));
    $expense = lastExpense($books->company);
    $this->actingAs($books->aaravUser)->post(route('expenses.approve', $expense));
    $entryId = $expense->refresh()->journal_entry_id;

    $this->actingAs($books->kabirUser)->put(route('expenses.update', $expense), expensePayload($books, ['amount_paise' => 10_000_00]))->assertForbidden();

    $this->actingAs($books->admin)->put(route('expenses.update', $expense), expensePayload($books, ['amount_paise' => 10_000_00, 'payers' => [['type' => 'partner', 'partner_id' => $books->kabir->id, 'account_id' => null, 'amount_paise' => 10_000_00]]]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Expense updated. Its entry was reversed and it’s back with partners for approval.');

    $expense->refresh();
    expect($expense->status)->toBe(ExpenseStatus::Pending)
        ->and($expense->journal_entry_id)->toBeNull()
        ->and($expense->amount_paise)->toBe(10_000_00)
        ->and(JournalEntry::query()->withoutGlobalScopes()->findOrFail($entryId)->isReversed())->toBeTrue();

    $this->actingAs($books->aaravUser)->post(route('expenses.approve', $expense))->assertSessionHasNoErrors();
    expect(expenseLines($expense)[0]['debit'])->toBe(10_000_00)
        ->and(app(IntegrityCheck::class)->run($books->company))->toBe([]);
});

test('deleting reverses an approved expense; pending ones just go', function () {
    $books = expenseBooks();
    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books));
    $pending = lastExpense($books->company);
    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books, ['description' => 'Second']));
    $approved = lastExpense($books->company);
    $this->actingAs($books->aaravUser)->post(route('expenses.approve', $approved));
    $deletedEntry = $approved->refresh()->journal_entry_id;

    $this->actingAs($books->kabirUser)->delete(route('expenses.destroy', $pending))->assertInertiaFlash('toast.message', 'Expense deleted');
    $this->actingAs($books->kabirUser)->delete(route('expenses.destroy', $approved))->assertForbidden();
    $this->actingAs($books->admin)->delete(route('expenses.destroy', $approved))->assertSessionHasNoErrors();

    expect(expensesOf($books->company))->toHaveCount(0)
        ->and(JournalEntry::query()->withoutGlobalScopes()->findOrFail($deletedEntry)->isReversed())->toBeTrue()
        ->and(Expense::query()->withoutGlobalScopes()->withTrashed()->where('company_id', $books->company->id)->count())->toBe(2);
});

test('bulk approve and reject skip what the user can’t decide', function () {
    $books = expenseBooks();
    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books, ['description' => 'Kabir 1']));
    $mine = lastExpense($books->company);
    $this->actingAs($books->aaravUser)->post(route('expenses.store'), expensePayload($books, ['description' => 'Aarav 1', 'payers' => [['type' => 'company', 'account_id' => $books->account->id, 'amount_paise' => 0]], 'payer_mode' => 'company']));
    $theirs = lastExpense($books->company);
    $this->actingAs($books->rohanUser)->post(route('expenses.store'), expensePayload($books, ['description' => 'Rohan 1', 'payers' => [['type' => 'member', 'member_id' => $books->rohan->id, 'account_id' => null, 'amount_paise' => 0]]]));
    $rohan = lastExpense($books->company);

    $this->actingAs($books->kabirUser)->post(route('expenses.bulk'), ['action' => 'approve', 'ids' => [$mine->id, $theirs->id]])
        ->assertInertiaFlash('toast.message', '1 expense approved. 1 skipped: your own, not pending, or in a closed year.');

    expect($mine->refresh()->status)->toBe(ExpenseStatus::Pending)->and($theirs->refresh()->status)->toBe(ExpenseStatus::Approved);

    $this->actingAs($books->kabirUser)->post(route('expenses.bulk'), ['action' => 'reject', 'ids' => [$rohan->id]])
        ->assertSessionHasErrors('reason');
    $this->actingAs($books->kabirUser)->post(route('expenses.bulk'), ['action' => 'reject', 'ids' => [$rohan->id], 'reason' => 'Duplicate'])
        ->assertInertiaFlash('toast.message', '1 expense rejected');
    expect($rohan->refresh()->reject_reason)->toBe('Duplicate');

    $this->actingAs($books->rohanUser)->post(route('expenses.bulk'), ['action' => 'approve', 'ids' => [$mine->id]])->assertForbidden();
});

test('members see and change only their own submissions, paid by themselves or the company', function () {
    $books = expenseBooks();
    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books));
    $kabirs = lastExpense($books->company);
    $this->actingAs($books->rohanUser)->post(route('expenses.store'), expensePayload($books, ['description' => 'Uber', 'payers' => [['type' => 'member', 'member_id' => $books->rohan->id, 'account_id' => null, 'amount_paise' => 0]]]))
        ->assertSessionHasNoErrors();
    $own = lastExpense($books->company);

    $this->actingAs($books->rohanUser)->get(route('expenses.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('expenses.data', 1)
            ->where('expenses.data.0.description', 'Uber')
            ->where('kpis.pendingPaise', 9_800_00)
            ->where('kpis.approvedPaise', 0)
            ->where('abilities.ownOnly', true)
            ->where('abilities.addCategory', false));

    $this->actingAs($books->rohanUser)->put(route('expenses.update', $kabirs), expensePayload($books))->assertForbidden();
    $this->actingAs($books->rohanUser)->get(route('expenses.receipt', $kabirs))->assertForbidden();
    $this->actingAs($books->rohanUser)->post(route('expenses.store'), expensePayload($books))
        ->assertSessionHasErrors(['payers' => 'You can only add expenses you or the company paid.']);
    $this->actingAs($books->rohanUser)->post(route('expenses.store'), expensePayload($books, ['new_category' => 'Snacks', 'category_id' => null]))
        ->assertSessionHasErrors(['new_category' => 'Ask an Admin or partner to add categories.']);

    $this->actingAs($books->rohanUser)->delete(route('expenses.destroy', $own))->assertSessionHasNoErrors();
});

test('“+ Add category” creates the category with the expense', function () {
    $books = expenseBooks();

    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books, ['category_id' => null, 'new_category' => 'Hosting']))
        ->assertSessionHasNoErrors();

    expect(lastExpense($books->company)->category_id)->toBe(category($books->company, 'expense', 'Hosting')->id);

    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books, ['category_id' => null, 'new_category' => 'travel']))
        ->assertSessionHasErrors(['new_category' => 'There’s already a category with this name.']);
});

test('salary runs show as approved Auto rows that can’t be touched', function () {
    $books = expenseBooks();
    $sana = Member::factory()->create(['partner_id' => $books->aarav->id, 'name' => 'Sana Qureshi', 'cost_type' => CostType::FixedMonthly, 'rate_paise' => 67_500_00, 'created_at' => '2026-04-01']);
    TimeEntry::query()->withoutGlobalScope('company')->create([
        'company_id' => $books->company->id, 'member_id' => $sana->id, 'partner_id' => $books->aarav->id,
        'project_id' => Project::query()->withoutGlobalScope('company')->where('company_id', $books->company->id)->value('id'),
        'date' => '2026-10-05', 'hours_centi' => 800, 'billable' => true,
    ]);

    app(SalaryGenerator::class)->runMonth($books->company, CarbonImmutable::parse('2026-10-01'), $books->admin);

    $auto = lastExpense($books->company);

    expect($auto->auto)->toBeTrue()
        ->and($auto->status)->toBe(ExpenseStatus::Approved)
        ->and($auto->description)->toBe('Salary — Sana Qureshi — 2026-10')
        ->and($auto->spent_on->toDateString())->toBe('2026-10-31')
        ->and($auto->category_id)->toBe(category($books->company, 'expense', 'Salary')->id)
        ->and($auto->amount_paise)->toBe(67_500_00)
        ->and(JournalEntry::query()->withoutGlobalScopes()->findOrFail($auto->journal_entry_id)->type)->toBe(JournalType::Salary)
        ->and($auto->payers->sole()->money_account_id)->toBe($books->vertexAccount->id);

    $this->actingAs($books->admin)->put(route('expenses.update', $auto), expensePayload($books))->assertForbidden();
    $this->actingAs($books->admin)->delete(route('expenses.destroy', $auto))->assertForbidden();
    $this->actingAs($books->admin)->post(route('expenses.approve', $auto))->assertSessionHasErrors('expense');

    $this->actingAs($books->admin)->get(route('expenses.index', ['month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('expenses.data.0.auto', true)
            ->where('expenses.data.0.submittedBy', null)
            ->where('expenses.data.0.can', ['edit' => false, 'delete' => false, 'decide' => false]));

    // Removing the hours removes the salary and its row.
    TimeEntry::query()->withoutGlobalScope('company')->where('member_id', $sana->id)->get()->each->delete();
    $sana->update(['rate_paise' => 0]);
    $sana->rates()->update(['rate_paise' => 0]);
    app(SalaryGenerator::class)->runMonth($books->company, CarbonImmutable::parse('2026-10-01'), $books->admin);

    expect(Expense::query()->withoutGlobalScopes()->where('auto', true)->where('company_id', $books->company->id)->count())->toBe(
        JournalEntry::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->where('type', JournalType::Salary->value)->whereNull('reversed_by_id')->whereNull('reverses_id')->count()
    );
});

test('the list filters by status, payer, category, month and search, with KPIs from the books', function () {
    $books = expenseBooks();
    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books));
    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books, [
        'description' => 'Meta ads — October',
        'amount_paise' => 25_000_00,
        'spent_on' => '2026-10-19',
        'category_id' => category($books->company, 'expense', 'Marketing')->id,
        'payers' => [['type' => 'partner', 'partner_id' => $books->meera->id, 'account_id' => null, 'amount_paise' => 0]],
    ]));
    $this->actingAs($books->aaravUser)->post(route('expenses.approve', expensesOf($books->company)->first()));

    $this->actingAs($books->admin)->get(route('expenses.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('expenses/index')
            ->where('kpis.approvedPaise', 9_800_00)
            ->where('kpis.approvedCount', 1)
            ->where('kpis.pendingPaise', 25_000_00)
            ->where('kpis.pendingCount', 1)
            ->where('statusCounts', ['all' => 2, 'pending' => 1, 'approved' => 1, 'rejected' => 0])
            ->has('expenses.data', 2)
            ->where('expenses.data.0.description', 'Meta ads — October'));

    $count = fn (array $q) => $this->actingAs($books->admin)->get(route('expenses.index', $q))->inertiaProps('expenses.data');

    expect($count(['status' => 'pending']))->toHaveCount(1)
        ->and($count(['payer' => 'partner:'.$books->meera->id]))->toHaveCount(1)
        ->and($count(['category' => category($books->company, 'expense', 'Travel')->id]))->toHaveCount(1)
        ->and($count(['month' => '2026-09']))->toHaveCount(0)
        ->and($count(['q' => 'meera']))->toHaveCount(1)
        ->and($count(['q' => 'jaipur']))->toHaveCount(1)
        ->and($count(['sort' => 'amount', 'dir' => 'asc'])[0]['amountPaise'])->toBe(9_800_00);
});

test('a closed fiscal year blocks submitting, approving and deleting', function () {
    $books = expenseBooks();
    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books, ['spent_on' => '2026-03-10']));
    $expense = lastExpense($books->company);

    app(FiscalYears::class)->forDate($books->company, CarbonImmutable::parse('2026-03-10'))->update(['closed_at' => now()]);
    $locked = 'FY 2025-26 is closed, so nothing dated inside it can change. An Admin can reopen it in Settings.';

    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books, ['spent_on' => '2026-03-11']))
        ->assertSessionHasErrors(['spent_on' => $locked]);
    $this->actingAs($books->aaravUser)->post(route('expenses.approve', $expense))->assertSessionHasErrors(['expense' => $locked]);
    $this->actingAs($books->admin)->delete(route('expenses.destroy', $expense))->assertSessionHasErrors(['expense' => $locked]);

    expect($expense->refresh()->status)->toBe(ExpenseStatus::Pending);
});

test('receipts are private, previewed inline and downloadable', function () {
    $books = expenseBooks();
    $this->actingAs($books->rohanUser)->post(route('expenses.store'), expensePayload($books, [
        'payers' => [['type' => 'member', 'member_id' => $books->rohan->id, 'account_id' => null, 'amount_paise' => 0]],
        'receipt' => UploadedFile::fake()->image('IMG_2041.jpg'),
    ]))->assertSessionHasNoErrors();

    $expense = lastExpense($books->company);
    Storage::disk('local')->assertExists($expense->receipt_path);

    $this->actingAs($books->rohanUser)->get(route('expenses.receipt', $expense))->assertOk();
    $this->actingAs($books->kabirUser)->get(route('expenses.receipt', ['expense' => $expense, 'download' => 1]))->assertOk()->assertDownload('IMG_2041.jpg');

    // The drawer's history.
    $this->actingAs($books->kabirUser)->post(route('expenses.approve', $expense));
    $this->actingAs($books->admin)->get(route('expenses.index', ['expense' => $expense->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('detail.id', $expense->id)
            ->where('detail.history.0.event', 'submitted')
            ->where('detail.history.0.by', 'Rohan Desai')
            ->where('detail.history.1.event', 'approved')
            ->where('detail.history.1.by', 'Kabir Shah'));
});

test('another company’s expense is not found', function () {
    $books = expenseBooks();
    $other = expenseBooks();
    $this->actingAs($other->kabirUser)->post(route('expenses.store'), expensePayload($other));
    $theirs = lastExpense($other->company);

    $this->actingAs($books->admin)->put(route('expenses.update', $theirs), expensePayload($books))->assertNotFound();
    $this->actingAs($books->admin)->delete(route('expenses.destroy', $theirs))->assertNotFound();
    $this->actingAs($books->admin)->post(route('expenses.approve', $theirs))->assertNotFound();
    $this->actingAs($books->admin)->post(route('expenses.reject', $theirs), ['reason' => 'x'])->assertNotFound();
    $this->actingAs($books->admin)->get(route('expenses.receipt', $theirs))->assertNotFound();
    $this->actingAs($books->admin)->post(route('expenses.bulk'), ['action' => 'approve', 'ids' => [$theirs->id]]);

    expect($theirs->refresh()->status)->toBe(ExpenseStatus::Pending);
});

test('export downloads the filtered list or the selected rows as CSV', function () {
    $books = expenseBooks();
    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books));
    $this->actingAs($books->kabirUser)->post(route('expenses.store'), expensePayload($books, ['description' => 'Other one']));

    $all = $this->actingAs($books->admin)->get(route('expenses.export'))->assertOk()->streamedContent();
    expect($all)->toContain('Date,Description,Category,"Amount (INR)"')
        ->toContain('2026-10-12,"Client visit — Jaipur",Travel,9800.00,Pending,"Kabir Shah 9800.00 (Personal card (reimburse))","Kabir Shah"');

    $one = $this->actingAs($books->admin)->get(route('expenses.export', ['ids' => [lastExpense($books->company)->id]]))->streamedContent();
    expect($one)->toContain('Other one')->not->toContain('Client visit');
});

test('users are linked to their partner colour for “Submitted by”', function () {
    $books = expenseBooks();
    $this->actingAs($books->rohanUser)->post(route('expenses.store'), expensePayload($books, ['payers' => [['type' => 'member', 'member_id' => $books->rohan->id, 'account_id' => null, 'amount_paise' => 0]]]));

    $this->actingAs($books->admin)->get(route('expenses.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('expenses.data.0.submittedBy', ['name' => 'Rohan Desai', 'colour' => 1])
            ->where('expenses.data.0.payers.0.colour', 1)
            ->where('me.memberId', null));

    expect(User::query()->count())->toBeGreaterThan(0);
});
