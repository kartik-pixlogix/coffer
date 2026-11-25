<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Ledger\FiscalYears;
use App\Ledger\OpeningBalance;
use App\Ledger\PayerLine;
use App\Ledger\Postings;
use App\Ledger\YearEnd;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Phase 11 statements: the four-column trial balance (D155), Schedule III balance sheet and
| profit and loss (D156), the cash flow statement (D157) and the chart of accounts (D158).
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** Opening ₹1,00,000; ₹3,00,000 project income; ₹50,000 software on Ishita's card; a ₹40,000 loan from Aarav. */
function accountantBooks(): object
{
    $books = singleBooks(openingPaise: 1_00_000_00);
    $p = app(Postings::class);
    app(OpeningBalance::class)->post($books->company, fyDay());
    $p->incomeToAccount($books->company, fyDay(15), 3_00_000_00, category($books->company, 'income', 'Project'), $books->account, $books->aarav);
    $p->expense($books->company, fyDay(20), category($books->company, 'expense', 'Software'), [PayerLine::partner(50_000_00, $books->ishita)]);
    $p->loan($books->company, fyDay(40), $books->aarav, 40_000_00, $books->account);
    $books->admin = userWithRole(CompanyRole::Admin, $books->company);

    return $books;
}

test('who may open each statement', function (string $route, CompanyRole $role, int $status) {
    $books = accountantBooks();

    $this->actingAs(userWithRole($role, $books->company))->get(route($route))->assertStatus($status);
})->with([
    'p&l, partner' => ['reports.profit-and-loss', CompanyRole::Partner, 200],
    'p&l, viewer' => ['reports.profit-and-loss', CompanyRole::Viewer, 200],
    'p&l, member' => ['reports.profit-and-loss', CompanyRole::Member, 403],
    'cash flow, partner' => ['reports.cash-flow', CompanyRole::Partner, 200],
    'cash flow, viewer' => ['reports.cash-flow', CompanyRole::Viewer, 200],
    'cash flow, member' => ['reports.cash-flow', CompanyRole::Member, 403],
    'chart, partner' => ['reports.chart-of-accounts', CompanyRole::Partner, 200],
    'chart, viewer' => ['reports.chart-of-accounts', CompanyRole::Viewer, 403],
    'chart, member' => ['reports.chart-of-accounts', CompanyRole::Member, 403],
]);

test('the trial balance shows opening, movement and closing for a period inside the year', function () {
    $books = accountantBooks();

    $this->actingAs($books->admin)->get(route('reports.trial-balance', ['fy' => 2026, 'from' => '2026-05-01', 'asof' => '2026-11-15']))
        ->assertInertia(function (Assert $page) {
            $page->where('date.from', '2026-05-01')->where('trial.driftPaise', 0);
            $accounts = collect($page->toArray()['props']['trial']['accounts']);
            $money = $accounts->firstWhere('name', 'Company account');
            $income = $accounts->firstWhere('name', 'Income — Project');

            expect($money)->toMatchArray(['openingPaise' => 4_00_000_00, 'debitPaise' => 40_000_00, 'creditPaise' => 0, 'closingPaise' => 4_40_000_00])
                ->and($income)->toMatchArray(['openingPaise' => -3_00_000_00, 'debitPaise' => 0, 'creditPaise' => 0, 'closingPaise' => -3_00_000_00]);

            foreach ($accounts as $a) {
                expect($a['openingPaise'] + $a['debitPaise'] - $a['creditPaise'])->toBe($a['closingPaise']);
            }
        });
});

test('a "from" outside the year falls back to its first day', function () {
    $books = accountantBooks();

    $this->actingAs($books->admin)->get(route('reports.trial-balance', ['fy' => 2026, 'from' => '2025-01-01']))
        ->assertInertia(fn (Assert $page) => $page->where('date.from', '2026-04-01'));
});

test('the Schedule III profit and loss puts each category on its line and ties to the ledger', function () {
    $books = accountantBooks();

    $this->actingAs($books->admin)->get(route('reports.profit-and-loss'))
        ->assertInertia(function (Assert $page) {
            $page->component('reports/profit-and-loss')->where('ties', true)->where('pnl.previousFrom', null);
            $lines = collect($page->toArray()['props']['pnl']['lines'])->keyBy('key');

            expect($lines['revenue']['amount'])->toBe(3_00_000_00)
                ->and($lines['other_income']['amount'])->toBe(0)
                ->and($lines['employee_benefits']['amount'])->toBe(0)
                ->and($lines['other_expenses']['amount'])->toBe(50_000_00)
                ->and($lines['total_expenses']['amount'])->toBe(50_000_00)
                ->and($lines['profit']['amount'])->toBe(2_50_000_00);
        });
});

