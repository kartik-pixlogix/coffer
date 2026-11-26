<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\CostType;
use App\Enums\TdsSection;
use App\Ledger\Exceptions\LedgerException;
use App\Ledger\ExpenseTax;
use App\Ledger\IncomeTax;
use App\Ledger\IntegrityCheck;
use App\Ledger\LedgerPoster;
use App\Ledger\OpeningBalance;
use App\Ledger\PayerLine;
use App\Ledger\Period;
use App\Ledger\Postings;
use App\Ledger\Reports;
use App\Models\JournalLine;
use App\Models\Member;
use App\Models\SalaryRun;
use App\Services\Team\SalaryGenerator;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| GST and TDS in the ledger (D161): what each taxed expense, income and salary posts, that
| tax lines carry no side or partner, and that the books, settlements and statements still
| tie with tax on them.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** @return array<string, int> account name → debit − credit for one entry */
function taxLines(int $entryId): array
{
    $out = [];

    foreach (JournalLine::query()->withoutGlobalScopes()->with('account')->where('journal_entry_id', $entryId)->get() as $line) {
        $out[$line->account->name] = ($out[$line->account->name] ?? 0) + $line->debit_paise - $line->credit_paise;
    }

    ksort($out);

    return $out;
}

test('a forward-charge bill with input credit puts the GST in input tax', function () {
    $books = singleBooks(openingPaise: 5_00_000_00);
    $tax = new ExpenseTax(1_00_000_00, cgst: 9_000_00, sgst: 9_000_00, itc: true);

    $entry = app(Postings::class)->expense($books->company, fyDay(10), category($books->company, 'expense', 'Software'), [PayerLine::company(1_18_000_00, $books->account)], tax: $tax);

    expect(taxLines($entry->id))->toBe([
        'Company account' => -1_18_000_00,
        'Expenses — Software' => 1_00_000_00,
        'GST input — CGST' => 9_000_00,
        'GST input — SGST' => 9_000_00,
    ]);

    // Tax lines carry no side or partner: they belong to the whole firm.
    expect(JournalLine::query()->withoutGlobalScopes()->where('journal_entry_id', $entry->id)
        ->whereHas('account', fn ($q) => $q->where('role', 'gst_input'))
        ->where(fn ($q) => $q->whereNotNull('side_id')->orWhereNotNull('partner_id'))
        ->exists())->toBeFalse();
});

test('without input credit the GST is part of the cost', function () {
    $books = singleBooks();
    $tax = new ExpenseTax(10_000_00, igst: 1_800_00, itc: false);

    $entry = app(Postings::class)->expense($books->company, fyDay(10), category($books->company, 'expense', 'Travel'), [PayerLine::partner(11_800_00, $books->aarav)], tax: $tax);

    expect(taxLines($entry->id))->toBe([
        'Expenses — Travel' => 11_800_00,
        'Partner current — Aarav Mehta' => -11_800_00,
    ]);
});

test('TDS is held back from the payment and owed under its section', function () {
    $books = singleBooks(openingPaise: 5_00_000_00);
    $tax = new ExpenseTax(50_000_00, cgst: 4_500_00, sgst: 4_500_00, itc: true, tdsSection: TdsSection::Professional, tds: 5_000_00);

    $entry = app(Postings::class)->expense($books->company, fyDay(10), category($books->company, 'expense', 'Office & misc'), [PayerLine::company(54_000_00, $books->account)], tax: $tax);

    expect(taxLines($entry->id))->toBe([
        'Company account' => -54_000_00,
        'Expenses — Office & misc' => 50_000_00,
        'GST input — CGST' => 4_500_00,
        'GST input — SGST' => 4_500_00,
        'TDS payable — 194J' => -5_000_00,
    ]);
});

test('payers that don’t add up to the bill less TDS are refused', function () {
    $books = singleBooks();
    $tax = new ExpenseTax(50_000_00, cgst: 4_500_00, sgst: 4_500_00, itc: true, tdsSection: TdsSection::Professional, tds: 5_000_00);

    expect(fn () => app(Postings::class)->expense($books->company, fyDay(10), category($books->company, 'expense', 'Office & misc'), [PayerLine::company(59_000_00, $books->account)], tax: $tax))
        ->toThrow(LedgerException::class, 'bill less TDS');
});

