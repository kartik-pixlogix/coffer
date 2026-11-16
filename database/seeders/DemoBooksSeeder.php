<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Ledger\ChartOfAccounts;
use App\Ledger\OpeningBalance;
use App\Ledger\PayerLine;
use App\Ledger\Postings;
use App\Models\Category;
use App\Models\Company;
use App\Models\Member;
use App\Models\Partner;
use App\Models\Side;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Demo books for the design's figures (spec §24, D32), posted straight to the ledger
 * until Phase 5 brings Income and Expense records:
 *
 * Saarthi Portal JV, Apr–Oct 2026: income ₹62,10,000, expenses ₹43,67,650, net profit and
 * current balance ₹18,42,350, and "Vertex Labs owes Bluefin Infotech ₹3,24,600" after one
 * ₹1,00,000 transfer. Vertex Labs collects ₹36,00,000 and spends ₹22,54,225; Bluefin
 * collects ₹26,10,000 and spends ₹21,13,425.
 */
class DemoBooksSeeder extends Seeder
{
    /** Monthly income per side (Apr → Oct), in rupees. */
    private const array INCOME = [
        'Vertex Labs' => [450000, 500000, 550000, 480000, 540000, 520000, 560000],
        'Bluefin Infotech' => [320000, 360000, 390000, 350000, 410000, 380000, 400000],
    ];

    /** What each side spends in total (salaries included), in paise. */
    private const array SPEND = [
        'Vertex Labs' => 22_54_225_00,
        'Bluefin Infotech' => 21_13_425_00,
    ];

    /** Hours billed each month by hourly members (fixed members cost their monthly rate). */
    private const array HOURS = [
        'Rohan Desai' => 160, 'Neha Joshi' => 160, 'Arjun Nair' => 120, 'Priya Menon' => 80,
        'Sana Qureshi' => 160, 'Tanvi Shah' => 100,
    ];