test('the previous year shows beside this one when it has entries', function () {
    $books = accountantBooks();
    app(Postings::class)->incomeToAccount($books->company, CarbonImmutable::create(2025, 6, 1), 75_000_00, category($books->company, 'income', 'Consulting'), $books->account, $books->aarav);

    $this->actingAs($books->admin)->get(route('reports.profit-and-loss'))
        ->assertInertia(function (Assert $page) {
            $page->where('pnl.previousFrom', '2025-04-01')->where('pnl.previousTo', '2025-11-15');
            $lines = collect($page->toArray()['props']['pnl']['lines'])->keyBy('key');

            expect($lines['revenue']['previous'])->toBe(75_000_00)->and($lines['profit']['previous'])->toBe(75_000_00);
        });

    $this->actingAs($books->admin)->get(route('reports.balance-sheet', ['layout' => 'schedule3']))
        ->assertInertia(fn (Assert $page) => $page->where('schedule.previousAsOf', '2026-03-31'));
});

test('the Schedule III balance sheet maps every account and both sides agree', function () {
    $books = accountantBooks();

    $this->actingAs($books->admin)->get(route('reports.balance-sheet', ['layout' => 'schedule3']))
        ->assertInertia(function (Assert $page) {
            $page->where('layout', 'schedule3')
                ->where('schedule.totalAssets', 4_40_000_00)
                ->where('schedule.totalEquityAndLiabilities', 4_40_000_00);
            $lines = collect($page->toArray()['props']['schedule']['sections'])
                ->flatMap(fn ($s) => collect($s['groups'])->flatMap(fn ($g) => $g['lines']))
                ->keyBy('label');

            expect($lines["Partners' capital accounts"]['amount'])->toBe(1_00_000_00)
                ->and($lines['Reserves and surplus']['amount'])->toBe(2_50_000_00)
                ->and($lines['Short-term borrowings']['amount'])->toBe(40_000_00)
                ->and($lines['Other current liabilities']['amount'])->toBe(50_000_00)
                ->and($lines['Cash and cash equivalents']['amount'])->toBe(4_40_000_00)
                ->and($lines['Short-term loans and advances']['amount'])->toBe(0);
        });

    $this->actingAs($books->admin)->get(route('reports.balance-sheet'))
        ->assertInertia(fn (Assert $page) => $page->where('layout', 'standard')->where('schedule', null));
});

test('the cash flow statement reconciles to the money accounts', function () {
    $books = accountantBooks();

    $this->actingAs($books->admin)->get(route('reports.cash-flow'))
        ->assertInertia(function (Assert $page) {
            $page->where('flow.reconciled', true)
                ->where('flow.openingCash', 1_00_000_00)
                ->where('flow.operatingTotal', 3_00_000_00)
                ->where('flow.financingTotal', 40_000_00)
                ->where('flow.netChange', 3_40_000_00)
                ->where('flow.closingCash', 4_40_000_00);
            $operating = collect($page->toArray()['props']['flow']['operating'])->keyBy('key');

            expect($operating['net_profit']['amount'])->toBe(2_50_000_00)
                ->and($operating['partners']['amount'])->toBe(50_000_00);
        });
});

