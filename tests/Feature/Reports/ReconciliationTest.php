<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Ledger\IntegrityCheck;
use App\Ledger\OpeningBalance;
use App\Ledger\PayerLine;
use App\Ledger\Postings;
use App\Models\Company;
use App\Models\Member;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Phase 6 acceptance (BUILD_PLAN): for the same period, Dashboard KPIs = Reports = Trial
| balance / Balance sheet = Capital statement. Each page is read through HTTP, as people
| see it, and compared figure by figure.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** @return array<string, mixed> the props of a page, read through HTTP */
function pageProps($test, string $route, array $query = []): array
{
    $props = [];
    $test->get(route($route, $query))->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/** Every figure the pages show for the fiscal year to date agrees across them. */
function assertReconciles($test, ?int $expectIncome = null, ?int $expectExpenses = null): void
{
    $dashboard = pageProps($test, 'dashboard');
    $reports = pageProps($test, 'reports.index');
    $trial = pageProps($test, 'reports.trial-balance');
    $sheet = pageProps($test, 'reports.balance-sheet');
    $capital = pageProps($test, 'capital-accounts.index');

    $income = $dashboard['dashboard']['kpis']['income']['paise'];
    $expenses = $dashboard['dashboard']['kpis']['expenses']['paise'];
    $net = $dashboard['dashboard']['kpis']['net']['paise'];
    $balance = $dashboard['dashboard']['balance'];

    if ($expectIncome !== null) {
        expect($income)->toBe($expectIncome)->and($expenses)->toBe($expectExpenses);
    }

    $accounts = collect($trial['trial']['accounts']);
    $assets = collect($sheet['sheet']['assets']);

    expect($net)->toBe($income - $expenses)
        // Dashboard = Reports.
        ->and($reports['report']['kpis']['income'])->toBe($income)
        ->and($reports['report']['kpis']['expenses'])->toBe($expenses)
        ->and($reports['report']['kpis']['net'])->toBe($net)
        ->and($reports['report']['kpis']['other'])->toBe($dashboard['dashboard']['kpis']['other']['paise'])
        ->and($reports['report']['kpis']['salary'])->toBe($dashboard['dashboard']['kpis']['expenses']['salary'])
        ->and(array_sum(array_column($reports['report']['months'], 'net')))->toBe($net)
        ->and($reports['report']['partnerTotals']['profitDue'])->toBe($net)
        ->and($reports['report']['partnerTotals']['net'])->toBe($net)
        // The balance hero's strip adds up to the balance.
        ->and($balance['openingPaise'] + $balance['incomePaise'] - $balance['expensesPaise'] + $balance['capitalPaise'] + $balance['personalPaise'] + $balance['taxesPaise'] + $balance['otherPaise'])->toBe($balance['currentPaise'])
        ->and($balance['otherPaise'])->toBe(0)
        // Trial balance: balanced, and its income, expenses and money match.
        ->and($trial['trial']['driftPaise'])->toBe(0)
        ->and($accounts->where('type', 'income')->sum(fn ($a) => $a['creditPaise'] - $a['debitPaise']))->toBe($income)
        ->and($accounts->where('type', 'expense')->sum(fn ($a) => $a['debitPaise'] - $a['creditPaise']))->toBe($expenses)
        // Balance sheet: balanced, profit is the year's net, money is the balance.
        ->and($sheet['sheet']['driftPaise'])->toBe(0)
        ->and($sheet['sheet']['currentYearProfitPaise'])->toBe($net)
        ->and($sheet['sheet']['totalAssetsPaise'])->toBe($sheet['sheet']['totalLiabilitiesPaise'] + $sheet['sheet']['totalEquityPaise'])
        // Capital accounts close at the balance sheet's equity, and its profit is the year's net.
        ->and($capital['kpis']['closingPaise'])->toBe($sheet['sheet']['totalEquityPaise'])
        ->and($capital['kpis']['netProfitPaise'])->toBe($net)
        ->and($sheet['capitalClosingPaise'])->toBe($capital['kpis']['closingPaise']);

    // Money accounts on the balance sheet sum to the dashboard's balance (partner current debits aside).
    $money = $accounts->filter(fn ($a) => str_starts_with($a['code'], '1000-'))->sum(fn ($a) => $a['closingPaise']);
    expect($money)->toBe($balance['currentPaise'])
        ->and($assets->sum('amountPaise'))->toBeGreaterThanOrEqual($money);

    // Phase 11 (D155): opening + debits − credits = closing on every trial balance line.
    foreach ($accounts as $a) {
        expect($a['openingPaise'] + $a['debitPaise'] - $a['creditPaise'])->toBe($a['closingPaise'], $a['name']);
    }

    // D156: the Schedule III statements tie to the existing reports.
    $pnl = pageProps($test, 'reports.profit-and-loss');
    $schedule = pageProps($test, 'reports.balance-sheet', ['layout' => 'schedule3']);

    expect($pnl['ties'])->toBeTrue()
        ->and($pnl['pnl']['totalIncome'])->toBe($income)
        ->and($pnl['pnl']['totalExpenses'])->toBe($expenses)
        ->and($pnl['pnl']['profit'])->toBe($net)
        ->and(collect($pnl['pnl']['notes'])->sum('total'))->toBe($income + $expenses)
        ->and($schedule['schedule']['totalAssets'])->toBe($sheet['sheet']['totalAssetsPaise'])
        ->and($schedule['schedule']['totalEquityAndLiabilities'])->toBe($sheet['sheet']['totalLiabilitiesPaise'] + $sheet['sheet']['totalEquityPaise']);

    foreach ($schedule['schedule']['sections'] as $section) {
        foreach ($section['groups'] as $group) {
            foreach ($group['lines'] as $line) {
                $note = collect($schedule['schedule']['notes'])->firstWhere('number', $line['note']);
                expect($note['total'])->toBe($line['amount'], $line['label']);
            }
        }
    }

    // D157: the cash flow statement reconciles to the movement in cash and bank.
    $flow = pageProps($test, 'reports.cash-flow')['flow'];

    expect($flow['reconciled'])->toBeTrue()
        ->and($flow['closingCash'])->toBe($balance['currentPaise'])
        ->and($flow['openingCash'] + $flow['operatingTotal'] + $flow['investingTotal'] + $flow['financingTotal'])->toBe($flow['closingCash'])
        ->and($flow['operating'][0]['amount'])->toBe($net);
}

test('the seeded demo books reconcile across every page', function () {
    $this->seed(DatabaseSeeder::class);
    $aarav = User::query()->where('email', 'aarav@vertexlabs.in')->sole();

    foreach (['Saarthi Portal JV', 'Vertex Labs Pvt Ltd'] as $name) {
        $company = Company::query()->where('name', $name)->sole();
        $this->actingAs($aarav)->post(route('companies.switch', $company));

        $name === 'Saarthi Portal JV'
            ? assertReconciles($this, 62_10_000_00, 43_67_650_00)
            : assertReconciles($this);

        expect(app(IntegrityCheck::class)->run($company))->toBe([]);
    }
});

test('a busy random-ish fixture reconciles too', function () {
    mt_srand(20261115);
    $books = jvBooks(openingPaise: 3_00_000_00);
    $p = app(Postings::class);
    app(OpeningBalance::class)->post($books->company, fyDay());
    $partners = [$books->aarav, $books->ishita, $books->kabir, $books->meera];
    $accounts = [$books->account, $books->vertexAccount, $books->bluefinAccount];
    $member = Member::factory()->for($books->company)->create(['partner_id' => $books->kabir->id]);
    $income = ['Project', 'Product', 'Consulting'];
    $spend = ['Software', 'Hardware', 'Travel', 'Marketing'];
    $paise = fn (int $min, int $max) => mt_rand($min, $max) * 100 + mt_rand(0, 99);

    for ($i = 0; $i < 60; $i++) {
        $day = fyDay(mt_rand(0, 220));
        $partner = $partners[mt_rand(0, 3)];

        match (mt_rand(0, 6)) {
            0, 1 => $p->incomeToAccount($books->company, $day, $paise(10_000, 3_00_000), category($books->company, 'income', $income[mt_rand(0, 2)]), $accounts[mt_rand(0, 2)], $partner),
            2 => $p->incomeToPartner($books->company, $day, $paise(5_000, 50_000), category($books->company, 'income', 'Other'), $partner, $partner),
            3 => $p->expense($books->company, $day, category($books->company, 'expense', $spend[mt_rand(0, 3)]), [PayerLine::company($paise(1_000, 60_000), $accounts[mt_rand(0, 2)])]),
            4 => $p->expense($books->company, $day, category($books->company, 'expense', $spend[mt_rand(0, 3)]), [PayerLine::partner($paise(1_000, 20_000), $partner), PayerLine::member($paise(500, 5_000), $member)]),
            5 => $p->contribution($books->company, $day, $partner, $paise(10_000, 1_00_000), $accounts[mt_rand(0, 2)]),
            default => $p->loan($books->company, $day, $partner, $paise(10_000, 80_000), $accounts[mt_rand(0, 2)]),
        };
    }

    $p->salary($books->company, $member, 45_000_00, fyDay(29));
    $p->drawing($books->company, fyDay(150), $books->meera, 20_000_00, $books->bluefinAccount);
    $p->settlementTransfer($books->company, fyDay(160), $books->vertex, $books->bluefin, 75_000_00);

    $this->actingAs(userWithRole(CompanyRole::Admin, $books->company));
    assertReconciles($this);

    expect(app(IntegrityCheck::class)->run($books->company))->toBe([]);
});
