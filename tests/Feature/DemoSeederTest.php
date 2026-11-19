<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Ledger\IntegrityCheck;
use App\Ledger\OpeningBalance;
use App\Ledger\Period;
use App\Ledger\Reports;
use App\Ledger\SettlementCalculator;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Income;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultItem;
use App\Services\Vault\VaultHealth;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;

test('the demo seeder recreates the design’s workspace (spec §24)', function () {
    $this->seed(DatabaseSeeder::class);

    $aarav = User::query()->where('email', 'aarav@vertexlabs.in')->sole();
    $saarthi = Company::query()->where('name', 'Saarthi Portal JV')->sole();

    expect(Company::query()->pluck('name')->sort()->values()->all())
        ->toBe(['Kriya Commerce', 'Northstar Studio', 'Saarthi Portal JV', 'Vertex Labs Pvt Ltd'])
        ->and($saarthi->workspace->owner_id)->toBe($aarav->id)
        ->and($aarav->companies()->count())->toBe(4)
        ->and($saarthi->roleOf($aarav))->toBe(CompanyRole::Admin)
        ->and($saarthi->roleOf(User::query()->where('email', 'rohan@vertexlabs.in')->sole()))->toBe(CompanyRole::Member)
        ->and($saarthi->roleOf(User::query()->where('email', 'accounts@ledgerworks.in')->sole()))->toBe(CompanyRole::Viewer);

    $overall = Partner::query()->withoutGlobalScopes()->where('company_id', $saarthi->id)->with('side')->get()
        ->mapWithKeys(fn (Partner $p) => [$p->name => $p->effectiveEquityBps()]);

    expect($overall->all())->toBe(['Aarav Mehta' => 2500, 'Ishita Rao' => 2500, 'Kabir Shah' => 2500, 'Meera Iyer' => 2500])
        ->and($overall->sum())->toBe(10000);
});

test('the demo books land on the design’s figures', function () {
    $this->seed(DatabaseSeeder::class);

    $saarthi = Company::query()->where('name', 'Saarthi Portal JV')->sole();
    $period = Period::between(CarbonImmutable::create(2026, 4, 1), CarbonImmutable::create(2026, 10, 31));
    $figures = app(Reports::class)->figures($saarthi, $period);
    $settlement = app(SettlementCalculator::class)->calculate($saarthi, $period);
    $vertex = collect($settlement->sides)->first(fn ($s) => $s->side->name === 'Vertex Labs');

    expect($figures['income'])->toBe(62_10_000_00)
        ->and($figures['expenses'])->toBe(43_67_650_00)
        ->and($figures['net'])->toBe(18_42_350_00)
        // Cash also holds capital and loans (+₹5,20,000) and two unpaid personal-card costs (₹16,580, D57).
        ->and($figures['capitalAndLoans'])->toBe(5_36_580_00)
        ->and($figures['currentBalance'])->toBe(23_78_930_00)
        ->and($vertex->remaining)->toBe(3_24_600_00)
        ->and($vertex->status())->toBe('owes')
        ->and($settlement->transfers[0]['to']->name)->toBe('Bluefin Infotech')
        ->and($settlement->transfers[0]['amount'])->toBe(3_24_600_00);

    foreach (Company::query()->get() as $company) {
        expect(app(IntegrityCheck::class)->run($company, CarbonImmutable::create(2026, 11, 16)))->toBe([]);
    }

    $vertexLabs = Company::query()->where('name', 'Vertex Labs Pvt Ltd')->sole();
    expect(app(OpeningBalance::class)->current($vertexLabs))->not->toBeNull();
});

