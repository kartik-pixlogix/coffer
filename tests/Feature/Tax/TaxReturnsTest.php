<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Enums\CompanyRole;
use App\Enums\GstRegistration;
use App\Models\AuditEvent;
use App\Models\Expense;
use App\Models\GstPayment;
use App\Models\TdsChallan;
use App\Services\Tax\TaxPayments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The Tax page (D163, D164): GSTR-1, GSTR-3B and TDS for a month, tied to the tax accounts,
| and GST payment (set-off) and TDS deposits.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    Storage::fake('local');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** October 2026 for a regular-registered Maharashtra company: four sales and three bills. */
function returnBooks($test): object
{
    $books = singleBooks(openingPaise: 20_00_000_00);
    $books->company->update(['gst_registration' => GstRegistration::Regular, 'state_code' => '27', 'gstin' => '27AAJCV1234K1Z8', 'tan' => 'PNEV01234B']);
    $books->admin = userWithRole(CompanyRole::Admin, $books->company);
    $category = category($books->company, 'income', 'Project')->id;

    $sale = fn (array $tax, string $source) => $test->actingAs($books->admin)->post(route('income.store'), [
        'source' => $source, 'amount_paise' => 1, 'received_on' => '2026-10-20', 'category_id' => $category,
        'brought_in_by' => $books->aarav->id, 'received_into' => 'account:'.$books->account->id, 'tax' => $tax,
    ])->assertSessionHasNoErrors();

    $sale(['party' => 'registered', 'gstin' => '27AABCA9999Z1Z5', 'invoice_no' => 'VL/01', 'hsn_sac' => '998314', 'rate_bps' => 1800, 'taxable_paise' => 1_00_000_00, 'tds_section' => '194J_professional'], 'Asha Retail');
    $sale(['party' => 'registered', 'gstin' => '29AABCK8888Z1Z5', 'invoice_no' => 'VL/02', 'hsn_sac' => '998314', 'rate_bps' => 1800, 'taxable_paise' => 50_000_00], 'Kiran Exports');
    $sale(['party' => 'unregistered', 'state' => '27', 'hsn_sac' => '998313', 'rate_bps' => 1800, 'taxable_paise' => 20_000_00], 'Walk-in client');
    $sale(['party' => 'foreign', 'invoice_no' => 'VL/03', 'hsn_sac' => '998314', 'rate_bps' => 1800, 'taxable_paise' => 2_00_000_00], 'Acme Inc, USA');

    $bill = function (array $tax, int $amount, int $paid, string $description) use ($test, $books): void {
        $test->actingAs($books->admin)->post(route('expenses.store'), [
            'description' => $description, 'amount_paise' => $amount, 'spent_on' => '2026-10-12',
            'category_id' => category($books->company, 'expense', 'Software')->id, 'payer_mode' => 'company',
            'payers' => [['type' => 'company', 'account_id' => $books->account->id, 'amount_paise' => $paid]], 'tax' => $tax,
        ])->assertSessionHasNoErrors();
        $expense = Expense::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->latest('id')->firstOrFail();
        $test->actingAs($books->admin)->post(route('expenses.approve', $expense))->assertSessionHasNoErrors();
    };

    $bill(['party' => 'registered', 'gstin' => '27AAACT1234B1Z5', 'rate_bps' => 1800, 'taxable_paise' => 30_000_00, 'gst_paise' => 5_400_00, 'itc' => true, 'tds_section' => '194C_other'], 35_400_00, 34_800_00, 'Design agency');
    $bill(['party' => 'foreign', 'invoice_no' => 'AWS-10', 'rate_bps' => 1800, 'taxable_paise' => 20_000_00, 'itc' => true], 20_000_00, 20_000_00, 'AWS');
    $bill(['party' => 'registered', 'gstin' => '29AAACF5555C1Z5', 'rate_bps' => 1800, 'taxable_paise' => 10_000_00, 'gst_paise' => 1_800_00, 'itc' => false], 11_800_00, 11_800_00, 'Team dinner');

    return $books;
}