test('reverse charge on an imported service books the GST payable and, with credit, the input', function (bool $itc, array $expected) {
    $books = singleBooks(openingPaise: 5_00_000_00);
    $tax = new ExpenseTax(20_000_00, igst: 3_600_00, reverseCharge: true, itc: $itc);

    $entry = app(Postings::class)->expense($books->company, fyDay(10), category($books->company, 'expense', 'Software'), [PayerLine::company(20_000_00, $books->account)], tax: $tax);

    expect(taxLines($entry->id))->toBe($expected);
})->with([
    'with input credit' => [true, [
        'Company account' => -20_000_00,
        'Expenses — Software' => 20_000_00,
        'GST input — IGST' => 3_600_00,
        'GST payable, reverse charge — IGST' => -3_600_00,
    ]],
    'without input credit' => [false, [
        'Company account' => -20_000_00,
        'Expenses — Software' => 23_600_00,
        'GST payable, reverse charge — IGST' => -3_600_00,
    ]],
]);

test('a split bill shares the cost by what each payer paid, keeping attribution', function () {
    $books = jvBooks(openingPaise: 4_00_000_00);
    app(OpeningBalance::class)->post($books->company, fyDay());
    $tax = new ExpenseTax(30_000_00, cgst: 2_700_00, sgst: 2_700_00, itc: true);

    $entry = app(Postings::class)->expense($books->company, fyDay(10), category($books->company, 'expense', 'Hardware'), [
        PayerLine::partner(23_600_00, $books->aarav, $books->vertexAccount),
        PayerLine::partner(11_800_00, $books->kabir, $books->bluefinAccount),
    ], tax: $tax);

    $costs = JournalLine::query()->withoutGlobalScopes()->where('journal_entry_id', $entry->id)->whereHas('account', fn ($q) => $q->where('role', 'expense'))
        ->orderBy('id')->get(['debit_paise', 'partner_id'])->map(fn ($l) => [$l->partner_id, $l->debit_paise])->all();

    expect($costs)->toBe([[$books->aarav->id, 20_000_00], [$books->kabir->id, 10_000_00]])
        // Settlement remainders still sum to zero with the GST pooled at company level.
        ->and(app(IntegrityCheck::class)->run($books->company))->toBe([]);
});

test('taxed income books GST output and TDS receivable, and income at the taxable value', function () {
    $books = singleBooks();
    $tax = new IncomeTax(1_00_000_00, cgst: 9_000_00, sgst: 9_000_00, tdsSection: TdsSection::Professional, tds: 10_000_00);

    $entry = app(Postings::class)->incomeToAccount($books->company, fyDay(20), 1_08_000_00, category($books->company, 'income', 'Project'), $books->account, $books->aarav, tax: $tax);

    expect(taxLines($entry->id))->toBe([
        'Company account' => 1_08_000_00,
        'GST output — CGST' => -9_000_00,
        'GST output — SGST' => -9_000_00,
        'Income — Project' => -1_00_000_00,
        'TDS receivable' => 10_000_00,
    ]);

    expect(fn () => app(Postings::class)->incomeToAccount($books->company, fyDay(20), 1_18_000_00, category($books->company, 'income', 'Project'), $books->account, $books->aarav, tax: $tax))
        ->toThrow(LedgerException::class, 'invoice less TDS');
});

test('income received personally can carry tax too', function () {
    $books = singleBooks();
    $tax = new IncomeTax(40_000_00, igst: 7_200_00);

    $entry = app(Postings::class)->incomeToPartner($books->company, fyDay(20), 47_200_00, category($books->company, 'income', 'Consulting'), $books->ishita, tax: $tax);

    expect(taxLines($entry->id))->toBe([
        'GST output — IGST' => -7_200_00,
        'Income — Consulting' => -40_000_00,
        'Partner current — Ishita Rao' => 47_200_00,
    ]);
});

test('salary TDS is held back and owed under 192, and the run notices a change', function () {
    $books = singleBooks(openingPaise: 10_00_000_00);
    $member = Member::factory()->for($books->company)->create([
        'partner_id' => $books->aarav->id, 'cost_type' => CostType::FixedMonthly, 'rate_paise' => 60_000_00,
        'tds_section' => TdsSection::Salary, 'tds_monthly_paise' => 4_500_00,
    ]);
    $generator = app(SalaryGenerator::class);

    expect($generator->runMember($books->company, $member, CarbonImmutable::create(2026, 9, 1)))->toBe('posted');
    $run = SalaryRun::query()->withoutGlobalScopes()->where('member_id', $member->id)->sole();

    expect(taxLines($run->journal_entry_id))->toBe([
        'Company account' => -55_500_00,
        'Expenses — Salary' => 60_000_00,
        'TDS payable — 192' => -4_500_00,
    ])->and($run->tds_paise)->toBe(4_500_00);

    $member->update(['tds_monthly_paise' => 5_000_00]);
    expect($generator->runMember($books->company, $member, CarbonImmutable::create(2026, 9, 1)))->toBe('updated');
});

