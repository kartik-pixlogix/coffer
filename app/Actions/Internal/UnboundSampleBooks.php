<?php

declare(strict_types=1);

namespace App\Actions\Internal;

use App\Actions\Capital\RecordCapitalMovement;
use App\Actions\Expenses\ExpenseData;
use App\Actions\Expenses\PayerInput;
use App\Actions\Expenses\RecordExpense;
use App\Actions\Income\IncomeData;
use App\Actions\Income\RecordIncome;
use App\Actions\Loans\LoanData;
use App\Actions\Loans\RecordLoan;
use App\Actions\Reimbursements\PayReimbursement;
use App\Enums\CapitalMovementKind;
use App\Enums\CostType;
use App\Enums\MemberStatus;
use App\Enums\PayerMode;
use App\Enums\PayerType;
use App\Enums\ProjectStatus;
use App\Ledger\ChartOfAccounts;
use App\Ledger\FiscalYears;
use App\Models\Category;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Member;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Team\SalaryGenerator;
use App\Services\Timesheets\Hours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Sample books for the Unbound companies (D145), so every page has something to show: a
 * team billed to the partners, monthly client income, running costs, timesheets and the
 * salaries made from them, capital in, a partner loan with repayments, drawings, personal
 * card spend (one paid back, one still owed) and expenses waiting for approval or turned
 * down. Everything goes through the app's own Actions, so it posts balanced journal
 * entries. Dates run from the start of the current financial year up to today.
 */