    private const array PROJECTS = ['Saarthi portal', 'Asha Retail store', 'Kiran Exports site'];

    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly OpeningBalance $opening,
        private readonly Postings $postings,
    ) {}

    public function run(): void
    {
        $start = CarbonImmutable::create(2026, 4, 1);
        $aarav = User::query()->where('email', 'aarav@vertexlabs.in')->firstOrFail();

        foreach (Company::query()->orderBy('id')->get() as $company) {
            $this->chart->setUp($company);
            $this->opening->post($company, $start, $aarav);
        }

        $this->saarthi(Company::query()->where('name', 'Saarthi Portal JV')->firstOrFail(), $start, $aarav);
        $this->vertexLabs(Company::query()->where('name', 'Vertex Labs Pvt Ltd')->firstOrFail(), $start, $aarav);
    }

    private function saarthi(Company $company, CarbonImmutable $start, User $by): void
    {
        $sides = Side::query()->withoutGlobalScopes()->where('company_id', $company->id)->get()->keyBy('name');
        $partners = Partner::query()->withoutGlobalScopes()->where('company_id', $company->id)->get()->keyBy('name');
        $members = Member::query()->withoutGlobalScopes()->where('company_id', $company->id)->with(['partner' => fn ($q) => $q->withoutGlobalScopes()])->get();
        $broughtBy = ['Vertex Labs' => ['Aarav Mehta', 'Ishita Rao'], 'Bluefin Infotech' => ['Kabir Shah', 'Meera Iyer']];
        $spent = ['Vertex Labs' => 0, 'Bluefin Infotech' => 0];

        foreach (range(0, 6) as $month) {
            $monthStart = $start->addMonths($month);
            $monthEnd = $monthStart->endOfMonth()->startOfDay();

            // Income into each side's account, brought in by its partners in turn.
            foreach (self::INCOME as $sideName => $amounts) {
                $account = $this->chart->sideAccount($sides[$sideName]);
                $half = intdiv($amounts[$month] * 100, 2);

                foreach ([$half, $amounts[$month] * 100 - $half] as $i => $amount) {
                    $this->postings->incomeToAccount(
                        $company,
                        $monthStart->addDays(4 + $i * 12),
                        $amount,
                        $this->category($company, 'income', $i === 0 ? 'Project' : 'Consulting'),
                        $account,
                        $partners[$broughtBy[$sideName][($month + $i) % 2]],
                        memo: self::PROJECTS[($month + $i) % 3],
                        by: $by,
                    );
                }
            }

            // Salaries on the last day of the month (spec §8.3).
            foreach ($members as $member) {
                $amount = isset(self::HOURS[$member->name]) ? self::HOURS[$member->name] * $member->rate_paise : $member->rate_paise;
                $this->postings->salary($company, $member, $amount, $monthEnd, by: $by);
                $spent[$this->sideOf($member, $sides)] += $amount;
            }

            // Running costs from each side's account.
            foreach (['Vertex Labs' => [['Software', 18_450_00], ['Marketing', 35_000_00], ['Office & misc', 22_000_00]], 'Bluefin Infotech' => [['Software', 24_300_00], ['Hardware', 15_000_00], ['Office & misc', 18_500_00]]] as $sideName => $costs) {
                foreach ($costs as $i => [$category, $amount]) {
                    $this->postings->expense($company, $monthStart->addDays(8 + $i * 5), $this->category($company, 'expense', $category), [PayerLine::company($amount, $this->chart->sideAccount($sides[$sideName]))], memo: $category, by: $by);
                    $spent[$sideName] += $amount;
                }
            }
        }

        $vertex = $this->chart->sideAccount($sides['Vertex Labs']);
        $bluefin = $this->chart->sideAccount($sides['Bluefin Infotech']);
        $rohan = $members->firstWhere('name', 'Rohan Desai');

        // Paid personally, then reimbursed: a member's travel and a partner's AWS bill.
        $this->postings->expense($company, $start->addMonths(5)->addDays(3), $this->category($company, 'expense', 'Travel'), [PayerLine::member(4_200_00, $rohan)], memo: 'Client visit — Asha Retail', by: $by);
        $this->postings->reimbursement($company, $start->addMonths(5)->addDays(10), 4_200_00, $rohan, $vertex, by: $by);
        $spent['Vertex Labs'] += 4_200_00;

        $this->postings->expense($company, $start->addMonths(6)->addDays(2), $this->category($company, 'expense', 'Software'), [PayerLine::partner(18_450_00, $partners['Meera Iyer'])], memo: 'AWS — October bill', by: $by);
        $this->postings->reimbursement($company, $start->addMonths(6)->addDays(9), 18_450_00, $partners['Meera Iyer'], $bluefin, by: $by);
        $spent['Bluefin Infotech'] += 18_450_00;

        // Whatever is left of each side's spend lands as travel and marketing (keeps the design totals exact).
        foreach (['Vertex Labs' => $vertex, 'Bluefin Infotech' => $bluefin] as $sideName => $account) {
            $rest = self::SPEND[$sideName] - $spent[$sideName];

            if ($rest <= 0) {
                throw new RuntimeException("Demo spend for {$sideName} overshoots by ".(-$rest).' paise.');
            }

            $travel = intdiv($rest, 3);
            $this->postings->expense($company, $start->addMonths(4)->addDays(14), $this->category($company, 'expense', 'Travel'), [PayerLine::company($travel, $account)], memo: 'Team travel', by: $by);
            $this->postings->expense($company, $start->addMonths(6)->addDays(14), $this->category($company, 'expense', 'Marketing'), [PayerLine::company($rest - $travel, $account)], memo: 'Launch campaign', by: $by);
        }

        // One settlement already made in August.
        $this->postings->settlementTransfer($company, $start->addMonths(4)->addDays(20), $sides['Vertex Labs'], $sides['Bluefin Infotech'], 1_00_000_00, 'NEFT 0842', by: $by);
    }

    private function vertexLabs(Company $company, CarbonImmutable $start, User $by): void
    {
        $account = $this->chart->companyAccount($company);
        $partners = Partner::query()->withoutGlobalScopes()->where('company_id', $company->id)->get()->keyBy('name');

        foreach (range(0, 6) as $month) {
            $day = $start->addMonths($month);
            $this->postings->incomeToAccount($company, $day->addDays(6), 1_20_000_00 + $month * 5_000_00, $this->category($company, 'income', 'Project'), $account, $partners['Aarav Mehta'], memo: 'Kiran Exports site', by: $by);
            $this->postings->expense($company, $day->addDays(10), $this->category($company, 'expense', 'Software'), [PayerLine::company(12_500_00, $account)], memo: 'Tools', by: $by);
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
