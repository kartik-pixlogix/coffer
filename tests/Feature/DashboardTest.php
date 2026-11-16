<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Ledger\OpeningBalance;
use App\Ledger\PayerLine;
use App\Ledger\Postings;
use App\Ledger\ReportCache;
use App\Models\Expense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** Vertex Labs: opening ₹1,00,000, ₹6,00,000 income, ₹1,50,000 expenses, a ₹50,000 contribution. */
function dashboardBooks(): object
{
    $books = singleBooks(openingPaise: 1_00_000_00);
    $p = app(Postings::class);
    app(OpeningBalance::class)->post($books->company, fyDay());
    $p->incomeToAccount($books->company, fyDay(15), 4_00_000_00, category($books->company, 'income', 'Project'), $books->account, $books->aarav);
    $p->incomeToAccount($books->company, fyDay(100), 2_00_000_00, category($books->company, 'income', 'Consulting'), $books->account, $books->ishita);
    $p->expense($books->company, fyDay(20), category($books->company, 'expense', 'Software'), [PayerLine::company(1_00_000_00, $books->account)]);
    $p->expense($books->company, fyDay(95), category($books->company, 'expense', 'Travel'), [PayerLine::partner(50_000_00, $books->ishita)]);
    $p->contribution($books->company, fyDay(30), $books->aarav, 50_000_00, $books->account);

    return $books;
}

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('admins, partners and viewers can open the dashboard', function (CompanyRole $role) {
    $this->actingAs(userWithRole($role))->get(route('dashboard'))->assertOk();
})->with([CompanyRole::Admin, CompanyRole::Partner, CompanyRole::Viewer]);

test('members can’t open the dashboard (spec §2.1)', function () {
    $this->actingAs(userWithRole(CompanyRole::Member))->get(route('dashboard'))->assertForbidden();
});

test('the dashboard renders inside the coffer shell with the user', function () {
    $user = userWithRole(CompanyRole::Admin, attributes: ['name' => 'Aarav Mehta']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('auth.user.name', 'Aarav Mehta')
            ->where('auth.user.theme', null)
            ->where('currentCompany.id', companyOf($user)->id)
            ->has('can.pages'));
});

test('unverified users cannot reach the dashboard', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

test('a new company sees the empty state', function () {
    $books = singleBooks();

    $this->actingAs(userWithRole(CompanyRole::Admin, $books->company))->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('dashboard.empty', true)->where('vault', null));
});

test('KPIs, the balance hero and its formula strip, and the charts for the year to date', function () {
    $books = dashboardBooks();

    $this->actingAs(userWithRole(CompanyRole::Partner, $books->company))->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.preset', 'fy')
            ->where('dashboard.empty', false)
            ->where('dashboard.kpis.income.paise', 6_00_000_00)
            ->where('dashboard.kpis.income.previous', 0)
            ->where('dashboard.kpis.expenses.paise', 1_50_000_00)
            ->where('dashboard.kpis.other.paise', 1_50_000_00)
            ->where('dashboard.kpis.net.paise', 4_50_000_00)
            ->where('dashboard.kpis.hours.centi', 0)
            ->where('dashboard.kpis.pending.count', 0)
            // ₹1,00,000 opening + ₹6,00,000 − ₹1,50,000 + ₹50,000 capital + ₹50,000 on Ishita's card.
            ->where('dashboard.balance.currentPaise', 6_50_000_00)
            ->where('dashboard.balance.openingPaise', 1_00_000_00)
            ->where('dashboard.balance.capitalPaise', 50_000_00)
            ->where('dashboard.balance.personalPaise', 50_000_00)
            ->where('dashboard.balance.otherPaise', 0)
            ->where('dashboard.balance.latestMonth.month', '2026-07')
            ->where('dashboard.balance.latestMonth.netPaise', 1_50_000_00)
            // Apr–Oct; November has nothing yet, so it's left off.
            ->has('dashboard.months', 7)
            ->where('dashboard.months.0.month', '2026-04')
            ->where('dashboard.months.0.net', 3_00_000_00)
            ->where('dashboard.selectedMonth', 6)
            ->where('dashboard.revenueByPartner.0.name', 'Aarav Mehta')
            ->where('dashboard.revenueByPartner.0.paise', 4_00_000_00)
            ->where('dashboard.expensesByCategory.0.name', 'Software')
            ->where('dashboard.settlement', null)
            ->where('vault', null)
            ->where('abilities.createCompany', false));
});

