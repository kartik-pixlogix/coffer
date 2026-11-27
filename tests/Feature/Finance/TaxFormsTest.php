<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\GstRegistration;
use App\Enums\TdsSection;
use App\Models\DocumentTax;
use App\Models\Expense;
use App\Models\Income;
use App\Models\JournalLine;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| GST and TDS on the expense and income forms (D162): validation, what posts, edits that
| repost, and the member's TDS on salary.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    Storage::fake('local');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** A regular-registered Maharashtra company with an Admin. */
function taxBooks(GstRegistration $registration = GstRegistration::Regular): object
{
    $books = singleBooks(openingPaise: 10_00_000_00);
    $books->company->update(['gst_registration' => $registration, 'state_code' => '27', 'gstin' => $registration === GstRegistration::Unregistered ? null : '27AAJCV1234K1Z8']);
    $books->admin = userWithRole(CompanyRole::Admin, $books->company);

    return $books;
}

/** @return array<string, mixed> a company-paid expense with tax */
function taxedExpense(object $books, array $tax, int $amount, int $paid): array
{
    return [
        'description' => 'Laptop for design',
        'amount_paise' => $amount,
        'spent_on' => '2026-10-12',
        'category_id' => category($books->company, 'expense', 'Hardware')->id,
        'payer_mode' => 'company',
        'payers' => [['type' => 'company', 'partner_id' => null, 'account_id' => $books->account->id, 'amount_paise' => $paid]],
        'tax' => $tax,
    ];
}

/** @return array<string, int> account name → debit − credit */
function postedFor(Expense|Income $document): array
{
    $out = [];

    foreach (JournalLine::query()->withoutGlobalScopes()->with('account')->where('journal_entry_id', $document->refresh()->journal_entry_id)->get() as $line) {
        $out[$line->account->name] = ($out[$line->account->name] ?? 0) + $line->debit_paise - $line->credit_paise;
    }

    ksort($out);

    return $out;
}

test('an expense with GST and TDS saves its tax and posts it when approved', function () {
    $books = taxBooks();
    $tax = ['party' => 'registered', 'gstin' => '27AAACT1234B1Z5', 'invoice_no' => 'INV-77', 'invoice_date' => '2026-10-10', 'hsn_sac' => '998314',
        'rate_bps' => 1800, 'taxable_paise' => 50_000_00, 'gst_paise' => 9_000_00, 'itc' => true, 'tds_section' => '194J_professional', 'tds_paise' => 5_000_00];

    $this->actingAs($books->admin)->post(route('expenses.store'), taxedExpense($books, $tax, 59_000_00, 54_000_00))->assertSessionHasNoErrors();
    $expense = Expense::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->latest('id')->firstOrFail();
    $row = DocumentTax::query()->withoutGlobalScopes()->where('taxable_id', $expense->id)->firstOrFail();

    expect($row->supply->value)->toBe('intra')
        ->and([$row->cgst_paise, $row->sgst_paise, $row->igst_paise])->toBe([4_500_00, 4_500_00, 0])
        ->and($row->tds_section)->toBe(TdsSection::Professional)
        ->and($row->deductee_pan)->toBe('AAACT1234B')
        ->and(DB::table('document_taxes')->where('id', $row->id)->value('party_gstin'))->toStartWith('cf1:');

    $this->actingAs($books->admin)->post(route('expenses.approve', $expense))->assertSessionHasNoErrors();

    expect(postedFor($expense))->toBe([
        'Company account' => -54_000_00,
        'Expenses — Hardware' => 50_000_00,
        'GST input — CGST' => 4_500_00,
        'GST input — SGST' => 4_500_00,
        'TDS payable — 194J' => -5_000_00,
    ]);

    $this->actingAs($books->admin)->get(route('expenses.index'))
        ->assertInertia(fn (Assert $page) => $page->where('expenses.data.0.tax.tdsPaise', 5_000_00)->where('options.tax.registered', true));
});

test('the payers must cover the amount less TDS', function () {
    $books = taxBooks();
    $tax = ['party' => 'registered', 'gstin' => '27AAACT1234B1Z5', 'rate_bps' => 1800, 'taxable_paise' => 50_000_00, 'gst_paise' => 9_000_00,
        'tds_section' => '194J_professional', 'tds_paise' => 5_000_00];
    $payload = [...taxedExpense($books, $tax, 59_000_00, 59_000_00), 'payer_mode' => 'custom'];

    $this->actingAs($books->admin)->post(route('expenses.store'), $payload)
        ->assertSessionHasErrors(['payers' => 'Payers add up to ₹5,000 more than the amount less TDS.']);
});

