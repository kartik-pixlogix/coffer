<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ExpenseStatus;
use App\Enums\PayerType;
use App\Models\Expense;
use App\Models\User;
use App\Services\Dashboard\DashboardData;
use App\Services\Dashboard\DashboardVaultPanel;
use App\Services\Reports\ReportPeriod;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Dashboard (Main.dc.html, spec §5): the period control, KPIs with trends, the balance
 * hero, the JV settlement alert, the charts, pending approvals with inline approve /
 * reject (the Expenses actions) and the vault panel once the vault exists (D64).
 * If the figures can't be read the page shows its error state instead of zeros.
 */
final class DashboardController extends Controller
{
    /** Pending approvals listed on the card. */
    private const int PENDING_ROWS = 5;

    public function __invoke(Request $request, CurrentCompany $current, DashboardData $data, DashboardVaultPanel $vault): Response
    {
        $company = $current->require();
        $period = ReportPeriod::fromQuery($company, $request->query());
        /** @var User $user */
        $user = $request->user();

        try {
            $figures = $data->build($company, $period);
        } catch (Throwable $e) {
            report($e);
            $figures = null;
        }

        return Inertia::render('dashboard', [
            'company' => ['name' => $company->name, 'jv' => $company->isJv()],
            'period' => $period->props(),
            'query' => $period->query(),
            'dashboard' => $figures,
            'approvals' => $this->approvals($user),
            'vault' => $vault->for($company, $user),
            'abilities' => [
                'createCompany' => $user->can('company.create'),
                'approve' => $user->can('action.expenses.approve') && $user->can('page.expenses'),
                'addIncome' => $user->can('action.income.manage') && $user->can('page.income'),
                'addExpense' => $user->can('action.expenses.create') && $user->can('page.expenses'),
                'expenses' => $user->can('page.expenses'),
                'settlements' => $user->can('page.settlements'),
                'recordSettlements' => $user->can('page.settlements') && $user->can('action.settlements.record'),
            ],
        ]);
    }

    /**
     * The oldest pending expenses (they have waited longest), with whether this user can
     * decide each one (never their own unless Admin, D44).
     *
     * @return array{count: int, totalPaise: int, rows: list<array{id: int, description: string, amountPaise: int, date: string, submitter: string|null, colour: int|null, canDecide: bool}>}
     */
    private function approvals(User $user): array
    {
        $query = Expense::query()->where('status', ExpenseStatus::Pending->value)->where('auto', false);
        $count = (clone $query)->count();
        $total = (int) (clone $query)->sum('amount_paise');

        // G2 (D106): a role that can't open Expenses sees only how many wait and their total.
        if (! $user->can('page.expenses')) {
            return ['count' => $count, 'totalPaise' => $total, 'rows' => []];
        }

        $rows = (clone $query)
            ->with(['submitter:id,name', 'payers.partner:id,colour', 'payers.member.partner:id,colour'])
            ->orderBy('spent_on')->orderBy('id')
            ->limit(self::PENDING_ROWS)
            ->get()
            ->map(function (Expense $e) use ($user): array {
                $payer = $e->payers->first();
                $colour = $payer?->payer_type === PayerType::Partner ? $payer->partner?->colour : $payer?->member?->partner?->colour;

                return [
                    'id' => $e->id,
                    'description' => $e->description,
                    'amountPaise' => $e->amount_paise,
                    'date' => $e->spent_on->toDateString(),
                    'submitter' => $e->submitter?->name,
                    'colour' => $colour,
                    'canDecide' => $user->can('decide', $e),
                ];
            })
            ->all();

        return ['count' => $count, 'totalPaise' => $total, 'rows' => array_values($rows)];
    }
}
