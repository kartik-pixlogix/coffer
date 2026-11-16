<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Enums\CostType;
use App\Ledger\PartnerMetrics;
use App\Ledger\PayerLine;
use App\Ledger\Period;
use App\Ledger\Postings;
use App\Ledger\Reports;
use App\Models\Company;
use App\Models\Member;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Team\SalaryGenerator;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/** JV books with income, a personal-card cost, a side-account cost, a company cost, a salary and hours. */
function partnerBooks(): object
{
    $books = jvBooks();
    $postings = app(Postings::class);
    $income = category($books->company, 'income', 'Project');
    $software = category($books->company, 'expense', 'Software');

    $postings->incomeToAccount($books->company, fyDay(10), 10_00_000_00, $income, $books->vertexAccount, $books->aarav);
    $postings->incomeToAccount($books->company, fyDay(12), 4_00_000_00, $income, $books->bluefinAccount, $books->kabir);
    $postings->expense($books->company, fyDay(20), $software, [PayerLine::partner(30_000_00, $books->meera)]);
    $postings->expense($books->company, fyDay(21), $software, [PayerLine::company(1_00_000_00, $books->vertexAccount)]);
    $postings->expense($books->company, fyDay(22), $software, [PayerLine::company(40_000_00, $books->account)]);

    $rohan = Member::factory()->create(['partner_id' => $books->ishita->id, 'name' => 'Rohan Desai', 'cost_type' => CostType::FixedMonthly, 'rate_paise' => 50_000_00]);
    app(SalaryGenerator::class)->runMember($books->company, $rohan->fresh(), CarbonImmutable::parse('2026-05-01'));
    TimeEntry::query()->create([
        'company_id' => $books->company->id, 'member_id' => $rohan->id, 'partner_id' => $books->ishita->id,
        'project_id' => Project::query()->withoutGlobalScope('company')->where('company_id', $books->company->id)->value('id'),
        'date' => '2026-05-04', 'hours_centi' => 812_50, 'billable' => true,
    ]);

    return $books;
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('figures split into direct, shared and salary, adding up to the books', function () {
    $books = partnerBooks();
    $year = Period::fiscalYearOf($books->company, CarbonImmutable::today());
    $figures = app(PartnerMetrics::class)->forPeriod($books->company, $year);
    $pnl = app(Reports::class)->profitAndLoss($books->company, $year);

    // Vertex side account ₹1,00,000 → Aarav 60% / Ishita 40% within the side;
    // the company account's ₹40,000 → by effective equity (30% / 20% / 25% / 25%).
    expect($figures[$books->aarav->id])->toBe(['revenue' => 10_00_000_00, 'direct' => 0, 'other' => 60_000_00 + 12_000_00, 'salary' => 0, 'hoursCenti' => 0])
        ->and($figures[$books->ishita->id])->toBe(['revenue' => 0, 'direct' => 0, 'other' => 40_000_00 + 8_000_00, 'salary' => 50_000_00, 'hoursCenti' => 812_50])
        ->and($figures[$books->meera->id]['direct'])->toBe(30_000_00)
        ->and($figures[$books->kabir->id]['revenue'])->toBe(4_00_000_00)
        // Every rupee of cost lands on a partner once.
        ->and(array_sum(array_map(fn ($f) => $f['direct'] + $f['other'] + $f['salary'], $figures)))->toBe($pnl['expenses'])
        ->and(array_sum(array_column($figures, 'revenue')))->toBe($pnl['income']);
});

test('the JV page groups partners by side with the settlement pill', function () {
    $books = partnerBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->get(route('partners.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('partners/index')
            ->where('company.jv', true)
            ->where('fy.label', 'FY 2026-27')
            ->has('partners', 4)
            ->where('partners.0.name', 'Aarav Mehta')
            ->where('partners.0.netPaise', 10_00_000_00 - 72_000_00)
            ->where('partners.0.overallBps', 3000)
            ->where('partners.1.hoursCenti', 812_50)
            ->has('sides', 2)
            ->where('sides.0.name', 'Vertex Labs')
            ->where('sides.0.revenuePaise', 10_00_000_00)
            ->where('sides.0.settlement.status', fn ($status) => in_array($status, ['owes', 'owed', 'settled'], true))
            ->where('abilities.editEquity', true));
});

test('a Single company shows one grid, no sides', function () {
    $books = singleBooks();

    $this->actingAs(userWithRole(CompanyRole::Admin, $books->company))->get(route('partners.index'))
        ->assertInertia(fn (Assert $page) => $page->where('company.jv', false)->has('partners', 2)->where('sides', []));
});

test('partners see the page; members and viewers don’t', function () {
    $books = singleBooks();

    $this->actingAs(userWithRole(CompanyRole::Partner, $books->company))->get(route('partners.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('abilities.editEquity', false));
    $this->actingAs(userWithRole(CompanyRole::Member, $books->company))->get(route('partners.index'))->assertForbidden();
    $this->actingAs(userWithRole(CompanyRole::Viewer, $books->company))->get(route('partners.index'))->assertForbidden();
});

test('the seeded Saarthi books come close to the design figures', function () {
    $this->seed();
    $aarav = User::query()->where('email', 'aarav@vertexlabs.in')->firstOrFail();
    $saarthi = Company::query()->where('name', 'Saarthi Portal JV')->firstOrFail();
    $this->actingAs($aarav)->post(route('companies.switch', $saarthi));

    $this->actingAs($aarav)->get(route('partners.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('partners', fn ($partners) => collect($partners)->sum('revenuePaise') === 62_10_000_00
                && collect($partners)->sum('expensesPaise') === 43_67_650_00
                && collect($partners)->sum('hoursCenti') > 0)
            ->where('sides.0.settlement.status', 'owes')
            ->where('sides.0.settlement.amountPaise', 3_24_600_00));
});