test('the demo books are real records, and the new pages land on their designs’ figures', function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    $this->seed(DatabaseSeeder::class);

    $aarav = User::query()->where('email', 'aarav@vertexlabs.in')->sole();
    $saarthi = Company::query()->where('name', 'Saarthi Portal JV')->sole();
    $this->actingAs($aarav)->post(route('companies.switch', $saarthi));
    $records = fn (string $model) => $model::query()->withoutGlobalScope('company')->where('company_id', $saarthi->id);

    // Every income and expense entry has its record (D57); pending and rejected ones don't post.
    expect($records(Income::class)->count())->toBe(28)
        ->and($records(Income::class)->whereNull('journal_entry_id')->count())->toBe(0)
        ->and($records(Expense::class)->where('status', 'pending')->count())->toBe(3)
        ->and($records(Expense::class)->where('status', 'rejected')->count())->toBe(1)
        ->and($records(Expense::class)->where('status', 'approved')->whereNull('journal_entry_id')->count())->toBe(0)
        ->and(JournalEntry::query()->withoutGlobalScopes()->where('company_id', $saarthi->id)->whereNull('source_type')->where('type', '!=', 'opening')->count())->toBe(0);

    // Settlements.dc.html: per side, and the July transfer.
    $this->get(route('settlements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('sides.0.collectedPaise', 39_95_000_00)
        ->where('sides.0.paidPaise', 25_64_225_00)
        ->where('sides.0.netHeldPaise', 14_30_775_00)
        ->where('sides.0.entitledPaise', 9_21_175_00)
        ->where('sides.0.paidOutPaise', 1_85_000_00)
        ->where('sides.0.remainingPaise', 3_24_600_00)
        ->where('sides.1.collectedPaise', 22_15_000_00)
        ->where('sides.1.paidPaise', 18_03_425_00)
        ->where('transfers.0.reference', 'UTR HDFC260710001834'));

    // CapitalAccounts.dc.html: contributions ₹7,00,000, drawings ₹4,10,000, profit ₹18,42,350 (equity per spec §24).
    $this->get(route('capital-accounts.index'))->assertInertia(fn (Assert $page) => $page
        ->where('kpis.contributionsPaise', 7_00_000_00)
        ->where('kpis.contributionsCount', 4)
        ->where('kpis.drawingsPaise', 4_10_000_00)
        ->where('kpis.drawingsCount', 4)
        ->where('kpis.netProfitPaise', 18_42_350_00)
        ->where('kpis.closingPaise', 21_32_350_00)
        ->where('statement.0.contributionsPaise', 2_00_000_00)
        ->where('statement.0.drawingsPaise', 1_50_000_00));

    // PartnerLoans.dc.html: borrowed ₹4,50,000, repaid ₹2,20,000, L-03 closed on 30 Sep, next ₹40,000 on 30 Nov.
    $this->get(route('partner-loans.index'))->assertInertia(fn (Assert $page) => $page
        ->where('kpis.borrowedPaise', 4_50_000_00)
        ->where('kpis.repaidPaise', 2_20_000_00)
        ->where('kpis.outstandingPaise', 2_30_000_00)
        ->where('kpis.openCount', 2)
        ->where('kpis.repaymentsCount', 5)
        ->where('kpis.next', ['code' => 'L-01', 'amountPaise' => 40_000_00, 'date' => '2026-11-30', 'by' => false])
        ->where('loans.2.code', 'L-03')
        ->where('loans.2.closedOn', '2026-09-30')
        ->where('loans.1.next', ['amountPaise' => 1_50_000_00, 'date' => '2027-03-31', 'by' => true])
        ->has('history', 5));

    // Expenses: two personal-card balances still to pay back.
    $this->get(route('expenses.index'))->assertInertia(fn (Assert $page) => $page
        ->where('cards.owed.0.name', 'Kabir Shah')
        ->where('cards.owed.0.owedPaise', 12_980_00)
        ->where('cards.owed.1.name', 'Sana Qureshi')
        ->where('cards.owed.1.owedPaise', 3_600_00)
        ->has('cards.recent', 2));

    CarbonImmutable::setTestNow();
});

test('the demo vault matches the Vault board (D71)', function () {
    CarbonImmutable::setTestNow('2026-11-15 10:00:00');
    $this->seed(DatabaseSeeder::class);
    $saarthi = Company::query()->where('name', 'Saarthi Portal JV')->sole();
    $items = VaultItem::query()->withoutGlobalScopes()->where('company_id', $saarthi->id)->get();

    expect(Vault::query()->withoutGlobalScopes()->where('company_id', $saarthi->id)->orderBy('position')->pluck('name')->all())
        ->toBe(['Internal', 'Saarthi Portal JV', 'Asha Retail', 'Kiran Exports'])
        ->and($items)->toHaveCount(13)
        ->and($items->firstWhere('provider', 'aws')->restricted)->toBeTrue()
        ->and($items->where('strength', '<=', 1)->whereNotNull('strength')->pluck('name')->sort()->values()->all())
        ->toBe(['Gmail — support inbox', 'MySQL — production database', 'Netlify — marketing site', 'WordPress — Kiran Exports'])
        ->and(User::query()->where('email', 'aarav@vertexlabs.in')->sole()->hasTwoFactorEnabled())->toBeTrue()
        ->and(User::query()->where('email', 'meera@bluefininfotech.in')->sole()->hasTwoFactorEnabled())->toBeFalse();

    $summary = app(CurrentCompany::class)->run($saarthi, fn () => app(VaultHealth::class)->summarise($items));
    expect($summary['counts'])->toMatchArray(['weak' => 4, 'reused' => 2, 'breached' => 1, 'expiring' => 2]);
    CarbonImmutable::setTestNow();
});

test('the demo seeders need no dev-only packages, so staging can seed after composer install --no-dev', function () {
    foreach (glob(database_path('seeders/{,*/}*.php'), GLOB_BRACE) ?: [] as $file) {
        expect((string) file_get_contents($file))
            ->not->toContain('::factory(')
            ->not->toContain('fake(')
            ->not->toContain('use Faker');
    }
});