test('the bill’s tax is checked', function (array $tax, int $amount, string $field) {
    $books = taxBooks();

    $this->actingAs($books->admin)->post(route('expenses.store'), taxedExpense($books, $tax, $amount, $amount))->assertSessionHasErrors($field);
})->with([
    'GST not at the rate' => [['party' => 'registered', 'gstin' => '27AAACT1234B1Z5', 'rate_bps' => 1800, 'taxable_paise' => 50_000_00, 'gst_paise' => 2_500_00], 52_500_00, 'tax.gst_paise'],
    'not adding up to the amount' => [['party' => 'registered', 'gstin' => '27AAACT1234B1Z5', 'rate_bps' => 1800, 'taxable_paise' => 50_000_00, 'gst_paise' => 9_000_00], 60_000_00, 'tax.gst_paise'],
    'a bad GSTIN' => [['party' => 'registered', 'gstin' => 'NOPE', 'rate_bps' => 1800, 'taxable_paise' => 50_000_00, 'gst_paise' => 9_000_00], 59_000_00, 'tax.gstin'],
    'no rate' => [['party' => 'registered', 'gstin' => '27AAACT1234B1Z5', 'rate_bps' => 1700, 'taxable_paise' => 50_000_00, 'gst_paise' => 9_000_00], 59_000_00, 'tax.rate_bps'],
    'import not at the bill value' => [['party' => 'foreign', 'rate_bps' => 1800, 'taxable_paise' => 10_000_00], 12_000_00, 'tax.taxable_paise'],
    'TDS over the taxable value' => [['party' => 'unregistered', 'rate_bps' => 0, 'taxable_paise' => 10_000_00, 'tds_section' => '194C_individual', 'tds_paise' => 20_000_00], 10_000_00, 'tax.tds_paise'],
]);

test('a foreign service is reverse charge, at IGST', function () {
    $books = taxBooks();
    $tax = ['party' => 'foreign', 'invoice_no' => 'AWS-11', 'rate_bps' => 1800, 'taxable_paise' => 20_000_00, 'itc' => true];

    $this->actingAs($books->admin)->post(route('expenses.store'), taxedExpense($books, $tax, 20_000_00, 20_000_00))->assertSessionHasNoErrors();
    $expense = Expense::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->latest('id')->firstOrFail();
    $this->actingAs($books->admin)->post(route('expenses.approve', $expense))->assertSessionHasNoErrors();

    expect(postedFor($expense))->toBe([
        'Company account' => -20_000_00,
        'Expenses — Hardware' => 20_000_00,
        'GST input — IGST' => 3_600_00,
        'GST payable, reverse charge — IGST' => -3_600_00,
    ]);
});

test('a composition company claims no input credit', function () {
    $books = taxBooks(GstRegistration::Composition);
    $tax = ['party' => 'registered', 'gstin' => '29AAACT1234B1Z5', 'rate_bps' => 1800, 'taxable_paise' => 10_000_00, 'gst_paise' => 1_800_00, 'itc' => true];

    $this->actingAs($books->admin)->post(route('expenses.store'), taxedExpense($books, $tax, 11_800_00, 11_800_00))->assertSessionHasNoErrors();
    $expense = Expense::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->latest('id')->firstOrFail();
    $this->actingAs($books->admin)->post(route('expenses.approve', $expense))->assertSessionHasNoErrors();

    expect(DocumentTax::query()->withoutGlobalScopes()->where('taxable_id', $expense->id)->value('itc'))->toBeFalsy()
        ->and(postedFor($expense))->toBe(['Company account' => -11_800_00, 'Expenses — Hardware' => 11_800_00]);
});

test('editing an approved expense’s tax reverses it and posts the new tax on approval', function () {
    $books = taxBooks();
    $tax = ['party' => 'registered', 'gstin' => '27AAACT1234B1Z5', 'rate_bps' => 1800, 'taxable_paise' => 10_000_00, 'gst_paise' => 1_800_00, 'itc' => true];
    $this->actingAs($books->admin)->post(route('expenses.store'), taxedExpense($books, $tax, 11_800_00, 11_800_00));
    $expense = Expense::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->latest('id')->firstOrFail();
    $this->actingAs($books->admin)->post(route('expenses.approve', $expense));

    $this->actingAs($books->admin)->put(route('expenses.update', $expense), taxedExpense($books, [...$tax, 'itc' => false], 11_800_00, 11_800_00))->assertSessionHasNoErrors();
    expect($expense->refresh()->status->value)->toBe('pending');

    $this->actingAs($books->admin)->post(route('expenses.approve', $expense))->assertSessionHasNoErrors();
    expect(postedFor($expense))->toBe(['Company account' => -11_800_00, 'Expenses — Hardware' => 11_800_00]);

    // Taking the tax off removes the row.
    $this->actingAs($books->admin)->put(route('expenses.update', $expense), [...taxedExpense($books, [], 11_800_00, 11_800_00), 'tax' => null])->assertSessionHasNoErrors();
    expect(DocumentTax::query()->withoutGlobalScopes()->where('taxable_id', $expense->id)->exists())->toBeFalse();
});