test('a contractor’s pay has TDS at the section’s rate', function () {
    $member = new Member(['tds_section' => TdsSection::ContractorIndividual, 'tds_monthly_paise' => 0]);

    expect($member->salaryTds(50_000_00))->toBe(500_00)
        ->and((new Member(['tds_section' => null]))->salaryTds(50_000_00))->toBe(0)
        ->and((new Member(['tds_section' => TdsSection::Salary, 'tds_monthly_paise' => 90_000_00]))->salaryTds(50_000_00))->toBe(50_000_00);
});

test('reversing a taxed entry unwinds every line', function () {
    $books = singleBooks(openingPaise: 5_00_000_00);
    $tax = new ExpenseTax(50_000_00, cgst: 4_500_00, sgst: 4_500_00, itc: true, tdsSection: TdsSection::Professional, tds: 5_000_00);
    $entry = app(Postings::class)->expense($books->company, fyDay(10), category($books->company, 'expense', 'Office & misc'), [PayerLine::company(54_000_00, $books->account)], tax: $tax);

    $reversal = app(LedgerPoster::class)->reverse($entry);

    $net = [];
    foreach ([$entry->id, $reversal->id] as $id) {
        foreach (taxLines($id) as $account => $amount) {
            $net[$account] = ($net[$account] ?? 0) + $amount;
        }
    }

    expect(array_unique(array_values($net)))->toBe([0]);
});

test('the statements, figures and dashboard place tax balances and still tie', function () {
    $books = singleBooks(openingPaise: 5_00_000_00);
    $p = app(Postings::class);
    app(OpeningBalance::class)->post($books->company, fyDay());
    $p->incomeToAccount($books->company, fyDay(20), 1_08_000_00, category($books->company, 'income', 'Project'), $books->account, $books->aarav,
        tax: new IncomeTax(1_00_000_00, cgst: 9_000_00, sgst: 9_000_00, tdsSection: TdsSection::Professional, tds: 10_000_00));
    $p->expense($books->company, fyDay(25), category($books->company, 'expense', 'Software'), [PayerLine::company(20_000_00, $books->account)],
        tax: new ExpenseTax(20_000_00, igst: 3_600_00, reverseCharge: true, itc: true));

    $figures = app(Reports::class)->figures($books->company, Period::between(fyDay(), fyDay(200)));
    $sheet = app(Reports::class)->balanceSheet($books->company, fyDay(200));

    // Cash rose by 1,08,000 − 20,000; the income and expense moved 80,000 of it; 8,000 is tax:
    // 18,000 output owed, less 10,000 TDS to claim back (the reverse-charge pair nets to zero).
    expect($figures['taxes'])->toBe(8_000_00)
        ->and($figures['capitalAndLoans'])->toBe($figures['capital'] + $figures['personal'] + $figures['taxes'])
        ->and($sheet['drift'])->toBe(0)
        ->and(collect($sheet['assets'])->pluck('name')->all())->toContain('TDS receivable', 'GST input — IGST')
        ->and(collect($sheet['liabilities'])->pluck('name')->all())->toContain('GST output — CGST', 'GST payable, reverse charge — IGST')
        ->and(app(IntegrityCheck::class)->run($books->company))->toBe([]);

    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('dashboard.balance.otherPaise', 0)->where('dashboard.balance.taxesPaise', 8_000_00));
    $this->actingAs($admin)->get(route('reports.cash-flow'))
        ->assertInertia(fn (Assert $page) => $page->where('flow.reconciled', true));
    $this->actingAs($admin)->get(route('reports.balance-sheet', ['layout' => 'schedule3']))
        ->assertInertia(fn (Assert $page) => $page->where('schedule.totalAssets', $sheet['totalAssets']));
    $this->actingAs($admin)->get(route('reports.chart-of-accounts'))
        ->assertInertia(fn (Assert $page) => $page->where('groups', fn ($groups) => collect($groups)->firstWhere('key', 'taxes')['accounts'] !== []));
});
