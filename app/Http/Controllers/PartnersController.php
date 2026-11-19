<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ledger\FiscalYears;
use App\Ledger\PartnerMetrics;
use App\Ledger\Period;
use App\Ledger\SettlementCalculator;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\Side;
use App\Models\User;
use App\Support\Csv;
use App\Support\CurrentCompany;
use App\Support\Equity;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Partners (Partners.dc.html, spec §6): each partner's revenue, expenses, net contribution
 * and hours for a fiscal year; JV partners grouped under their sides with the settlement
 * pill, Single partners in one grid (D39).
 */
final class PartnersController extends Controller
{
    /** "Export" on Partner detail (P3, D116): the same rows for the fiscal year, as CSV. */
    public function export(Request $request, CurrentCompany $current, PartnerMetrics $metrics): StreamedResponse
    {
        $company = $current->require();
        $today = CarbonImmutable::today();
        $years = $this->years($company, $today);
        $start = $years[$request->integer('fy')] ?? FiscalYears::startOf($company, $today);
        $figures = $metrics->forPeriod($company, Period::between($start, $start->addYear()->subDay()));
        $partners = Partner::query()->active()->with('side')->orderBy('position')->orderBy('id')->get();
        $label = FiscalYears::label($company, $start);

        activity('partners')->performedOn($company)->causedBy($request->user())->withProperties(['fy' => $label])->log('exported partners');

        return response()->streamDownload(function () use ($partners, $figures, $company): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            Csv::put($out, ['Partner', ...($company->isJv() ? ['Side'] : []), 'Equity (%)', 'Revenue (INR)', 'Direct expenses (INR)', 'Other expenses (INR)', 'Salaries (INR)', 'Expenses (INR)', 'Net (INR)', 'Hours']);

            foreach ($partners as $p) {
                $f = $figures[$p->id] ?? ['revenue' => 0, 'direct' => 0, 'other' => 0, 'salary' => 0, 'hoursCenti' => 0];
                $expenses = $f['direct'] + $f['other'] + $f['salary'];
                Csv::put($out, [
                    $p->name, ...($company->isJv() ? [$p->side?->name] : []), number_format($p->equity_bps / 100, 2),
                    Money::plain($f['revenue']), Money::plain($f['direct']), Money::plain($f['other']), Money::plain($f['salary']),
                    Money::plain($expenses), Money::plain($f['revenue'] - $expenses), number_format($f['hoursCenti'] / 100, 2, '.', ''),
                ]);
            }

            fclose($out);
        }, 'partners-'.str_replace(' ', '-', strtolower($label)).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function index(Request $request, CurrentCompany $current, PartnerMetrics $metrics, SettlementCalculator $settlements): Response
    {
        $company = $current->require();
        $today = CarbonImmutable::today();
        $years = $this->years($company, $today);
        $asked = $request->integer('fy');
        $start = $years[$asked] ?? FiscalYears::startOf($company, $today);
        $period = Period::between($start, $start->addYear()->subDay());

        $figures = $metrics->forPeriod($company, $period);
        $sides = Side::query()->orderBy('position')->orderBy('id')->get();
        $partners = Partner::query()->active()->with('side')->orderBy('position')->orderBy('id')->get();
        $settlement = $company->isJv() ? $settlements->calculate($company, $period) : null;
        /** @var User $user */
        $user = $request->user();

        $rows = $partners->map(function (Partner $p) use ($figures): array {
            $f = $figures[$p->id] ?? ['revenue' => 0, 'direct' => 0, 'other' => 0, 'salary' => 0, 'hoursCenti' => 0];
            $expenses = $f['direct'] + $f['other'] + $f['salary'];

            return [
                'id' => $p->id,
                'name' => $p->name,
                'role' => $p->role->value,
                'colour' => $p->colour,
                'sideId' => $p->side_id,
                'equityBps' => $p->equity_bps,
                'overallBps' => $p->side_id === null ? $p->equity_bps : intdiv($p->side->equity_bps * $p->equity_bps, Equity::WHOLE),
                'revenuePaise' => $f['revenue'],
                'directPaise' => $f['direct'],
                'otherPaise' => $f['other'],
                'salaryPaise' => $f['salary'],
                'expensesPaise' => $expenses,
                'netPaise' => $f['revenue'] - $expenses,
                'hoursCenti' => $f['hoursCenti'],
            ];
        })->values();

        return Inertia::render('partners/index', [
            'company' => ['name' => $company->name, 'jv' => $company->isJv()],
            'fy' => [
                'start' => $start->year,
                'label' => FiscalYears::label($company, $start),
                'options' => array_map(fn (int $year, CarbonImmutable $s) => ['value' => $year, 'label' => FiscalYears::label($company, $s)], array_keys($years), $years),
                'current' => $period->contains($today),
            ],
            'partners' => $rows,
            'sides' => $company->isJv() ? $sides->map(function (Side $s) use ($rows, $settlement): array {
                $mine = $rows->where('sideId', $s->id);
                $row = $settlement?->sides[$s->id] ?? null;

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'shareBps' => $s->equity_bps,
                    'revenuePaise' => (int) $mine->sum('revenuePaise'),
                    'expensesPaise' => (int) $mine->sum('expensesPaise'),
                    'netPaise' => (int) $mine->sum('netPaise'),
                    'settlement' => $row === null ? null : ['status' => $row->status(), 'amountPaise' => abs($row->remaining)],
                ];
            })->values() : [],
            'abilities' => ['editEquity' => $user->can('page.settings')],
        ]);
    }

    /**
     * Fiscal years with books, oldest first: from the first entry's year to this one.
     *
     * @return array<int, CarbonImmutable> start year → first day
     */
    private function years(Company $company, CarbonImmutable $today): array
    {
        $first = JournalEntry::query()->min('date');
        $from = FiscalYears::startOf($company, $first !== null ? CarbonImmutable::parse((string) $first) : $today);
        $to = FiscalYears::startOf($company, $today);
        $years = [];

        for ($s = $from; $s->lessThanOrEqualTo($to); $s = $s->addYear()) {
            $years[$s->year] = $s;
        }

        return $years;
    }
}
