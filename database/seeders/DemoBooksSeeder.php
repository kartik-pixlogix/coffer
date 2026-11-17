<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Capital\RecordCapitalMovement;
use App\Actions\Expenses\ExpenseData;
use App\Actions\Expenses\PayerInput;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Income\IncomeData;
use App\Actions\Income\RecordIncome;
use App\Actions\Loans\LoanData;
use App\Actions\Loans\RecordLoan;
use App\Actions\Reimbursements\PayReimbursement;
use App\Actions\Settlements\RecordSettlementTransfer;
use App\Enums\CapitalMovementKind;
use App\Enums\PayerMode;
use App\Enums\PayerType;
use App\Enums\ProjectStatus;
use App\Ledger\ChartOfAccounts;
use App\Ledger\OpeningBalance;
use App\Models\Category;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Member;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\Project;
use App\Models\SalaryRun;
use App\Models\Side;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Team\SalaryGenerator;
use App\Services\Timesheets\Hours;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Demo books for the design's figures (spec §24), made through the same Actions the
 * app uses (D57): Income and approved Expense records with payer lines, salary runs,
 * personal-card payouts, capital contributions and drawings, partner loans with
 * repayments, and a settlement transfer — each posting through LedgerPoster.
 *
 * Saarthi Portal JV, Apr–Oct 2026: income ₹62,10,000, expenses ₹43,67,650, net profit
 * ₹18,42,350. Vertex Labs collects ₹39,95,000 and pays ₹25,64,225; Bluefin collects
 * ₹22,15,000 and pays ₹18,03,425; after the ₹1,85,000 transfer in July, "Vertex Labs
 * owes Bluefin Infotech ₹3,24,600" (Settlements.dc.html). Capital and loans follow
 * CapitalAccounts.dc.html and PartnerLoans.dc.html. A few expenses stay pending or
 * rejected (they don't post) and two personal-card balances are left to pay out.
 */
class DemoBooksSeeder extends Seeder
{
    use Concerns\DemoOnly;

    /** Monthly income per side (Apr → Oct), in rupees. */
    private const array INCOME = [
        'Vertex Labs' => [520000, 560000, 610000, 545000, 600000, 575000, 585000],
        'Bluefin Infotech' => [300000, 310000, 330000, 300000, 340000, 315000, 320000],
    ];

    /** What each side pays in total (salaries and personal cards included), in paise. */
    private const array SPEND = [
        'Vertex Labs' => 25_64_225_00,
        'Bluefin Infotech' => 18_03_425_00,
    ];

    /** Hours billed each month by hourly members (fixed members cost their monthly rate). */
    private const array HOURS = [
        'Rohan Desai' => 160, 'Neha Joshi' => 160, 'Arjun Nair' => 120, 'Priya Menon' => 80,
        'Sana Qureshi' => 160, 'Tanvi Shah' => 100,
    ];

    private const array PROJECTS = ['Saarthi portal', 'Asha Retail store', 'Kiran Exports site'];

    private const array CLIENTS = [
        'Saarthi portal' => 'Saarthi Portal JV', 'Asha Retail store' => 'Asha Retail', 'Kiran Exports site' => 'Kiran Exports',
    ];

    /** Each member's usual project (spec §24 projects). */
    private const array WORKS_ON = [
        'Rohan Desai' => 'Saarthi portal', 'Neha Joshi' => 'Saarthi portal', 'Sana Qureshi' => 'Saarthi portal',
        'Dev Patel' => 'Kiran Exports site', 'Vivaan Kapoor' => 'Kiran Exports site',
        'Arjun Nair' => 'Asha Retail store', 'Priya Menon' => 'Asha Retail store', 'Tanvi Shah' => 'Asha Retail store',
    ];

    /** @var array<string, int> paise each side has paid so far */
    private array $spent = ['Vertex Labs' => 0, 'Bluefin Infotech' => 0];

    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly OpeningBalance $opening,
        private readonly SalaryGenerator $salaries,
        private readonly CurrentCompany $current,
        private readonly RecordIncome $income,
        private readonly RecordExpense $expenses,
        private readonly PayReimbursement $payouts,
        private readonly RecordCapitalMovement $capital,
        private readonly RecordLoan $loans,
        private readonly RecordSettlementTransfer $transfers,
    ) {}

    public function run(): void
    {
        $this->refuseInProduction();

        $start = CarbonImmutable::create(2026, 4, 1);
        $aarav = User::query()->where('email', 'aarav@vertexlabs.in')->firstOrFail();

        foreach (Company::query()->orderBy('id')->get() as $company) {
            $this->chart->setUp($company);
            $this->opening->post($company, $start, $aarav);

            foreach (self::PROJECTS as $name) {
                Project::query()->withoutGlobalScopes()->firstOrCreate(
                    ['company_id' => $company->id, 'name' => $name],
                    ['client' => self::CLIENTS[$name], 'status' => ProjectStatus::Active],
                );
            }
        }

        $saarthi = Company::query()->where('name', 'Saarthi Portal JV')->firstOrFail();
        $vertexLabs = Company::query()->where('name', 'Vertex Labs Pvt Ltd')->firstOrFail();

        $this->current->run($saarthi, fn () => $this->saarthi($saarthi, $start, $aarav));
        $this->current->run($vertexLabs, fn () => $this->vertexLabs($vertexLabs, $start, $aarav));
    }

    private function saarthi(Company $company, CarbonImmutable $start, User $by): void
    {
        $sides = Side::query()->get()->keyBy('name');
        $partners = Partner::query()->get()->keyBy('name');
        $members = Member::query()->with('partner')->get();
        $projects = Project::query()->pluck('id', 'name');
        $vertex = $this->chart->sideAccount($sides['Vertex Labs']);
        $bluefin = $this->chart->sideAccount($sides['Bluefin Infotech']);
        $accounts = ['Vertex Labs' => $vertex, 'Bluefin Infotech' => $bluefin];
        $broughtBy = ['Vertex Labs' => ['Aarav Mehta', 'Ishita Rao'], 'Bluefin Infotech' => ['Kabir Shah', 'Meera Iyer']];
        $member = fn (string $name): Member => $members->firstWhere('name', $name) ?? throw new RuntimeException("No member {$name}.");

        // Capital in (CapitalAccounts.dc.html): first-year contributions into each side's account.
        foreach ([['Aarav Mehta', 2_00_000_00, 11], ['Kabir Shah', 2_00_000_00, 11], ['Ishita Rao', 1_50_000_00, 14], ['Meera Iyer', 1_50_000_00, 14]] as [$name, $amount, $day]) {
            $partner = $partners[$name];
            $this->capital->record($company, CapitalMovementKind::Contribution, $partner, $amount, $start->addDays($day), $this->accountOf($partner, $accounts), 'Initial capital', $by);
        }

        // Partner loans (PartnerLoans.dc.html).
        $l1 = $this->loans->lend($company, new LoanData($partners['Aarav Mehta'], 2_00_000_00, $start->addMonth()->addDay(), $vertex, 'salaries before Asha milestone 2', 40_000_00), $by);
        $this->loans->lend($company, new LoanData($partners['Kabir Shah'], 1_50_000_00, $start->addMonths(2)->addDays(17), $bluefin, 'server and licence prepayment', null, CarbonImmutable::create(2027, 3, 31)), $by);
        $l3 = $this->loans->lend($company, new LoanData($partners['Meera Iyer'], 1_00_000_00, $start->addDays(2), $bluefin, 'opening working capital', 50_000_00), $by);

        foreach (range(0, 6) as $month) {
            $monthStart = $start->addMonths($month);

            // Income into each side's account, brought in by its partners in turn.
            foreach (self::INCOME as $sideName => $amounts) {
                $half = intdiv($amounts[$month] * 100, 2);

                foreach ([$half, $amounts[$month] * 100 - $half] as $i => $amount) {
                    $project = self::PROJECTS[($month + $i) % 3];
                    $this->income->create($company, new IncomeData(
                        source: self::CLIENTS[$project].' — '.($i === 0 ? 'milestone' : 'retainer').' '.$monthStart->format('M Y'),
                        amountPaise: $amount,
                        receivedOn: $monthStart->addDays(4 + $i * 12),
                        categoryId: $this->category($company, 'income', $i === 0 ? 'Project' : 'Consulting')->id,
                        broughtInBy: $partners[$broughtBy[$sideName][($month + $i) % 2]]->id,
                        moneyAccountId: $accounts[$sideName]->id,
                        receivedBy: null,
                        projectId: $projects[$project],
                        notes: null,
                    ), $by);
                }
            }

            // Timesheets for the month, then salaries from them (spec §8.3; Auto expense rows, D43).
            $this->hours($company, $members, $monthStart, $monthStart->endOfMonth()->startOfDay(), $by);

            foreach ($members as $m) {
                $this->salaries->runMember($company, $m, $monthStart, $by);
                $this->spent[$this->sideOf($m, $sides)] += (int) SalaryRun::query()
                    ->where('member_id', $m->id)->whereDate('month', $monthStart->toDateString())->value('amount_paise');
            }

            // Running costs from each side's account.
            foreach (['Vertex Labs' => [['Software', 18_450_00, 'GitHub, Figma and Vercel'], ['Marketing', 35_000_00, 'LinkedIn ads'], ['Office & misc', 22_000_00, 'Co-working desks']], 'Bluefin Infotech' => [['Software', 24_300_00, 'AWS and Jira'], ['Hardware', 15_000_00, 'Test devices lease'], ['Office & misc', 18_500_00, 'Office rent share']]] as $sideName => $costs) {
                foreach ($costs as $i => [$category, $amount, $what]) {
                    $this->approved($company, $by, $what.' — '.$monthStart->format('M Y'), $category, $monthStart->addDays(8 + $i * 5), [$this->fromAccount($accounts[$sideName], $amount)], PayerMode::Company);
                    $this->spent[$sideName] += $amount;
                }
            }
        }

        $rohan = $member('Rohan Desai');
        $sana = $member('Sana Qureshi');

        // Paid personally, then reimbursed: a member's travel and a partner's AWS bill.
        $this->approved($company, $by, 'Client visit — Asha Retail', 'Travel', $start->addMonths(5)->addDays(3), [new PayerInput(PayerType::Member, null, $rohan->id, null, 4_200_00)], PayerMode::Full, $projects['Asha Retail store']);
        $this->payouts->pay($company, $rohan, 4_200_00, $start->addMonths(5)->addDays(10), $vertex, 'NEFT 7731', $by);
        $this->spent['Vertex Labs'] += 4_200_00;

        $this->approved($company, $by, 'AWS — October bill', 'Software', $start->addMonths(6)->addDays(2), [new PayerInput(PayerType::Partner, $partners['Meera Iyer']->id, null, null, 18_450_00)], PayerMode::Full);
        $this->payouts->pay($company, $partners['Meera Iyer'], 18_450_00, $start->addMonths(6)->addDays(9), $bluefin, null, $by);
        $this->spent['Bluefin Infotech'] += 18_450_00;

        // Still to pay back: shown on Expenses' "Owed for personal cards" (D50).
        $this->approved($company, $by, 'iPhone test device case and cables', 'Hardware', $start->addMonths(6)->addDays(16), [new PayerInput(PayerType::Member, null, $sana->id, null, 3_600_00)], PayerMode::Full, $projects['Saarthi portal']);
        $this->approved($company, $by, 'Figma — annual seat', 'Software', $start->addMonths(6)->addDays(18), [new PayerInput(PayerType::Partner, $partners['Kabir Shah']->id, null, null, 12_980_00)], PayerMode::Full);
        $this->spent['Bluefin Infotech'] += 3_600_00 + 12_980_00;

        // Whatever is left of each side's spend lands as travel and marketing (keeps the design totals exact).
        foreach ($accounts as $sideName => $account) {
            $rest = self::SPEND[$sideName] - $this->spent[$sideName];

            if ($rest <= 0) {
                throw new RuntimeException("Demo spend for {$sideName} overshoots by ".(-$rest).' paise.');
            }

            $travel = intdiv($rest, 3);
            $this->approved($company, $by, 'Team travel — site visits', 'Travel', $start->addMonths(4)->addDays(14), [$this->fromAccount($account, $travel)], PayerMode::Company);
            $this->approved($company, $by, 'Launch campaign', 'Marketing', $start->addMonths(6)->addDays(14), [$this->fromAccount($account, $rest - $travel)], PayerMode::Company);
        }

        // Waiting for a decision, and turned down (neither posts).
        $ishitaUser = User::query()->where('email', 'ishita@vertexlabs.in')->first() ?? $by;
        $rohanUser = User::query()->where('email', 'rohan@vertexlabs.in')->first() ?? $by;
        $this->submitted($company, $rohanUser, 'MacBook Air for QA', 'Hardware', CarbonImmutable::create(2026, 11, 9), [new PayerInput(PayerType::Member, null, $rohan->id, null, 1_14_900_00)]);
        $this->submitted($company, $ishitaUser, 'BrowserStack — team plan', 'Software', CarbonImmutable::create(2026, 11, 12), [$this->fromAccount($vertex, 12_500_00)], PayerMode::Company);
        $this->submitted($company, $ishitaUser, 'Client dinner — Kiran Exports', 'Travel', CarbonImmutable::create(2026, 11, 13), [new PayerInput(PayerType::Partner, $partners['Ishita Rao']->id, null, null, 6_850_00)]);
        $rejected = $this->submitted($company, $rohanUser, 'Noise-cancelling headphones', 'Hardware', CarbonImmutable::create(2026, 10, 27), [new PayerInput(PayerType::Member, null, $rohan->id, null, 24_990_00)]);
        $this->expenses->reject($rejected, 'Personal equipment isn’t covered. Use the office pair.', $by);

        // Drawings at the end of Q2 (CapitalAccounts.dc.html).
        foreach ([['Aarav Mehta', 1_50_000_00], ['Kabir Shah', 1_20_000_00], ['Ishita Rao', 80_000_00], ['Meera Iyer', 60_000_00]] as [$name, $amount]) {
            $partner = $partners[$name];
            $this->capital->record($company, CapitalMovementKind::Drawing, $partner, $amount, CarbonImmutable::create(2026, 9, 30), $this->accountOf($partner, $accounts), 'Q2 drawing · bank transfer', $by);
        }

        // Loan repayments: L-03 closes on 30 Sep; L-01 pays ₹40,000 a month from August.
        $this->loans->repay($company, $l3, 50_000_00, CarbonImmutable::create(2026, 6, 30), $bluefin, null, $by);
        $this->loans->repay($company, $l3, 50_000_00, CarbonImmutable::create(2026, 9, 30), $bluefin, null, $by);

        foreach ([[8, 31], [9, 30], [10, 31]] as [$m, $d]) {
            $this->loans->repay($company, $l1, 40_000_00, CarbonImmutable::create(2026, $m, $d), $vertex, null, $by);
        }

        // One settlement already made in July.
        $this->transfers->record($company, $sides['Vertex Labs'], $sides['Bluefin Infotech'], 1_85_000_00, CarbonImmutable::create(2026, 7, 10), 'UTR HDFC260710001834', $by);

        // This month so far: daily hours up to today, no salary yet (it runs on the 1st).
        $today = CarbonImmutable::today();
        $this->hours($company, $members, $today->startOfMonth(), $today, $by, daily: true);
    }

    private function vertexLabs(Company $company, CarbonImmutable $start, User $by): void
    {
        $account = $this->chart->companyAccount($company);
        $partners = Partner::query()->get()->keyBy('name');
        $project = Project::query()->where('name', 'Kiran Exports site')->value('id');

        foreach (range(0, 6) as $month) {
            $day = $start->addMonths($month);
            $this->income->create($company, new IncomeData(
                source: 'Kiran Exports — '.$day->format('M Y').' invoice',
                amountPaise: 1_20_000_00 + $month * 5_000_00,
                receivedOn: $day->addDays(6),
                categoryId: $this->category($company, 'income', 'Project')->id,
                broughtInBy: $partners['Aarav Mehta']->id,
                moneyAccountId: $account->id,
                receivedBy: null,
                projectId: is_numeric($project) ? (int) $project : null,
                notes: null,
            ), $by);
            $this->approved($company, $by, 'Tools — '.$day->format('M Y'), 'Software', $day->addDays(10), [$this->fromAccount($account, 12_500_00)], PayerMode::Company);
        }
    }

    /**
     * Submits an expense and approves it (posting it), as an Admin would.
     *
     * @param  list<PayerInput>  $payers
     */
    private function approved(Company $company, User $by, string $description, string $category, CarbonImmutable $date, array $payers, PayerMode $mode, ?int $projectId = null): void
    {
        $expense = $this->submitted($company, $by, $description, $category, $date, $payers, $mode, $projectId);
        $this->expenses->approve($company, $expense, $by);
    }

    /** @param list<PayerInput> $payers */
    private function submitted(Company $company, User $by, string $description, string $category, CarbonImmutable $date, array $payers, PayerMode $mode = PayerMode::Full, ?int $projectId = null): Expense
    {
        return $this->expenses->submit($company, new ExpenseData(
            description: $description,
            amountPaise: array_sum(array_map(fn (PayerInput $p) => $p->amountPaise, $payers)),
            spentOn: $date,
            categoryId: $this->category($company, 'expense', $category)->id,
            newCategory: null,
            projectId: $projectId,
            billable: false,
            notes: null,
            mode: $mode,
            payers: $payers,
        ), $by);
    }

    private function fromAccount(MoneyAccount $account, int $amount): PayerInput
    {
        return new PayerInput(PayerType::Company, null, null, $account->id, $amount);
    }

    /** @param array<string, MoneyAccount> $accounts */
    private function accountOf(Partner $partner, array $accounts): MoneyAccount
    {
        return $partner->side_id === null ? $this->chart->companyAccount($partner->company) : collect($accounts)->firstWhere('side_id', $partner->side_id) ?? throw new RuntimeException('No side account.');
    }

    /**
     * A month of timesheets (spec §24): each member's billable hours spread over the weekdays
     * on their usual project (hourly members bill exactly HOURS, so salaries match the books),
     * plus a little non-billable Internal time. `daily` logs a working day's hours instead.
     *
     * @param  Collection<int, Member>  $members
     */
    private function hours(Company $company, Collection $members, CarbonImmutable $from, CarbonImmutable $to, User $by, bool $daily = false): void
    {
        $projects = Project::query()->withoutGlobalScopes()->where('company_id', $company->id)->pluck('id', 'name');
        $days = array_values(array_filter(
            Hours::workingDays($from, Hours::WEEKDAYS),
            fn (CarbonImmutable $d) => $d->lessThanOrEqualTo($to),
        ));
        $rows = [];
        $now = now();

        foreach ($members as $i => $member) {
            $billable = $daily ? 0 : (self::HOURS[$member->name] ?? 150) * 100;
            $parts = $daily ? array_fill(0, count($days), [650, 700, 750, 800][$i % 4]) : Hours::distribute($billable, count($days));

            foreach ($days as $d => $day) {
                $rows[] = [
                    'company_id' => $company->id, 'member_id' => $member->id, 'partner_id' => $member->partner_id,
                    'project_id' => $projects[self::WORKS_ON[$member->name] ?? 'Saarthi portal'], 'date' => $day->toDateString(),
                    'hours_centi' => $parts[$d], 'billable' => true, 'notes' => null, 'created_by' => $by->id,
                    'created_at' => $now, 'updated_at' => $now,
                ];

                // Stand-ups and reviews: an hour of Internal time on Fridays.
                if ($day->isFriday()) {
                    $rows[] = [
                        'company_id' => $company->id, 'member_id' => $member->id, 'partner_id' => $member->partner_id,
                        'project_id' => $projects[Project::INTERNAL], 'date' => $day->toDateString(),
                        'hours_centi' => 100, 'billable' => false, 'notes' => 'Stand-up and review', 'created_by' => $by->id,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            TimeEntry::query()->insert($chunk);
        }
    }

    private function category(Company $company, string $kind, string $name): Category
    {
        return Category::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('kind', $kind)->where('name', $name)->firstOrFail();
    }

    /** @param Collection<string, Side> $sides */
    private function sideOf(Member $member, Collection $sides): string
    {
        $side = $sides->firstWhere('id', $member->partner->side_id);

        return $side === null ? 'Vertex Labs' : $side->name;
    }
}