test('a converted personal-card balance and a settlement move no cash and still reconcile', function () {
    $books = jvBooks(openingPaise: 2_00_000_00);
    $p = app(Postings::class);
    app(OpeningBalance::class)->post($books->company, fyDay());
    $p->expense($books->company, fyDay(10), category($books->company, 'expense', 'Travel'), [PayerLine::partner(30_000_00, $books->kabir)]);
    $p->convertToLoan($books->company, fyDay(20), $books->kabir, 30_000_00, 'Converted');
    $p->settlementTransfer($books->company, fyDay(30), $books->vertex, $books->bluefin, 25_000_00, 'Settle');
    $admin = userWithRole(CompanyRole::Admin, $books->company);

    $this->actingAs($admin)->get(route('reports.cash-flow'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('flow.reconciled', true)
            ->where('flow.netChange', 0)
            ->where('flow.openingCash', 2_00_000_00)
            ->where('flow.closingCash', 2_00_000_00));
});

test('the chart of accounts lists each account under its heading, and Admins move a category', function () {
    $books = accountantBooks();
    $project = category($books->company, 'income', 'Project');

    $this->actingAs($books->admin)->get(route('reports.chart-of-accounts'))
        ->assertInertia(function (Assert $page) {
            $page->component('reports/chart-of-accounts')->where('canEdit', true);
            $groups = collect($page->toArray()['props']['groups'])->keyBy('key');

            expect(collect($groups['cash']['accounts'])->pluck('name')->all())->toContain('Company account')
                ->and(collect($groups['revenue_from_operations']['accounts'])->pluck('name')->all())->toContain('Income — Project')
                ->and(collect($groups['other_income']['accounts'])->pluck('name')->all())->toContain('Income — Other')
                ->and(collect($groups['employee_benefits']['accounts'])->pluck('name')->all())->toContain('Expenses — Salary');
        });

    $this->actingAs($books->admin)->patch(route('reports.chart-of-accounts.schedule-line', $project), ['schedule_line' => 'other_income'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($project->refresh()->schedule_line)->toBe('other_income')
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('subject_type', $project->getMorphClass())->where('subject_id', $project->id)->where('action', 'updated')->exists())->toBeTrue();

    $this->actingAs($books->admin)->get(route('reports.profit-and-loss'))
        ->assertInertia(function (Assert $page) {
            $lines = collect($page->toArray()['props']['pnl']['lines'])->keyBy('key');

            expect($lines['revenue']['amount'])->toBe(0)->and($lines['other_income']['amount'])->toBe(3_00_000_00);
        });
});

test('an expense line can’t be given to an income category, and partners can’t change lines', function () {
    $books = accountantBooks();
    $project = category($books->company, 'income', 'Project');

    $this->actingAs($books->admin)->patch(route('reports.chart-of-accounts.schedule-line', $project), ['schedule_line' => 'other_expenses'])
        ->assertSessionHasErrors('schedule_line');
    $this->actingAs(userWithRole(CompanyRole::Partner, $books->company))
        ->patch(route('reports.chart-of-accounts.schedule-line', $project), ['schedule_line' => 'other_income'])
        ->assertForbidden();
});

test('the new statements export as PDF and Excel', function (string $route, array $query) {
    $books = accountantBooks();

    foreach (['pdf', 'xlsx'] as $format) {
        $this->actingAs($books->admin)->get(route($route, [...$query, 'format' => $format]))->assertOk();
    }
})->with([
    'profit and loss' => ['reports.profit-and-loss.export', []],
    'cash flow' => ['reports.cash-flow.export', []],
    'schedule III balance sheet' => ['reports.balance-sheet.export', ['layout' => 'schedule3']],
    'four-column trial balance' => ['reports.trial-balance.export', ['from' => '2026-05-01']],
]);

test('across a closed year every trial balance line still adds up and the cash flow reconciles', function () {
    $books = singleBooks(openingPaise: 1_00_000_00);
    $p = app(Postings::class);
    app(OpeningBalance::class)->post($books->company, CarbonImmutable::create(2025, 4, 1));
    $p->incomeToAccount($books->company, CarbonImmutable::create(2025, 7, 1), 2_00_000_00, category($books->company, 'income', 'Project'), $books->account, $books->aarav);
    $p->expense($books->company, CarbonImmutable::create(2025, 8, 1), category($books->company, 'expense', 'Travel'), [PayerLine::company(20_000_00, $books->account)]);
    $p->incomeToAccount($books->company, fyDay(10), 50_000_00, category($books->company, 'income', 'Consulting'), $books->account, $books->ishita);
    $admin = userWithRole(CompanyRole::Admin, $books->company);
    $year = app(FiscalYears::class)->forDate($books->company, CarbonImmutable::create(2025, 4, 1));
    app(YearEnd::class)->close($books->company, $year, $admin);

    foreach ([2025, 2026] as $fy) {
        $this->actingAs($admin)->get(route('reports.trial-balance', ['fy' => $fy]))
            ->assertInertia(function (Assert $page) {
                $page->where('trial.driftPaise', 0);

                foreach ($page->toArray()['props']['trial']['accounts'] as $a) {
                    expect($a['openingPaise'] + $a['debitPaise'] - $a['creditPaise'])->toBe($a['closingPaise'], $a['name']);
                }
            });

        $this->actingAs($admin)->get(route('reports.cash-flow', ['fy' => $fy]))
            ->assertInertia(fn (Assert $page) => $page->where('flow.reconciled', true));
    }

    // The closed year's profit is in capital now, so this year's statement starts from it.
    $this->actingAs($admin)->get(route('reports.balance-sheet', ['layout' => 'schedule3']))
        ->assertInertia(function (Assert $page) {
            $lines = collect($page->toArray()['props']['schedule']['sections'][0]['groups'][0]['lines'])->keyBy('label');

            expect($lines["Partners' capital accounts"]['amount'])->toBe(2_80_000_00)
                ->and($lines["Partners' capital accounts"]['previous'])->toBe(2_80_000_00)
                ->and($lines['Reserves and surplus']['amount'])->toBe(50_000_00)
                ->and($lines['Reserves and surplus']['previous'])->toBe(0);
        });
});
