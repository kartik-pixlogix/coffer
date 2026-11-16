<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\Partners\SavePartner;
use App\Enums\AccountRole;
use App\Enums\CompanyRole;
use App\Http\Controllers\Controller;
use App\Ledger\Lines;
use App\Ledger\Period;
use App\Ledger\ProfitAllocator;
use App\Models\Account;
use App\Models\Partner;
use App\Models\Side;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Equity;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Partners & equity (SettingsPartners.dc.html, spec §16): partner cards with
 * their share, the edit-partner modal and removal (archive once they have records).
 */
final class PartnersController extends Controller
{
    public function index(CurrentCompany $current, ProfitAllocator $allocator): Response
    {
        $company = $current->require();
        $year = Period::fiscalYearOf($company, CarbonImmutable::today());
        $profitDue = $allocator->allocate($company, $year);
        $revenue = Lines::profitAndLoss($company, $year)
            ->where('a.role', AccountRole::Income->value)
            ->whereNotNull('l.partner_id')
            ->selectRaw('l.partner_id, COALESCE(SUM(l.credit_paise - l.debit_paise), 0) as amount')
            ->groupBy('l.partner_id')
            ->pluck('amount', 'partner_id');
        $sides = Side::query()->orderBy('position')->orderBy('id')->get();
        $partners = Partner::query()->active()->with('side')->orderBy('position')->orderBy('id')->get();

        return Inertia::render('settings/partners', [
            'company' => ['name' => $company->name, 'type' => $company->type->value],
            'sides' => $sides->map(fn (Side $s) => ['id' => $s->id, 'name' => $s->name, 'equityBps' => $s->equity_bps])->values(),
            'partners' => $partners->map(fn (Partner $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'email' => $p->email,
                'role' => $p->role->value,
                'colour' => $p->colour,
                'equityBps' => $p->equity_bps,
                'sideId' => $p->side_id,
                'overallBps' => $p->side_id === null ? $p->equity_bps : intdiv($p->side->equity_bps * $p->equity_bps, Equity::WHOLE),
                'linked' => $p->user_id !== null,
                'hasRecords' => $p->hasRecords(),
                // This fiscal year: income they brought in and their share of profit (spec §4.4).
                // Hours arrive with timesheets in Phase 4.
                'stats' => ['revenuePaise' => (int) ($revenue[$p->id] ?? 0), 'profitDuePaise' => $profitDue[$p->id] ?? 0, 'hours' => 0],
            ])->values(),
        ]);
    }

    public function store(Request $request, CurrentCompany $current, SavePartner $save): RedirectResponse
    {
        $data = $this->validated($request, $current, null);
        /** @var User $actor */
        $actor = $request->user();
        $partner = $save->handle($current->require(), $actor, null, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$partner->name} added".($partner->email ? ' and invited' : '')]);

        return back();
    }

    public function update(Request $request, Partner $partner, CurrentCompany $current, SavePartner $save): RedirectResponse
    {
        abort_if($partner->archived_at !== null, 404);

        $data = $this->validated($request, $current, $partner);
        /** @var User $actor */
        $actor = $request->user();
        $save->handle($current->require(), $actor, $partner, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$data['name']} updated"]);

        return back();
    }

    /** Remove a partner: only with no share left; archived (not deleted) once they have records. */
    public function destroy(Request $request, Partner $partner): RedirectResponse
    {
        abort_if($partner->archived_at !== null, 404);

        if ($partner->equity_bps > 0) {
            $share = rtrim(rtrim(Equity::format($partner->equity_bps), '0'), '.');

            throw ValidationException::withMessages(['partner' => "Move {$partner->name}’s {$share}% to another partner before removing them."]);
        }

        if ($partner->user_id !== null && $partner->company->workspace->owner_id === $partner->user_id) {
            throw ValidationException::withMessages(['partner' => "{$partner->name} owns the workspace, so they stay a partner."]);
        }

        $archived = $partner->hasRecords();

        if ($archived) {
            $partner->update(['archived_at' => now()]);
        } else {
            // No lines reference them (hasRecords is false), so the partner's empty accounts go too.
            Account::query()->where('partner_id', $partner->id)->delete();
            $partner->delete();
        }

        activity('partners')->performedOn($partner)->causedBy($request->user())->log($archived ? 'archived a partner' : 'removed a partner');

        Inertia::flash('toast', ['type' => 'success', 'message' => $archived ? "{$partner->name} archived. Their records stay." : "{$partner->name} removed"]);

        return back();
    }

    /**
     * @return array{name: string, email?: string|null, role: string, colour: int, side_id?: int|null, equity: string, shares?: array<int|string, string>}
     */
    private function validated(Request $request, CurrentCompany $current, ?Partner $partner): array
    {
        $company = $current->require();

        /** @var array{name: string, email?: string|null, role: string, colour: int, side_id?: int|null, equity: string, shares?: array<int|string, string>} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [$partner === null ? 'nullable' : 'prohibited', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_map(fn (CompanyRole $r) => $r->value, CompanyRole::partnerRoles()))],
            'colour' => ['required', 'integer', 'between:1,'.Partner::COLOURS],
            'side_id' => [Rule::requiredIf($company->isJv()), 'nullable', 'integer'],
            'equity' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if (Equity::parse((string) $value) === null) {
                    $fail('Use a percentage from 0 to 100, with up to 2 decimals.');
                }
            }],
            'shares' => ['nullable', 'array'],
            'shares.*' => ['string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
        ], [
            'name.required' => 'Add the partner’s name.',
        ]);

        // Only Admins make someone an Admin or change an Admin's role.
        $touchesAdmin = $data['role'] === CompanyRole::Admin->value || $partner?->role === CompanyRole::Admin;
        $roleChanges = $partner === null || $partner->role->value !== $data['role'];

        if ($touchesAdmin && $roleChanges && ! $current->isAdmin()) {
            abort(403);
        }

        return $data;
    }
}