/** Credits − debits (or debits − credits) on one tax role's accounts in October. */
function octoberOn(object $books, AccountRole $role, bool $credit): int
{
    $row = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
        ->where('l.company_id', $books->company->id)->where('a.role', $role->value)
        ->whereBetween('l.date', ['2026-10-01', '2026-10-31'])
        ->selectRaw('COALESCE(SUM(l.credit_paise - l.debit_paise), 0) as net')->first();

    return $credit ? (int) $row->net : -(int) $row->net;
}

test('GSTR-1 lists B2B invoices, B2C by state and rate, exports and HSN, and ties to output tax', function () {
    $books = returnBooks($this);

    $this->actingAs($books->admin)->get(route('tax.index', ['month' => '2026-10']))
        ->assertInertia(function (Assert $page) use ($books) {
            $page->component('tax/index');
            $g = $page->toArray()['props']['gstr1'];

            expect($g['b2b'])->toHaveCount(2)
                ->and($g['b2b'][0])->toMatchArray(['gstin' => '27AABCA9999Z1Z5', 'cgst' => 9_000_00, 'sgst' => 9_000_00, 'igst' => 0])
                ->and($g['b2b'][1])->toMatchArray(['stateName' => 'Karnataka', 'igst' => 9_000_00])
                ->and($g['b2c'])->toHaveCount(1)
                ->and($g['b2c'][0])->toMatchArray(['stateName' => 'Maharashtra', 'taxable' => 20_000_00, 'cgst' => 1_800_00])
                ->and($g['exports'])->toHaveCount(1)
                ->and($g['exports'][0]['igst'])->toBe(0)
                ->and(collect($g['hsn'])->firstWhere('code', '998314')['documents'])->toBe(3)
                ->and($g['totals'])->toBe(['taxable' => 3_70_000_00, 'igst' => 9_000_00, 'cgst' => 10_800_00, 'sgst' => 10_800_00]);

            // The tax on GSTR-1 is exactly what the GST output accounts took in.
            expect($g['totals']['igst'] + $g['totals']['cgst'] + $g['totals']['sgst'])->toBe(octoberOn($books, AccountRole::GstOutput, true));
        });
});

test('GSTR-3B sorts supplies and input credit, and the credit ties to the input accounts', function () {
    $books = returnBooks($this);

    $this->actingAs($books->admin)->get(route('tax.index', ['month' => '2026-10']))
        ->assertInertia(function (Assert $page) use ($books) {
            $g = $page->toArray()['props']['gstr3b'];

            expect($g['outwardTaxable'])->toBe(['taxable' => 1_70_000_00, 'igst' => 9_000_00, 'cgst' => 10_800_00, 'sgst' => 10_800_00])
                ->and($g['zeroRated']['taxable'])->toBe(2_00_000_00)
                ->and($g['inwardRcm']['igst'])->toBe(3_600_00)
                ->and($g['itcImport']['igst'])->toBe(3_600_00)
                ->and($g['itcOther'])->toBe(['taxable' => 30_000_00, 'igst' => 0, 'cgst' => 2_700_00, 'sgst' => 2_700_00])
                ->and($g['itcIneligible']['igst'])->toBe(1_800_00)
                ->and($g['itcNet']['igst'] + $g['itcNet']['cgst'] + $g['itcNet']['sgst'])->toBe(octoberOn($books, AccountRole::GstInput, false))
                ->and($g['inwardRcm']['igst'])->toBe(octoberOn($books, AccountRole::RcmPayable, true));
        });
});

