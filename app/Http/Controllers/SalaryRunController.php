<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\User;
use App\Services\Team\SalaryGenerator;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * "Run salaries" on Team (spec §8.3 "Run now"): preview what a month would post per
 * member, then run it. Same rules as the monthly job; re-running is safe (D38).
 */
final class SalaryRunController extends Controller
{
    public function preview(Request $request, CurrentCompany $current, SalaryGenerator $salaries): JsonResponse
    {
        Gate::authorize('runSalaries', Member::class);
        $month = $this->month($request);

        return response()->json([
            'month' => $month->toDateString(),
            'rows' => array_map(fn (array $row) => [
                'id' => $row['member']->id,
                'name' => $row['member']->name,
                'costType' => $row['member']->cost_type->value,
                'billableCenti' => $row['billableCenti'],
                'amountPaise' => $row['amountPaise'],
                'postedPaise' => $row['postedPaise'],
            ], $salaries->preview($current->require(), $month)),
        ]);
    }

    public function store(Request $request, CurrentCompany $current, SalaryGenerator $salaries): RedirectResponse
    {
        Gate::authorize('runSalaries', Member::class);
        $month = $this->month($request);
        /** @var User $actor */
        $actor = $request->user();
        $company = $current->require();

        $summary = $salaries->runMonth($company, $month, $actor);

        activity('team')->performedOn($company)->causedBy($actor)
            ->withProperties(['month' => $month->format('Y-m'), ...$summary->counts])
            ->log('ran salaries');

        Inertia::flash('toast', [
            'type' => $summary->counts['locked'] > 0 ? 'warning' : 'success',
            'message' => 'Salaries for '.$month->format('F Y').': '.$summary->describe(),
        ]);

        return back();
    }

    /** A finished month: this month or later isn't over yet. */
    private function month(Request $request): CarbonImmutable
    {
        $request->validate(['month' => ['required', 'date_format:Y-m', 'before:'.CarbonImmutable::today()->startOfMonth()->toDateString()]], [
            'month.before' => 'Salaries run for months that are over.',
        ]);

        return (CarbonImmutable::createFromFormat('!Y-m', $request->string('month')->toString()) ?: CarbonImmutable::today())->startOfMonth();
    }
}
