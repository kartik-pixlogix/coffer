<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Reimbursements\PayReimbursement;
use App\Http\Requests\Finance\ReimbursementRequest;
use App\Models\Reimbursement;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Personal-card payouts from the Expenses page (D50, spec §4.2): pay a partner or member
 * back from a money account, or undo a payout (a reversal). Converting a partner's
 * balance into a loan posts to Partner loans (`partner-loans.store`, source `card`).
 */
final class ReimbursementsController extends Controller
{
    public function store(ReimbursementRequest $request, CurrentCompany $current, PayReimbursement $pay): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $p = $request->payout();
        $payout = $pay->pay($current->require(), $p['payee'], $p['amount'], $p['date'], $p['account'], $p['note'], $user);

        activity('expenses')->performedOn($payout)->causedBy($user)
            ->withProperties(['amount_paise' => $p['amount'], 'payee' => $p['payee']->name])
            ->log('paid out a reimbursement');
        Inertia::flash('toast', ['type' => 'success', 'message' => Money::format($p['amount']).' paid back to '.$p['payee']->name]);

        return back();
    }

    public function destroy(Request $request, Reimbursement $reimbursement, CurrentCompany $current, PayReimbursement $pay): RedirectResponse
    {
        Gate::authorize('action.capital.manage');
        /** @var User $user */
        $user = $request->user();
        $pay->delete($current->require(), $reimbursement, $user);

        activity('expenses')->performedOn($reimbursement)->causedBy($user)
            ->withProperties(['amount_paise' => $reimbursement->amount_paise])
            ->log('deleted a reimbursement payout');
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payout deleted']);

        return back();
    }
}
