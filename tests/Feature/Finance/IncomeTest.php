<?php

declare(strict_types=1);

use App\Enums\AttributionMode;
use App\Enums\CompanyRole;
use App\Enums\JournalType;
use App\Ledger\FiscalYears;
use App\Models\Company;
use App\Models\Income;
use App\Models\JournalEntry;
use App\Models\JournalLine;
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

/** @return array<string, mixed> */
function incomePayload(object $books, array $overrides = []): array
{
    return $overrides + [
        'source' => 'Asha Retail — store build, milestone 3',
        'amount_paise' => 3_50_000_00,
        'received_on' => '2026-10-28',
        'category_id' => category($books->company, 'income', 'Project')->id,
        'brought_in_by' => $books->aarav->id,
        'received_into' => 'account:'.$books->account->id,
    ];
}

/** @return list<array{account: string, debit: int, credit: int, partner: int|null, side: int|null}> */
function incomeLines(JournalEntry $entry): array
{
    return JournalLine::query()->withoutGlobalScopes()->with('account')->where('journal_entry_id', $entry->id)->orderBy('id')->get()
        ->map(fn (JournalLine $l) => ['account' => $l->account->name, 'debit' => $l->debit_paise, 'credit' => $l->credit_paise, 'partner' => $l->partner_id, 'side' => $l->side_id])
        ->all();
}

function incomesOf(Company $company)
{
    return Income::query()->withoutGlobalScopes()->where('company_id', $company->id)->get();
}

test('the income page shows KPIs, trend and entries from the books', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->post(route('income.store'), incomePayload($books))->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('income.store'), incomePayload($books, ['amount_paise' => 1_20_000_00, 'received_on' => '2026-06-05', 'category_id' => category($books->company, 'income', 'Consulting')->id]));

    $this->actingAs($admin)->get(route('income.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('income/index')
            ->where('fy.label', 'FY 2026-27')
            ->where('kpis.totalPaise', 4_70_000_00)
            ->where('kpis.entries', 2)
            ->where('kpis.activeMonths', 2)
            ->has('trend', 8)
            ->where('trend.2.paise', 1_20_000_00)
            ->where('trend.6.paise', 3_50_000_00)
            ->has('incomes.data', 2)
            ->where('incomes.data.0.source', 'Asha Retail — store build, milestone 3')
            ->where('incomes.data.0.intoLabel', 'Company account')
            ->where('options.sources', ['Asha Retail — store build, milestone 3'])
            ->where('abilities.manage', true));

    // Sort by amount ascending, filter a category.
    $this->actingAs($admin)->get(route('income.index', ['sort' => 'amount', 'dir' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page->where('incomes.data.0.amountPaise', 1_20_000_00));
    $this->actingAs($admin)->get(route('income.index', ['category' => category($books->company, 'income', 'Consulting')->id]))
        ->assertInertia(fn (Assert $page) => $page->has('incomes.data', 1));
});

test('page and action access follow the matrix', function (CompanyRole $role, int $page, bool $manage) {
    $books = singleBooks();
    $user = userWithRole($role, $books->company);

    $this->actingAs($user)->get(route('income.index'))->assertStatus($page);

    $response = $this->actingAs($user)->post(route('income.store'), incomePayload($books));
    $manage ? $response->assertSessionHasNoErrors() : $response->assertForbidden();
})->with([
    'admin' => [CompanyRole::Admin, 200, true],
    'partner' => [CompanyRole::Partner, 200, true],
    'member' => [CompanyRole::Member, 403, false],
    'viewer' => [CompanyRole::Viewer, 403, false],
]);

test('income into a money account posts Dr account, Cr income with the partner who brought it in', function () {
    $books = jvBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->post(route('income.store'), incomePayload($books, [
        'brought_in_by' => $books->kabir->id,
        'received_into' => 'account:'.$books->bluefinAccount->id,
        'amount_paise' => 2_80_000_00,
    ]))->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', '₹2,80,000 added to income');

    $income = incomesOf($books->company)->sole();
    $entry = JournalEntry::query()->withoutGlobalScopes()->findOrFail($income->journal_entry_id);

    expect($entry->type)->toBe(JournalType::Income)
        ->and($entry->source_type)->toBe($income->getMorphClass())
        ->and($entry->source_id)->toBe($income->id)
        ->and(incomeLines($entry))->toBe([
            ['account' => 'Bluefin Infotech account', 'debit' => 2_80_000_00, 'credit' => 0, 'partner' => null, 'side' => $books->bluefin->id],
            ['account' => 'Income — Project', 'debit' => 0, 'credit' => 2_80_000_00, 'partner' => $books->kabir->id, 'side' => $books->bluefin->id],
        ]);
});

test('income received personally posts to the partner’s current account', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->post(route('income.store'), incomePayload($books, [
        'brought_in_by' => $books->aarav->id,
        'received_into' => 'partner:'.$books->ishita->id,
        'amount_paise' => 95_000_00,
    ]))->assertSessionHasNoErrors();

    $entry = JournalEntry::query()->withoutGlobalScopes()->findOrFail(incomesOf($books->company)->sole()->journal_entry_id);

    expect(incomeLines($entry))->toBe([
        ['account' => 'Partner current — Ishita Rao', 'debit' => 95_000_00, 'credit' => 0, 'partner' => $books->ishita->id, 'side' => null],
        ['account' => 'Income — Project', 'debit' => 0, 'credit' => 95_000_00, 'partner' => $books->aarav->id, 'side' => null],
    ]);
});

