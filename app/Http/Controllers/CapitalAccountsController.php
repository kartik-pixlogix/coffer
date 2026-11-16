<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Capital\RecordCapitalMovement;
use App\Enums\AttributionMode;
use App\Enums\CapitalMovementKind;
use App\Http\Requests\Finance\CapitalMovementRequest;
use App\Ledger\FiscalYears;
use App\Ledger\Lines;
use App\Ledger\Reports;
use App\Ledger\Shares;
use App\Models\CapitalMovement;
use App\Models\Company;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\User;
use App\Services\Finance\FiscalYearFilter;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Capital accounts (CapitalAccounts.dc.html, spec §11): per partner, Opening +
 * Contributions − Drawings + Share of profit = Closing for the chosen fiscal year, read
 * from the books (Reports::capitalStatement, so profit comes from ProfitAllocator), the
 * KPIs, and the contribution and drawing ledgers with Record contribution / drawing.
 * Closed years are read-only.
 */
final class CapitalAccountsController extends Controller
{
    public function index(Request $request, CurrentCompany $current, Reports $reports, FiscalYears $years): Response
    {
        $company = $current->require();
        /** @var User $user */
        $user = $request->user();
        $fy = FiscalYearFilter::for($company, $request->query('fy'));
        $closed = $years->closedYearFor($company, $fy->start) !== null;
        $partners = Partner::query()->with('side')->orderBy('position')->orderBy('id')->get()->keyBy('id');
        $statement = $this->statement($company, $reports, $fy, $partners->all());

        $movements = CapitalMovement::query()
            ->with('moneyAccount:id,name')
            ->whereBetween('moved_on', [$fy->period->from?->toDateString(), $fy->period->to->toDateString()])
            ->orderByDesc('moved_on')->orderByDesc('id')
            ->get();
        $row = fn (CapitalMovement $m) => [
            'id' => $m->id,
            'date' => $m->moved_on->toDateString(),
            'partnerId' => $m->partner_id,
            'amountPaise' => $m->amount_paise,
            'note' => $m->note,
            'accountName' => $m->moneyAccount->name,
        ];
        $contributions = $movements->where('kind', CapitalMovementKind::Contribution);
        $drawings = $movements->where('kind', CapitalMovementKind::Drawing);
        $previous = collect($fy->props()['options'])->firstWhere('value', $fy->start->year - 1);

        return Inertia::render('capital/index', [
            'company' => [
                'name' => $company->name,
                'jv' => $company->isJv(),
                'pooled' => $company->attribution_mode === AttributionMode::JvPooled,
            ],
            'today' => now()->toDateString(),
            'fy' => [...$fy->props(), 'closed' => $closed, 'previousLabel' => $previous['label'] ?? null],
            'statement' => $statement,
            'kpis' => [
                'closingPaise' => array_sum(array_column($statement, 'closingPaise')),
                'contributionsPaise' => (int) $contributions->sum('amount_paise'),
                'contributionsCount' => $contributions->count(),
                'drawingsPaise' => (int) $drawings->sum('amount_paise'),
                'drawingsCount' => $drawings->count(),
                'netProfitPaise' => Lines::netProfit($company, $fy->toDate()),
                'asOf' => $fy->toDate()->to->toDateString(),
            ],
            'contributions' => $contributions->map($row)->values(),
            'drawings' => $drawings->map($row)->values(),
            'options' => [
                'partners' => $partners->values()->map(fn (Partner $p) => [
                    'id' => $p->id, 'name' => $p->name, 'colour' => $p->colour, 'sideId' => $p->side_id, 'active' => $p->archived_at === null,
                ]),
                'accounts' => MoneyAccount::query()->whereNull('archived_at')->orderBy('position')->orderBy('id')->get()
                    ->map(fn (MoneyAccount $a) => ['id' => $a->id, 'name' => $a->name, 'sideId' => $a->side_id, 'active' => true])->values(),
            ],
            'abilities' => ['manage' => $user->can('action.capital.manage') && ! $closed],
        ]);
    }

    public function store(CapitalMovementRequest $request, CurrentCompany $current, RecordCapitalMovement $record): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $m = $request->movement();
        $movement = $record->record($current->require(), $m['kind'], $m['partner'], $m['amount'], $m['date'], $m['account'], $m['note'], $user);
        $contribution = $m['kind'] === CapitalMovementKind::Contribution;