test('a quarter or a month compares with the period before', function () {
    $books = dashboardBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->get(route('dashboard', ['period' => 'q2']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.preset', 'q2')
            ->where('period.comparedTo', 'Q1')
            ->where('dashboard.kpis.income.paise', 2_00_000_00)
            ->where('dashboard.kpis.income.previous', 4_00_000_00)
            ->where('dashboard.kpis.expenses.paise', 50_000_00)
            ->where('dashboard.kpis.expenses.previous', 1_00_000_00));

    $this->actingAs($admin)->get(route('dashboard', ['period' => 'month', 'month' => '2026-05']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.comparedTo', 'April')
            ->where('dashboard.kpis.income.paise', 0)
            ->where('dashboard.kpis.income.previous', 4_00_000_00));
});

test('the JV alert says who owes whom and hides once every side is settled', function () {
    $books = jvBooks();
    $p = app(Postings::class);
    $p->incomeToAccount($books->company, fyDay(10), 10_00_000_00, category($books->company, 'income', 'Project'), $books->vertexAccount, $books->aarav);
    $p->incomeToAccount($books->company, fyDay(12), 2_00_000_00, category($books->company, 'income', 'Project'), $books->bluefinAccount, $books->kabir);
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.settlement.transfers.0.fromName', 'Vertex Labs')
            ->where('dashboard.settlement.transfers.0.toName', 'Bluefin Infotech')
            ->where('dashboard.settlement.transfers.0.amountPaise', 4_00_000_00)
            ->where('dashboard.settlement.shares', '50/50')
            ->where('dashboard.settlement.fromEntitledPaise', 6_00_000_00)
            ->where('dashboard.settlement.fromHoldsPaise', 10_00_000_00));

    $p->settlementTransfer($books->company, fyDay(40), $books->vertex, $books->bluefin, 4_00_000_00);

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('dashboard.settlement', null));
});

test('pending approvals list the oldest five with approve and reject for those who can decide', function () {
    $books = jvBooks();
    $kabirUser = userWithRole(CompanyRole::Partner, $books->company);
    $books->kabir->update(['user_id' => $kabirUser->id]);
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $viewer = userWithRole(CompanyRole::Viewer, $books->company);

    foreach (range(1, 6) as $i) {
        $this->actingAs($kabirUser)->post(route('expenses.store'), [
            'description' => "Meta ads {$i}",
            'amount_paise' => $i * 1_000_00,
            'spent_on' => fyDay(100 + $i)->toDateString(),
            'category_id' => category($books->company, 'expense', 'Marketing')->id,
            'payer_mode' => 'full',
            'payers' => [['type' => 'partner', 'partner_id' => $books->kabir->id, 'account_id' => null, 'amount_paise' => $i * 1_000_00]],
        ])->assertSessionHasNoErrors();
    }

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('approvals.count', 6)
            ->has('approvals.rows', 5)
            ->where('approvals.rows.0.description', 'Meta ads 1')
            ->where('approvals.rows.0.canDecide', true)
            ->where('approvals.rows.0.colour', 3)
            ->where('dashboard.kpis.pending.paise', 21_000_00)
            ->where('abilities.approve', true));

    // Kabir's own expenses can't be his to decide (D44); a Viewer can't approve at all.
    $this->actingAs($kabirUser)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('approvals.rows.0.canDecide', false));
    $this->actingAs($viewer)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('abilities.approve', false)->where('approvals.rows.0.canDecide', false));

    // Approving inline goes through the Expenses action and the figures follow.
    $first = Expense::query()->withoutGlobalScopes()->where('company_id', $books->company->id)->orderBy('id')->firstOrFail();
    $this->actingAs($admin)->post(route('expenses.approve', $first))->assertSessionHasNoErrors();

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('approvals.count', 5)
            ->where('dashboard.kpis.expenses.paise', 1_000_00));
});

test('if the figures can’t be read the page shows its error state, not zeros', function () {
    $books = dashboardBooks();
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $broken = Mockery::mock(Repository::class);
    $broken->shouldReceive('get')->andReturn('v1');
    $broken->shouldReceive('forever')->andReturnTrue();
    $broken->shouldReceive('remember')->andThrow(new RuntimeException('cache down'));
    $this->app->instance(ReportCache::class, new ReportCache($broken));

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('dashboard', null)->has('approvals'));
});

test('another company’s books never show', function () {
    dashboardBooks();

    $this->actingAs(userWithRole(CompanyRole::Admin))->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('dashboard.empty', true)->where('dashboard.kpis.income.paise', 0)->where('approvals.count', 0));
});
