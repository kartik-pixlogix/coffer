<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Ledger\ChartOfAccounts;
use App\Models\Account;
use App\Models\JournalLine;
use App\Models\MoneyAccount;
use App\Models\Partner;
use App\Models\Side;
use App\Support\CurrentCompany;
use App\Support\Equity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Partner sides (SettingsPartners.dc.html "Sides" tab, spec §16). Joint
 * ventures only: name and share per side, totalling 100%; a side can be removed only
 * while it has no partners and its money account has no entries.
 */
final class SidesController extends Controller
{
    public function index(CurrentCompany $current, ChartOfAccounts $chart): Response|RedirectResponse
    {
        $company = $current->require();

        if (! $company->isJv()) {
            return redirect()->route('settings.partners');
        }

        $partners = Partner::query()->active()->get(['id', 'name', 'colour', 'side_id']);

        return Inertia::render('settings/sides', [
            'sides' => Side::query()->orderBy('position')->orderBy('id')->get()->map(fn (Side $side) => [
                'id' => $side->id,
                'name' => $side->name,
                'equityBps' => $side->equity_bps,
                'bankAccount' => (fn (MoneyAccount $account) => $account->bank_label ?? $account->name)($chart->sideAccount($side)),
                'partners' => $partners->where('side_id', $side->id)->map(fn (Partner $p) => ['id' => $p->id, 'name' => $p->name, 'colour' => $p->colour])->values(),
            ])->values(),
        ]);
    }

    public function update(Request $request, CurrentCompany $current, ChartOfAccounts $chart): RedirectResponse
    {
        $company = $current->require();
        abort_unless($company->isJv(), 404);

        $validated = $request->validate([
            'sides' => ['required', 'array', 'min:2', 'max:12'],
            'sides.*.id' => ['nullable', 'integer'],
            'sides.*.name' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
            'sides.*.equity' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
        ], [
            'sides.min' => 'A joint venture needs at least two sides.',
            'sides.*.name.required' => 'Name every side.',
            'sides.*.name.distinct' => 'Give each side its own name.',
        ]);

        /** @var Collection<int, array{id?: int|null, name: string, equity: string}> $rows */
        $rows = new Collection($validated['sides']);
        $total = (int) $rows->sum(fn (array $s) => Equity::parse($s['equity']) ?? 0);

        if ($total !== Equity::WHOLE) {
            $shown = rtrim(rtrim(Equity::format($total), '0'), '.');

            throw ValidationException::withMessages(['sides' => "Side equity must add up to 100%. It’s {$shown}% now."]);
        }

        DB::transaction(function () use ($rows, $request, $chart): void {
            $existing = Side::query()->lockForUpdate()->get()->keyBy('id');
            $keep = $rows->pluck('id')->filter()->map(fn ($id) => (int) $id);

            foreach ($existing as $side) {
                if (! $keep->contains($side->id)) {
                    if (Partner::query()->where('side_id', $side->id)->exists()) {
                        throw ValidationException::withMessages(['sides' => "{$side->name} still has partners. Move them to another side first."]);
                    }

                    $this->deleteBooks($side);
                    $side->delete();
                }
            }

            foreach ($rows->values() as $position => $row) {
                $attributes = ['name' => trim($row['name']), 'equity_bps' => Equity::parse($row['equity']) ?? 0, 'position' => $position];
                $side = isset($row['id']) ? $existing->get((int) $row['id']) : null;

                if (isset($row['id']) && $side === null) {
                    abort(404);
                }

                if ($side === null) {
                    $side = Side::query()->create($attributes);
                } else {
                    $side->update($attributes);
                }

                // Each side has its own money account (spec §3.1), named after it.
                $account = $chart->sideAccount($side);
                $account->update(['name' => "{$side->name} account"]);
                Account::query()->where('money_account_id', $account->id)->update(['name' => "{$side->name} account"]);
            }

            activity('partners')->causedBy($request->user())->withProperties(['sides' => $rows->all()])->log('updated the sides');
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sides saved']);

        return back();
    }

    /** A side can only go while its money account has never been used. */
    private function deleteBooks(Side $side): void
    {
        $accounts = MoneyAccount::query()->where('side_id', $side->id)->get();
        $chartIds = Account::query()->whereIn('money_account_id', $accounts->pluck('id'))->pluck('id');

        if (JournalLine::query()->whereIn('account_id', $chartIds)->exists()) {
            throw ValidationException::withMessages(['sides' => "{$side->name}’s account has entries, so the side can’t be removed."]);
        }

        Account::query()->whereIn('id', $chartIds)->delete();
        MoneyAccount::query()->whereIn('id', $accounts->pluck('id'))->delete();
    }
}
