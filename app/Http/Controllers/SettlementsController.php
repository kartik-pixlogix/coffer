<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Settlements\RecordSettlementTransfer;
use App\Http\Requests\Finance\SettlementTransferRequest;
use App\Ledger\FiscalYears;
use App\Ledger\Reports;
use App\Ledger\SettlementCalculator;
use App\Ledger\SideSettlement;
use App\Models\SettlementTransfer;
use App\Models\Side;
use App\Models\User;
use App\Services\Finance\FiscalYearFilter;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settlements (Settlements.dc.html, spec §13, JV only): the JV balance card, the banner
 * ("A owes B ₹X", or the fewest transfers for more than two sides), the per-side table
 * from SettlementCalculator for the chosen fiscal year, and the recorded transfers with
 * Record / edit / delete (each posting or reversing). A Single company gets the
 * design's empty state.
 */
final class SettlementsController extends Controller
{
    public function index(Request $request, CurrentCompany $current, SettlementCalculator $calculator, Reports $reports, FiscalYears $years): Response
    {
        $company = $current->require();
        /** @var User $user */
        $user = $request->user();

        // P12 (D116): Settlements is a joint-venture page; the menu hides it elsewhere.
        abort_unless($company->isJv(), 404);

        $fy = FiscalYearFilter::for($company, $request->query('fy'));
        $closed = $years->closedYearFor($company, $fy->start) !== null;
        $result = $calculator->calculate($company, $fy->period);
        $figures = $reports->figures($company, $fy->period);
        $sides = Side::query()->orderBy('position')->orderBy('id')->get();

        $transfers = SettlementTransfer::query()
            ->with(['fromSide:id,name', 'toSide:id,name', 'creator:id,name', 'voucherEntry:id,voucher_no'])
            ->whereBetween('transferred_on', [$fy->period->from?->toDateString(), $fy->period->to->toDateString()])
            ->orderByDesc('transferred_on')->orderByDesc('id')
            ->get();

        return Inertia::render('settlements/index', [
            'company' => ['name' => $company->name, 'jv' => true],
            'today' => now()->toDateString(),
            'fy' => [...$fy->props(), 'closed' => $closed],
            'asOf' => $fy->toDate()->to->toDateString(),
            'balance' => [
                'currentPaise' => $figures['currentBalance'],
                'openingPaise' => $figures['opening'],
                'incomePaise' => $figures['income'],
                'expensesPaise' => $figures['expenses'],
                'capitalAndLoansPaise' => $figures['capitalAndLoans'],
                'capitalPaise' => $figures['capital'],
                'personalPaise' => $figures['personal'],
            ],
            'netProfitPaise' => $result->netProfit,
            'sides' => array_values(array_map(fn (SideSettlement $s) => [
                'id' => $s->side->id,
                'name' => $s->side->name,
                'shareBps' => $s->shareBps,
                'collectedPaise' => $s->collected,
                'paidPaise' => $s->paid,
                'netHeldPaise' => $s->netHeld,
                'pooledPaise' => $s->pooled,
                'entitledPaise' => $s->entitled,
                'paidOutPaise' => $s->paidOut,
                'paidInPaise' => $s->paidIn,
                'remainingPaise' => $s->remaining,
                'status' => $s->status(),
            ], $result->sides)),
            'suggested' => array_map(fn (array $t) => [
                'fromId' => $t['from']->id,
                'fromName' => $t['from']->name,
                'toId' => $t['to']->id,
                'toName' => $t['to']->name,
                'amountPaise' => $t['amount'],
            ], $result->transfers),
            'transfers' => $transfers->map(fn (SettlementTransfer $t) => [
                'id' => $t->id,
                'date' => $t->transferred_on->toDateString(),
                'voucher' => $t->voucher(),
                'fromId' => $t->from_side_id,
                'fromName' => $t->fromSide->name,
                'toId' => $t->to_side_id,
                'toName' => $t->toSide->name,
                'amountPaise' => $t->amount_paise,
                'reference' => $t->reference,
                'by' => $t->creator?->name,
            ])->values(),
            'sideOptions' => $sides->map(fn (Side $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'abilities' => ['record' => $user->can('action.settlements.record') && ! $closed],
        ]);
    }

    public function store(SettlementTransferRequest $request, CurrentCompany $current, RecordSettlementTransfer $record): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $t = $request->transfer();
        $transfer = $record->record($current->require(), $t['from'], $t['to'], $t['amount'], $t['date'], $t['reference'], $user);

        activity('settlements')->performedOn($transfer)->causedBy($user)
            ->withProperties(['amount_paise' => $t['amount'], 'from' => $t['from']->name, 'to' => $t['to']->name])
            ->log('recorded a settlement transfer');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transfer of '.Money::format($t['amount']).' recorded']);

        return back();
    }

    public function update(SettlementTransferRequest $request, SettlementTransfer $transfer, CurrentCompany $current, RecordSettlementTransfer $record): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $t = $request->transfer();
        $record->update($current->require(), $transfer, $t['from'], $t['to'], $t['amount'], $t['date'], $t['reference'], $user);

        activity('settlements')->performedOn($transfer)->causedBy($user)
            ->withProperties(['amount_paise' => $t['amount'], 'from' => $t['from']->name, 'to' => $t['to']->name])
            ->log('edited a settlement transfer');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transfer updated']);

        return back();
    }

    public function destroy(Request $request, SettlementTransfer $transfer, CurrentCompany $current, RecordSettlementTransfer $record): RedirectResponse
    {
        Gate::authorize('action.settlements.record');
        /** @var User $user */
        $user = $request->user();
        $record->delete($current->require(), $transfer, $user);

        activity('settlements')->performedOn($transfer)->causedBy($user)->withProperties(['amount_paise' => $transfer->amount_paise])->log('deleted a settlement transfer');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transfer deleted']);

        return back();
    }
}