test('brought in by is required unless the company shares profit JV-pooled', function () {
    $books = jvBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $books->company->update(['attribution_mode' => AttributionMode::PerPartner]);
    $this->actingAs($admin)->post(route('income.store'), incomePayload($books, ['brought_in_by' => null]))
        ->assertSessionHasErrors(['brought_in_by' => 'Pick the partner who brought it in.']);

    $books->company->update(['attribution_mode' => AttributionMode::JvPooled]);
    $this->actingAs($admin)->post(route('income.store'), incomePayload($books, ['brought_in_by' => null]))
        ->assertSessionHasNoErrors();

    $entry = JournalEntry::query()->withoutGlobalScopes()->findOrFail(incomesOf($books->company)->sole()->journal_entry_id);
    expect(incomeLines($entry)[1]['partner'])->toBeNull();
});

test('validation covers every field', function () {
    $books = singleBooks();
    $other = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->post(route('income.store'), [])
        ->assertSessionHasErrors(['source', 'amount_paise', 'received_on', 'category_id', 'brought_in_by', 'received_into']);

    $this->actingAs($admin)->post(route('income.store'), incomePayload($books, [
        'amount_paise' => 0,
        'category_id' => category($books->company, 'expense', 'Travel')->id,
        'received_into' => 'account:'.$other->account->id,
        'brought_in_by' => $other->aarav->id,
        'attachment' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'),
    ]))->assertSessionHasErrors([
        'amount_paise' => 'Enter an amount.',
        'category_id' => 'Pick an active income category.',
        'received_into' => 'Pick where the money went.',
        'brought_in_by' => 'Pick an active partner.',
        'attachment' => 'Attach a PDF, JPG or PNG.',
    ]);

    $this->actingAs($admin)->post(route('income.store'), incomePayload($books, [
        'attachment' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'),
    ]))->assertSessionHasErrors(['attachment' => 'The file is over 10 MB.']);

    expect(incomesOf($books->company))->toHaveCount(0);
});

test('editing reverses the entry and posts a new one; notes alone don’t repost', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->post(route('income.store'), incomePayload($books));
    $income = incomesOf($books->company)->sole();
    $first = $income->journal_entry_id;

    $this->actingAs($admin)->put(route('income.update', $income), incomePayload($books, ['notes' => 'Paid by NEFT']))
        ->assertSessionHasNoErrors();
    expect($income->refresh()->journal_entry_id)->toBe($first);

    $this->actingAs($admin)->put(route('income.update', $income), incomePayload($books, ['amount_paise' => 3_00_000_00, 'received_into' => 'partner:'.$books->aarav->id]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Income updated');

    $old = JournalEntry::query()->withoutGlobalScopes()->findOrFail($first);
    $new = JournalEntry::query()->withoutGlobalScopes()->findOrFail($income->refresh()->journal_entry_id);

    expect($old->isReversed())->toBeTrue()
        ->and($new->id)->not->toBe($first)
        ->and(incomeLines($new)[0])->toBe(['account' => 'Partner current — Aarav Mehta', 'debit' => 3_00_000_00, 'credit' => 0, 'partner' => $books->aarav->id, 'side' => null])
        ->and(JournalLine::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->sum('credit_paise'))
        ->toEqual(JournalLine::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->sum('debit_paise'));

    $this->actingAs($admin)->get(route('income.index'))
        ->assertInertia(fn (Assert $page) => $page->where('kpis.totalPaise', 3_00_000_00)->where('kpis.entries', 1));
});

test('deleting reverses the entry and soft-deletes the record', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->post(route('income.store'), incomePayload($books));
    $income = incomesOf($books->company)->sole();

    $this->actingAs($admin)->delete(route('income.destroy', $income))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Income deleted');

    expect(Income::query()->withoutGlobalScopes()->withTrashed()->find($income->id)?->trashed())->toBeTrue()
        ->and(JournalEntry::query()->withoutGlobalScopes()->findOrFail($income->journal_entry_id)->isReversed())->toBeTrue();

    $this->actingAs($admin)->get(route('income.index'))
        ->assertInertia(fn (Assert $page) => $page->where('kpis.totalPaise', 0)->has('incomes.data', 0));
});