/** @return array<string, mixed> */
function taxedIncome(object $books, array $tax): array
{
    return [
        'source' => 'Asha Retail — milestone 3',
        'amount_paise' => 1,
        'received_on' => '2026-10-28',
        'category_id' => category($books->company, 'income', 'Project')->id,
        'brought_in_by' => $books->aarav->id,
        'received_into' => 'account:'.$books->account->id,
        'tax' => $tax,
    ];
}

test('income with GST and TDS works out what was received and posts the tax', function () {
    $books = taxBooks();
    $tax = ['party' => 'registered', 'gstin' => '27AABCA9999Z1Z5', 'invoice_no' => 'VL/26/031', 'hsn_sac' => '998314', 'rate_bps' => 1800,
        'taxable_paise' => 1_00_000_00, 'tds_section' => '194J_professional'];

    $this->actingAs($books->admin)->post(route('income.store'), taxedIncome($books, $tax))->assertSessionHasNoErrors();
    $income = Income::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->latest('id')->firstOrFail();

    expect($income->amount_paise)->toBe(1_08_000_00)
        ->and(postedFor($income))->toBe([
            'Company account' => 1_08_000_00,
            'GST output — CGST' => -9_000_00,
            'GST output — SGST' => -9_000_00,
            'Income — Project' => -1_00_000_00,
            'TDS receivable' => 10_000_00,
        ]);

    // A change to the tax reposts.
    $this->actingAs($books->admin)->put(route('income.update', $income), taxedIncome($books, [...$tax, 'gstin' => '29AABCA9999Z1Z5']))->assertSessionHasNoErrors();
    expect(postedFor($income))->toMatchArray(['GST output — IGST' => -18_000_00, 'Income — Project' => -1_00_000_00]);
});

test('an export is zero-rated, and an unregistered company charges no GST', function () {
    $books = taxBooks();
    $this->actingAs($books->admin)->post(route('income.store'), taxedIncome($books, ['party' => 'foreign', 'rate_bps' => 1800, 'taxable_paise' => 2_00_000_00]))->assertSessionHasNoErrors();
    $export = Income::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->latest('id')->firstOrFail();

    expect($export->amount_paise)->toBe(2_00_000_00)->and(postedFor($export))->toBe(['Company account' => 2_00_000_00, 'Income — Project' => -2_00_000_00]);

    $plain = taxBooks(GstRegistration::Unregistered);
    $this->actingAs($plain->admin)->post(route('income.store'), taxedIncome($plain, ['party' => 'unregistered', 'rate_bps' => 1800, 'taxable_paise' => 50_000_00, 'tds_section' => '194C_other']))->assertSessionHasNoErrors();
    $income = Income::query()->withoutGlobalScopes()->where('company_id', $plain->company->id)->latest('id')->firstOrFail();

    expect($income->amount_paise)->toBe(49_000_00)
        ->and(postedFor($income))->toBe(['Company account' => 49_000_00, 'Income — Project' => -50_000_00, 'TDS receivable' => 1_000_00]);
});

test('a member’s TDS is saved from the team modal, and salary needs its monthly amount', function () {
    $books = taxBooks();
    $member = Member::factory()->for($books->company)->create(['partner_id' => $books->aarav->id]);
    $base = ['name' => $member->name, 'partner_id' => $books->aarav->id, 'cost_type' => $member->cost_type->value, 'rate_paise' => $member->rate_paise];

    $this->actingAs($books->admin)->put(route('team.update', $member), [...$base, 'tds_section' => '192'])->assertSessionHasErrors('tds_monthly_paise');
    $this->actingAs($books->admin)->put(route('team.update', $member), [...$base, 'tds_section' => '192', 'tds_monthly_paise' => 4_500_00])->assertSessionHasNoErrors();

    expect($member->refresh()->tds_section)->toBe(TdsSection::Salary)->and($member->tds_monthly_paise)->toBe(4_500_00);

    $this->actingAs($books->admin)->put(route('team.update', $member), [...$base, 'tds_section' => null])->assertSessionHasNoErrors();
    expect($member->refresh()->tds_section)->toBeNull()->and($member->tds_monthly_paise)->toBe(0);
});