test('paying GST sets off credit in the legal order, pays the rest in cash and clears the accounts', function () {
    $books = returnBooks($this);

    $this->actingAs($books->admin)->get(route('tax.index', ['month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('gstPreview.settle.cash', ['igst' => 9_000_00, 'cgst' => 8_100_00, 'sgst' => 8_100_00])
            ->where('gstPreview.settle.cashTotal', 25_200_00));

    $this->actingAs($books->admin)->post(route('tax.gst-payments.store'), [
        'period' => '2026-10', 'paid_on' => '2026-11-10', 'money_account_id' => $books->account->id, 'reference' => 'CPIN123',
    ])->assertSessionHasNoErrors();

    $after = app(TaxPayments::class)->balances($books->company, CarbonImmutable::create(2026, 11, 30));
    expect(array_sum($after['output']) + array_sum($after['rcm']) + array_sum($after['credit']))->toBe(0)
        ->and(GstPayment::query()->withoutGlobalScopes()->sole()->cash_paise)->toBe(25_200_00);

    $this->actingAs($books->admin)->get(route('tax.index', ['month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page->where('gstr3b.payment.cashPaise', 25_200_00)->where('gstPayments.0.voucher', 'GST/2026-27/0001'));

    // Nothing left to pay.
    $this->actingAs($books->admin)->post(route('tax.gst-payments.store'), [
        'period' => '2026-10', 'paid_on' => '2026-11-11', 'money_account_id' => $books->account->id,
    ])->assertSessionHasErrors('period');

    // Deleting reverses it: the tax is owed again.
    $this->actingAs($books->admin)->delete(route('tax.gst-payments.destroy', GstPayment::query()->withoutGlobalScopes()->sole()))->assertSessionHasNoErrors();
    expect(array_sum(app(TaxPayments::class)->balances($books->company, CarbonImmutable::create(2026, 11, 30))['output']))->toBe(30_600_00);
});

test('TDS shows what was deducted, deposited and owed; challans clear the payable', function () {
    $books = returnBooks($this);

    $this->actingAs($books->admin)->get(route('tax.index', ['month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tds.sections', [['section' => '194C', 'deducted' => 600_00, 'deposited' => 0, 'owed' => 600_00]])
            ->where('tds.deductees.0.pan', 'AAACT****B')
            ->where('tds.receivable.0.tds', 10_000_00));

    $this->actingAs($books->admin)->post(route('tax.tds-challans.store'), [
        'section' => '194C', 'period' => '2026-10', 'paid_on' => '2026-11-07', 'amount_paise' => 700_00, 'money_account_id' => $books->account->id,
    ])->assertSessionHasErrors('amount_paise');

    $this->actingAs($books->admin)->post(route('tax.tds-challans.store'), [
        'section' => '194C', 'period' => '2026-10', 'paid_on' => '2026-11-07', 'amount_paise' => 600_00, 'money_account_id' => $books->account->id,
        'bsr_code' => '0510032', 'challan_no' => '00451',
    ])->assertSessionHasNoErrors();

    expect(TdsChallan::query()->withoutGlobalScopes()->sole()->challan_no)->toBe('00451');
    $this->actingAs($books->admin)->get(route('tax.index', ['month' => '2026-10']))
        ->assertInertia(fn (Assert $page) => $page->where('tds.sections', [['section' => '194C', 'deducted' => 600_00, 'deposited' => 600_00, 'owed' => 0]]));
});

test('who may see the Tax page and who may record payments', function () {
    $books = returnBooks($this);
    $partner = userWithRole(CompanyRole::Partner, $books->company);

    $this->actingAs($partner)->get(route('tax.index'))->assertOk();
    $this->actingAs(userWithRole(CompanyRole::Viewer, $books->company))->get(route('tax.index'))->assertForbidden();
    $this->actingAs(userWithRole(CompanyRole::Member, $books->company))->get(route('tax.index'))->assertForbidden();

    $this->actingAs($partner)->post(route('tax.gst-payments.store'), [
        'period' => '2026-10', 'paid_on' => '2026-11-10', 'money_account_id' => $books->account->id,
    ])->assertForbidden();
});

test('the returns export to Excel and JSON for the CA, audited', function () {
    $books = returnBooks($this);

    $this->actingAs($books->admin)->get(route('tax.export', ['month' => '2026-10', 'format' => 'xlsx']))->assertOk();
    $json = $this->actingAs($books->admin)->get(route('tax.export', ['month' => '2026-10', 'format' => 'json']));
    $json->assertOk();

    expect(json_decode($json->streamedContent(), true)['gstr1']['totals']['taxable'])->toBe(3_70_000_00)
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->where('action', 'exported')->count())->toBe(2);
});