test('a closed fiscal year blocks create, edit and delete', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->post(route('income.store'), incomePayload($books, ['received_on' => '2025-12-01']))->assertSessionHasNoErrors();
    $income = incomesOf($books->company)->sole();

    app(FiscalYears::class)->forDate($books->company, CarbonImmutable::parse('2025-12-01'))->update(['closed_at' => now()]);
    $locked = 'FY 2025-26 is closed, so nothing dated inside it can change. An Admin can reopen it in Settings.';

    $this->actingAs($admin)->post(route('income.store'), incomePayload($books, ['received_on' => '2025-11-01']))
        ->assertSessionHasErrors(['received_on' => $locked]);
    $this->actingAs($admin)->put(route('income.update', $income), incomePayload($books, ['received_on' => '2026-10-01']))
        ->assertSessionHasErrors(['received_on' => $locked]);
    $this->actingAs($admin)->delete(route('income.destroy', $income))
        ->assertSessionHasErrors(['received_on' => $locked]);

    expect(incomesOf($books->company))->toHaveCount(1)
        ->and(JournalEntry::query()->withoutGlobalScopes()->findOrFail($income->journal_entry_id)->isReversed())->toBeFalse();
});

test('the invoice is stored privately and served only to people with the page', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $member = userWithRole(CompanyRole::Member, $books->company);

    $this->actingAs($admin)->post(route('income.store'), incomePayload($books, [
        'attachment' => UploadedFile::fake()->create('invoice-118.pdf', 120, 'application/pdf'),
    ]))->assertSessionHasNoErrors();

    $income = incomesOf($books->company)->sole();
    Storage::disk('local')->assertExists($income->attachment_path);
    expect($income->attachment_name)->toBe('invoice-118.pdf')
        ->and($income->attachment_path)->toStartWith("companies/{$books->company->id}/income/");

    $this->actingAs($admin)->get(route('income.attachment', $income))->assertOk();
    $this->actingAs($admin)->get(route('income.attachment', ['income' => $income, 'download' => 1]))
        ->assertOk()->assertDownload('invoice-118.pdf');
    $this->actingAs($member)->get(route('income.attachment', $income))->assertForbidden();

    // Replacing it removes the old file.
    $old = $income->attachment_path;
    $this->actingAs($admin)->put(route('income.update', $income), incomePayload($books, [
        'attachment' => UploadedFile::fake()->image('invoice.png'),
    ]))->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($old);
    Storage::disk('local')->assertExists($income->refresh()->attachment_path);
});

test('another company’s income is not found', function () {
    $books = singleBooks();
    $other = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $theirAdmin = userWithRole(CompanyRole::Admin, $other->company);

    $this->actingAs($theirAdmin)->post(route('income.store'), incomePayload($other));
    $theirs = incomesOf($other->company)->sole();

    $this->actingAs($admin)->put(route('income.update', $theirs), incomePayload($books))->assertNotFound();
    $this->actingAs($admin)->delete(route('income.destroy', $theirs))->assertNotFound();
    $this->actingAs($admin)->get(route('income.attachment', $theirs))->assertNotFound();
    $this->actingAs($admin)->get(route('income.index'))->assertInertia(fn (Assert $page) => $page->has('incomes.data', 0));
});

test('export downloads the year’s entries as CSV', function () {
    $books = singleBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $this->actingAs($admin)->post(route('income.store'), incomePayload($books));

    $response = $this->actingAs($admin)->get(route('income.export'))->assertOk();

    expect($response->streamedContent())
        ->toContain('Date,Source,"Amount (INR)"')
        ->toContain('2026-10-28,"Asha Retail — store build, milestone 3",350000.00,Project,"Aarav Mehta","Company account"');
});

test('Add income starts on the signed-in person’s own partner (P1)', function () {
    $books = jvBooks();
    $meeraUser = userWithRole(CompanyRole::Partner, $books->company, ['name' => 'Meera Iyer']);
    $books->meera->update(['user_id' => $meeraUser->id]);

    $this->actingAs($meeraUser)->get(route('income.index'))
        ->assertInertia(fn (Assert $page) => $page->where('options.myPartnerId', $books->meera->id));
});