        activity('capital')->performedOn($movement)->causedBy($user)
            ->withProperties(['kind' => $m['kind']->value, 'amount_paise' => $m['amount'], 'partner_id' => $m['partner']->id])
            ->log($contribution ? 'recorded a contribution' : 'recorded a drawing');
        Inertia::flash('toast', ['type' => 'success', 'message' => Money::format($m['amount']).($contribution ? ' contribution recorded for ' : ' drawing recorded for ').$m['partner']->name]);

        return back();
    }

    public function destroy(Request $request, CapitalMovement $movement, CurrentCompany $current, RecordCapitalMovement $record): RedirectResponse
    {
        Gate::authorize('action.capital.manage');
        /** @var User $user */
        $user = $request->user();
        $record->delete($current->require(), $movement, $user);
        $contribution = $movement->kind === CapitalMovementKind::Contribution;

        activity('capital')->performedOn($movement)->causedBy($user)
            ->withProperties(['kind' => $movement->kind->value, 'amount_paise' => $movement->amount_paise])
            ->log($contribution ? 'deleted a contribution' : 'deleted a drawing');
        Inertia::flash('toast', ['type' => 'success', 'message' => $contribution ? 'Contribution deleted' : 'Drawing deleted']);

        return back();
    }

    /** The statement for the chosen year as CSV ("Export statements"). */
    public function export(Request $request, CurrentCompany $current, Reports $reports): StreamedResponse
    {
        $company = $current->require();
        $fy = FiscalYearFilter::for($company, $request->query('fy'));
        $partners = Partner::query()->with('side')->orderBy('position')->orderBy('id')->get()->keyBy('id');
        $statement = $this->statement($company, $reports, $fy, $partners->all());
        $label = (string) $fy->props()['label'];

        activity('capital')->causedBy($request->user())->withProperties(['fy' => $label])->log('exported capital statements');

        return response()->streamDownload(function () use ($statement): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, ['Partner', 'Side', 'Equity (%)', 'Opening (INR)', 'Contributions (INR)', 'Drawings (INR)', 'Share of profit (INR)', 'Closing (INR)'], escape: '');

            foreach ($statement as $row) {
                fputcsv($out, [
                    $row['name'], $row['sideName'], number_format($row['equityBps'] / 100, 2),
                    Money::plain($row['openingPaise']), Money::plain($row['contributionsPaise']), Money::plain($row['drawingsPaise']),
                    Money::plain($row['profitPaise']), Money::plain($row['closingPaise']),
                ], escape: '');
            }

            fclose($out);
        }, 'capital-statements-'.str_replace(' ', '-', strtolower($label)).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Reports::capitalStatement for the year, with each partner's side and effective
     * equity, grouped by side in a JV (spec §11).
     *
     * @param  array<int, Partner>  $partners
     * @return list<array{partnerId: int, name: string, colour: int, sideId: int|null, sideName: string|null, equityBps: int, openingPaise: int, contributionsPaise: int, drawingsPaise: int, profitPaise: int, closingPaise: int}>
     */
    private function statement(Company $company, Reports $reports, FiscalYearFilter $fy, array $partners): array
    {
        $weights = Shares::effective($company);
        $total = array_sum($weights);
        $sideOrder = fn (?Partner $p) => $p?->side === null ? 0 : $p->side->position;

        $rows = array_map(function (array $r) use ($partners, $weights, $total) {
            $partner = $partners[$r['partner_id']] ?? null;

            return [
                'partnerId' => $r['partner_id'],
                'name' => $r['name'],
                'colour' => (int) ($partner->colour ?? 1),
                'sideId' => $r['side_id'],
                'sideName' => $partner?->side?->name,
                'equityBps' => $total > 0 ? intdiv(($weights[$r['partner_id']] ?? 0) * 10000, $total) : 0,
                'openingPaise' => $r['opening'],
                'contributionsPaise' => $r['contributions'],
                'drawingsPaise' => $r['drawings'],
                'profitPaise' => $r['profit'],
                'closingPaise' => $r['closing'],
            ];
        }, $reports->capitalStatement($company, $fy->period));

        // Grouped by side (in side order), keeping partner order within each side.
        $order = array_flip(array_keys($partners));
        usort($rows, fn (array $a, array $b) => [$sideOrder($partners[$a['partnerId']] ?? null), $order[$a['partnerId']] ?? 0]
            <=> [$sideOrder($partners[$b['partnerId']] ?? null), $order[$b['partnerId']] ?? 0]);

        return $rows;
    }
}