final class UnboundSampleBooks
{
    /**
     * Per company: projects (name → client), the team (name, designation, cost type, rate in
     * paise, billed to, project, billable hours a month), monthly income per project in
     * rupees, and monthly running costs (category, rupees, description).
     */
    private const array BOOKS = [
        'SOL' => [
            'projects' => ['Shopify store — Asha Retail' => 'Asha Retail', 'Corporate website — Kiran Exports' => 'Kiran Exports', 'Design system — Nimbus Health' => 'Nimbus Health'],
            'team' => [
                ['Neel Parikh', 'Shopify Developer', CostType::Hourly, 450_00, 'Kartik', 'Shopify store — Asha Retail', 160],
                ['Aman Verma', 'WordPress Developer', CostType::Hourly, 350_00, 'Kartik', 'Corporate website — Kiran Exports', 150],
                ['Ritika Sen', 'UI/UX Designer', CostType::FixedMonthly, 45_000_00, 'Khyati', 'Design system — Nimbus Health', 160],
                ['Sneha Kulkarni', 'QA Engineer', CostType::Hourly, 250_00, 'Shyam', 'Shopify store — Asha Retail', 140],
                ['Varun Malhotra', 'Project Manager', CostType::FixedMonthly, 55_000_00, 'Shyam', 'Corporate website — Kiran Exports', 160],
            ],
            'income' => [420000, 310000, 240000],
            'costs' => [['Software', 28_500, 'Shopify Partner, Figma and GitHub'], ['Marketing', 40_000, 'Google Ads — agency leads'], ['Office & misc', 35_000, 'Co-working desks']],
            'capital' => 8_00_000,
            'loan' => ['Karan', 2_00_000, 'Laptops for the design team', 50_000],
        ],
        'SVC' => [
            'projects' => ['Security audit — Orbit Finance' => 'Orbit Finance', 'DPDPA readiness — Lumen Clinics' => 'Lumen Clinics', 'Managed IT — Sahyadri Logistics' => 'Sahyadri Logistics'],
            'team' => [
                ['Rahul Bhatt', 'Security Analyst', CostType::FixedMonthly, 60_000_00, 'Faiz', 'Security audit — Orbit Finance', 160],
                ['Pooja Nair', 'Compliance Consultant', CostType::FixedMonthly, 50_000_00, 'Faiz', 'DPDPA readiness — Lumen Clinics', 160],
                ['Imran Sheikh', 'Network Engineer', CostType::Hourly, 400_00, 'Kartik', 'Managed IT — Sahyadri Logistics', 150],
                ['Ananya Ghosh', 'Privacy Analyst', CostType::Hourly, 380_00, 'Khyati', 'DPDPA readiness — Lumen Clinics', 120],
            ],
            'income' => [280000, 220000, 160000],
            'costs' => [['Software', 32_000, 'Burp Suite, Nessus and Microsoft 365'], ['Hardware', 25_000, 'Test lab and spare devices'], ['Office & misc', 30_000, 'Office rent share']],
            'capital' => 10_00_000,
            'loan' => ['Faiz', 3_00_000, 'Hardware stock for Sahyadri rollout', 75_000],
        ],
        'INN' => [
            'projects' => ['ERP — Shakti Manufacturing' => 'Shakti Manufacturing', 'Product build — FitLoop app' => 'FitLoop', 'Performance ads — Urban Nest' => 'Urban Nest'],
            'team' => [
                ['Siddharth Rao', 'ERP Developer', CostType::Hourly, 500_00, 'Kartik', 'ERP — Shakti Manufacturing', 160],
                ['Kavya Iyer', 'Full-stack Developer', CostType::Hourly, 450_00, 'Harshal', 'Product build — FitLoop app', 160],
                ['Mohit Arora', 'Performance Marketer', CostType::FixedMonthly, 40_000_00, 'Khyati', 'Performance ads — Urban Nest', 160],
                ['Zoya Khan', 'Ads Specialist', CostType::Hourly, 300_00, 'Khyati', 'Performance ads — Urban Nest', 120],
            ],
            'income' => [380000, 260000, 150000],
            'costs' => [['Software', 30_000, 'AWS, Jira and Vercel'], ['Marketing', 45_000, 'Meta Ads — own campaigns'], ['Office & misc', 28_000, 'Office rent share']],
            'capital' => 12_00_000,
            'loan' => ['Harshal', 2_50_000, 'Cloud credits prepayment', 50_000],
        ],
    ];

    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly SalaryGenerator $salaries,
        private readonly RecordIncome $income,
        private readonly RecordExpense $expenses,
        private readonly PayReimbursement $payouts,
        private readonly RecordCapitalMovement $capital,
        private readonly RecordLoan $loans,
    ) {}

    /** Runs inside the company (CurrentCompany). */
    public function post(Company $company, string $code, User $by): void
    {
        $book = self::BOOKS[$code];
        $today = CarbonImmutable::today();
        $start = FiscalYears::startOf($company, $today);
        $account = $this->chart->companyAccount($company);
        $partners = Partner::query()->with('user')->orderBy('position')->get()->keyBy('name');
        $byName = fn (string $first): User => $partners[$first]->user ?? $by;

        $projects = [];
        /** @var list<array{0: int, 1: string}> $invoiced project id and client, in order */
        $invoiced = [];

        foreach ($book['projects'] as $name => $client) {
            $projects[$name] = Project::query()->create(['name' => $name, 'client' => $client, 'status' => ProjectStatus::Active])->id;
            $invoiced[] = [$projects[$name], $client];
        }

        $members = collect();

        foreach ($book['team'] as [$name, $designation, $costType, $rate, $billedTo, $project, $hours]) {
            $members->push(Member::query()->create([
                'partner_id' => $partners[$billedTo]->id,
                'name' => $name,
                'designation' => $designation,
                'cost_type' => $costType,
                'rate_paise' => $rate,
                'status' => MemberStatus::Active,
            ]));
        }

        // Capital in at the start of the year, split by equity.
        foreach ($partners as $partner) {
            $amount = intdiv($book['capital'] * 100 * $partner->equity_bps, 10_000);
            $this->capital->record($company, CapitalMovementKind::Contribution, $partner, $amount, $start->addDays(3), $account, 'Initial capital', $by);
        }

        // Every finished month of this financial year.
        $months = [];

        for ($month = $start; $month->endOfMonth()->lessThan($today); $month = $month->addMonth()) {
            $months[] = $month;
        }

        $loanPartner = $partners[$book['loan'][0]];
        $loan = null;

        foreach ($months as $i => $month) {
            foreach ($invoiced as $p => [$projectId, $client]) {
                // A little movement month to month, so the charts aren't flat.
                $rupees = $book['income'][$p] + (($i * 7 + $p * 3) % 5 - 2) * 15_000;
                $this->income->create($company, new IncomeData(
                    source: $client.' — '.$month->format('M Y').' invoice',
                    amountPaise: $rupees * 100,
                    receivedOn: $month->addDays(4 + $p * 6),
                    categoryId: $this->category($company, 'income', $p === 2 ? 'Consulting' : 'Project')->id,
                    broughtInBy: $partners->values()[($i + $p) % $partners->count()]->id,
                    moneyAccountId: $account->id,
                    receivedBy: null,
                    projectId: $projectId,
                    notes: null,
                ), $by);
            }

            $this->hours($company, $members, $book['team'], $projects, $month, $month->endOfMonth()->startOfDay(), $by);

            foreach ($members as $member) {
                $this->salaries->runMember($company, $member, $month, $by);
            }

            foreach ($book['costs'] as $c => [$category, $rupees, $what]) {
                $this->approved($company, $by, $by, $what.' — '.$month->format('M Y'), $category, $month->addDays(9 + $c * 4), [$this->fromAccount($account, $rupees * 100)], PayerMode::Company);
            }

            // The partner loan in the second month, paid back in instalments after that.
            if ($i === 1) {
                $loan = $this->loans->lend($company, new LoanData($loanPartner, $book['loan'][1] * 100, $month->addDays(2), $account, $book['loan'][2], $book['loan'][3] * 100), $by);
            } elseif ($loan !== null && $i > 2) {
                $this->loans->repay($company, $loan, $book['loan'][3] * 100, $month->endOfMonth()->startOfDay(), $account, null, $by);
            }

            // Quarterly drawings, split by equity.
            if ($month->month % 3 === 0) {
                foreach ($partners as $partner) {
                    $amount = intdiv(3_00_000_00 * $partner->equity_bps, 10_000);
                    $this->capital->record($company, CapitalMovementKind::Drawing, $partner, $amount, $month->endOfMonth()->startOfDay(), $account, $month->format('M Y').' drawing · bank transfer', $by);
                }
            }
        }

        $first = $members->first();
        $last = $members->last();
        $recent = fn (int $days): CarbonImmutable => $today->subDays($days)->max($start);

        if ($first instanceof Member && $last instanceof Member) {
            // A member's client visit, paid personally and paid back.
            $this->approved($company, $by, $by, 'Client visit — travel and stay', 'Travel', $recent(20), [new PayerInput(PayerType::Member, null, $first->id, null, 6_800_00)], PayerMode::Full);
            $this->payouts->pay($company, $first, 6_800_00, $recent(15), $account, 'NEFT', $by);

            // A partner's card, still to pay back.
            $owed = $partners->values()[1];
            $this->approved($company, $by, $by, 'Domain and SSL renewals', 'Software', $recent(6), [new PayerInput(PayerType::Partner, $owed->id, null, null, 9_450_00)], PayerMode::Full);

            // Waiting for a decision, and turned down.
            $this->submitted($company, $byName('Shyam'), 'Laptop for '.explode(' ', $last->name)[0], 'Hardware', $recent(3), [$this->fromAccount($account, 92_000_00)], PayerMode::Company);
            $this->submitted($company, $byName('Karan'), 'Conference passes — two seats', 'Marketing', $recent(2), [new PayerInput(PayerType::Partner, $partners['Karan']->id, null, null, 18_000_00)]);
            $rejected = $this->submitted($company, $byName('Shyam'), 'Ergonomic chair', 'Office & misc', $recent(9), [new PayerInput(PayerType::Member, null, $last->id, null, 21_500_00)]);
            $this->expenses->reject($rejected, 'Not in this quarter’s budget. Raise it again in the next one.', $by);
        }

        // This month so far: daily hours up to today (salaries run on the 1st).
        if ($today->startOfMonth()->greaterThanOrEqualTo($start)) {
            $this->hours($company, $members, $book['team'], $projects, $today->startOfMonth(), $today, $by, daily: true);
        }
    }

    /**
     * Each member's billable hours for the month spread over the weekdays on their project,
     * plus an hour of Internal time on Fridays. `daily` logs a normal working day instead.
     *
     * @param  Collection<int, Member>  $members
     * @param  list<array{0: string, 1: string, 2: CostType, 3: int, 4: string, 5: string, 6: int}>  $team
     * @param  array<string, int>  $projects
     */
    private function hours(Company $company, Collection $members, array $team, array $projects, CarbonImmutable $from, CarbonImmutable $to, User $by, bool $daily = false): void
    {
        $internal = Project::query()->where('name', Project::INTERNAL)->value('id');
        $days = array_values(array_filter(Hours::workingDays($from, Hours::WEEKDAYS), fn (CarbonImmutable $d) => $d->betweenIncluded($from, $to)));
        $rows = [];
        $now = now();

        foreach ($members as $i => $member) {
            $parts = $daily ? array_fill(0, count($days), [700, 750, 800][$i % 3]) : Hours::distribute($team[$i][6] * 100, count($days));

            foreach ($days as $d => $day) {
                $row = ['company_id' => $company->id, 'member_id' => $member->id, 'partner_id' => $member->partner_id, 'date' => $day->toDateString(), 'created_by' => $by->id, 'created_at' => $now, 'updated_at' => $now];
                $rows[] = $row + ['project_id' => $projects[$team[$i][5]], 'hours_centi' => $parts[$d], 'billable' => true, 'notes' => null];

                if ($day->isFriday() && is_numeric($internal)) {
                    $rows[] = $row + ['project_id' => (int) $internal, 'hours_centi' => 100, 'billable' => false, 'notes' => 'Stand-up and review'];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            TimeEntry::query()->insert($chunk);
        }
    }

    /** @param list<PayerInput> $payers */
    private function approved(Company $company, User $submitter, User $approver, string $description, string $category, CarbonImmutable $date, array $payers, PayerMode $mode): void
    {
        $this->expenses->approve($company, $this->submitted($company, $submitter, $description, $category, $date, $payers, $mode), $approver);
    }

    /** @param list<PayerInput> $payers */
    private function submitted(Company $company, User $by, string $description, string $category, CarbonImmutable $date, array $payers, PayerMode $mode = PayerMode::Full): Expense
    {
        return $this->expenses->submit($company, new ExpenseData(
            description: $description,
            amountPaise: array_sum(array_map(fn (PayerInput $p) => $p->amountPaise, $payers)),
            spentOn: $date,
            categoryId: $this->category($company, 'expense', $category)->id,
            newCategory: null,
            projectId: null,
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

    private function category(Company $company, string $kind, string $name): Category
    {
        return Category::query()->where('company_id', $company->id)->where('kind', $kind)->where('name', $name)->firstOrFail();
    }
}
