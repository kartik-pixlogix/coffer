<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Team\SaveMember;
use App\Enums\CostType;
use App\Enums\JournalType;
use App\Enums\MemberStatus;
use App\Ledger\Period;
use App\Models\Account;
use App\Models\Company;
use App\Models\Invite;
use App\Models\JournalLine;
use App\Models\Member;
use App\Models\MemberRate;
use App\Models\Partner;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\MemberPolicy;
use App\Services\Team\Earnings;
use App\Services\Team\MemberRates;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team (Team.dc.html, spec §7): members by partner with this month's billable hours and
 * earnings, the add/edit modal, deactivate and delete; and the derived member profile.
 */
final class TeamController extends Controller
{
    public function index(Request $request, CurrentCompany $current, Earnings $earnings, MemberPolicy $policy): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // A Member sees only their own profile (spec §2.1).
        if ($policy->ownOnly()) {
            $own = Member::query()->where('user_id', $user->id)->first();
            abort_if($own === null, 403);

            return to_route('team.show', $own);
        }

        $company = $current->require();
        $month = CarbonImmutable::today()->startOfMonth();
        $members = Member::query()->with('rates')->orderBy('name')->get();
        $figures = $earnings->forMonth($members, $month);
        $withHistory = $this->idsWithHistory($members->modelKeys());
        $canManage = $user->can('create', Member::class);

        return Inertia::render('team/index', [
            'company' => ['name' => $company->name, 'workspace' => $company->workspace->name],
            'month' => $month->toDateString(),
            'partners' => $this->partners(),
            'members' => $members->map(fn (Member $m) => [
                ...$this->summary($m),
                'billableCenti' => $figures[$m->id]->billableCenti,
                'earningsPaise' => $figures[$m->id]->amountPaise,
                'hasHistory' => in_array($m->id, $withHistory, true),
            ])->values(),
            'abilities' => [
                'manage' => $canManage,
                'editRates' => $user->can('changeRate', Member::class),
                'runSalaries' => $user->can('runSalaries', Member::class),
            ],
            'users' => $canManage ? $this->workspaceUsers($company) : [],
        ]);
    }

    public function show(Request $request, Member $member, CurrentCompany $current, Earnings $earnings, MemberRates $rates): Response
    {
        Gate::authorize('view', $member);

        /** @var User $user */
        $user = $request->user();
        $company = $current->require();
        $member->load(['rates.creator:id,name', 'partner', 'user:id,name,email']);
        $year = Period::fiscalYearOf($company, CarbonImmutable::today());
        $today = CarbonImmutable::today();

        $months = [];

        for ($m = CarbonImmutable::instance($year->from ?? $today)->startOfMonth(); $m->lessThanOrEqualTo($today); $m = $m->addMonth()) {
            $month = $earnings->forMember($member, $m);
            $months[] = [
                'month' => $m->toDateString(),
                'hoursCenti' => $month->hoursCenti,
                'billableCenti' => $month->billableCenti,
                'earningsPaise' => $month->amountPaise,
            ];
        }

        $canManage = $user->can('update', $member);

        return Inertia::render('team/show', [
            'company' => ['name' => $company->name, 'workspace' => $company->workspace->name],
            'member' => [
                ...$this->summary($member),
                'email' => $member->email,
                'user' => $member->user ? ['name' => $member->user->name, 'email' => $member->user->email] : null,
                'deactivatedOn' => $member->deactivated_on?->toDateString(),
                'createdAt' => $member->created_at?->toDateString(),
                'hasHistory' => $member->hasHistory(),
            ],
            'partners' => $this->partners(),
            'rates' => $member->rates->sortByDesc('effective_from')->map(fn (MemberRate $r) => [
                'id' => $r->id,
                'from' => $r->effective_from->toDateString(),
                'costType' => $r->cost_type->value,
                'ratePaise' => $r->rate_paise,
                'by' => $r->creator?->name,
                'current' => $r->id === $rates->on($member, $today)->id,
            ])->values(),
            'months' => $months,
            'salaries' => $this->salaries($member),
            'abilities' => ['manage' => $canManage, 'editRates' => $user->can('changeRate', $member)],
            'users' => $canManage ? $this->workspaceUsers($company) : [],
        ]);
    }

    public function store(Request $request, SaveMember $save): RedirectResponse
    {
        Gate::authorize('create', Member::class);

        /** @var User $actor */
        $actor = $request->user();
        $member = $save->handle($actor, null, $this->validated($request, null));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->name} added to the team"]);

        return back();
    }

    public function update(Request $request, Member $member, SaveMember $save, MemberRates $rates): RedirectResponse
    {
        Gate::authorize('update', $member);

        $data = $this->validated($request, $member);
        $current = $rates->on($member, $data['effective_from'] ?? CarbonImmutable::today());

        if ($current->cost_type !== $data['cost_type'] || $current->rate_paise !== $data['rate_paise']) {
            Gate::authorize('changeRate', $member);

            if ($data['effective_from'] === null) {
                throw ValidationException::withMessages(['effective_from' => 'Pick the date the new rate starts.']);
            }
        }

        /** @var User $actor */
        $actor = $request->user();
        $save->handle($actor, $member, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->name} updated"]);

        return back();
    }

    /** Deactivate (from today, or a chosen day) or reactivate. Deactivated members keep their history. */
    public function status(Request $request, Member $member): RedirectResponse
    {
        Gate::authorize('update', $member);

        $deactivate = $member->isActive();

        if ($deactivate) {
            /** @var array{on?: string|null} $data */
            $data = $request->validate(['on' => ['nullable', 'date']]);
            $member->update([
                'status' => MemberStatus::Inactive,
                'deactivated_on' => isset($data['on']) ? CarbonImmutable::parse($data['on']) : CarbonImmutable::today(),
            ]);
        } else {
            $member->update(['status' => MemberStatus::Active, 'deactivated_on' => null]);
        }

        activity('team')->performedOn($member)->causedBy($request->user())->log($deactivate ? 'deactivated a team member' : 'reactivated a team member');
        Inertia::flash('toast', ['type' => 'success', 'message' => $deactivate ? "{$member->name} deactivated. Their history stays." : "{$member->name} is active again"]);

        return back();
    }

    /** Delete, only with no hours or salary on record (spec §7). */
    public function destroy(Request $request, Member $member): RedirectResponse
    {
        Gate::authorize('delete', $member);

        if ($member->hasHistory()) {
            throw ValidationException::withMessages(['member' => "{$member->name} has hours or salary on record, so they can only be deactivated."]);
        }

        DB::transaction(function () use ($member): void {
            // Nothing posted against them, so their (empty) reimbursements account goes too.
            Account::query()->where('member_id', $member->id)->delete();
            Invite::query()->where('member_id', $member->id)->update(['member_id' => null]);
            $member->forceDelete();
        });

        activity('team')->causedBy($request->user())->withProperties(['name' => $member->name])->log('deleted a team member');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->name} removed from the team"]);

        return to_route('team.index');
    }

    /**
     * @return array{name: string, designation: string|null, partner_id: int, user_id: int|null, cost_type: CostType, rate_paise: int, effective_from: CarbonImmutable|null}
     */
    private function validated(Request $request, ?Member $member): array
    {
        $company = app(CurrentCompany::class)->require();

        /** @var array{name: string, designation?: string|null, partner_id: int|string, user_id?: int|string|null, cost_type: string, rate_paise: int|string, effective_from?: string|null} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'designation' => ['nullable', 'string', 'max:120'],
            'partner_id' => ['required', 'integer', Rule::exists('partners', 'id')->where('company_id', $company->id)->whereNull('archived_at')],
            'user_id' => [
                'nullable', 'integer',
                Rule::exists('company_user', 'user_id')->whereIn('company_id', $company->workspace->companies()->pluck('id')->all()),
                Rule::unique('members', 'user_id')->where('company_id', $company->id)->ignore($member?->id),
            ],
            'cost_type' => ['required', Rule::enum(CostType::class)],
            'rate_paise' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'effective_from' => ['nullable', 'date'],
        ], [
            'name.required' => 'Add their name.',
            'partner_id.required' => 'Pick the partner they bill to.',
            'partner_id.exists' => 'Pick the partner they bill to.',
            'user_id.exists' => 'Pick someone from this workspace.',
            'user_id.unique' => 'That account is already linked to another team member.',
            'rate_paise.required' => 'Add their rate.',
            'rate_paise.min' => 'Add their rate.',
        ]);

        return [
            'name' => trim($data['name']),
            'designation' => filled($data['designation'] ?? null) ? trim((string) $data['designation']) : null,
            'partner_id' => (int) $data['partner_id'],
            'user_id' => filled($data['user_id'] ?? null) ? (int) $data['user_id'] : null,
            'cost_type' => CostType::from($data['cost_type']),
            'rate_paise' => (int) $data['rate_paise'],
            'effective_from' => filled($data['effective_from'] ?? null) ? CarbonImmutable::parse((string) $data['effective_from'])->startOfDay() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function summary(Member $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'designation' => $m->designation,
            'partnerId' => $m->partner_id,
            'userId' => $m->user_id,
            'costType' => $m->cost_type->value,
            'ratePaise' => $m->rate_paise,
            'active' => $m->isActive(),
        ];
    }

    /** @return list<array{id: int, name: string, colour: int}> */
    private function partners(): array
    {
        return array_values(Partner::query()->active()->orderBy('position')->orderBy('id')->get()
            ->map(fn (Partner $p) => ['id' => $p->id, 'name' => $p->name, 'colour' => $p->colour])
            ->all());
    }

    /**
     * People in this workspace who can be linked to a member ("Search people in Vertex Group").
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    private function workspaceUsers(Company $company): array
    {
        return array_values(User::query()
            ->whereHas('companies', fn ($q) => $q->where('workspace_id', $company->workspace_id))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->all());
    }

    /**
     * Members with hours or salary on record, who can be deactivated but not deleted.
     *
     * @param  array<int, int|string>  $ids
     * @return list<int>
     */
    private function idsWithHistory(array $ids): array
    {
        $hours = TimeEntry::query()->whereIn('member_id', $ids)->distinct()->pluck('member_id');
        $lines = JournalLine::query()->whereIn('member_id', $ids)->distinct()->pluck('member_id');

        return array_values($hours->merge($lines)->map(fn ($id) => (int) $id)->unique()->all());
    }

    /**
     * Salary posted for the member, newest first. Reversed runs and their reversals are left out.
     *
     * @return list<array{date: string, memo: string|null, amountPaise: int}>
     */
    private function salaries(Member $member): array
    {
        return array_values(JournalLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.member_id', $member->id)
            ->where('e.type', JournalType::Salary->value)
            ->whereNull('e.reversed_by_id')
            ->whereNull('e.reverses_id')
            ->where('journal_lines.debit_paise', '>', 0)
            ->groupBy('e.id', 'e.date', 'e.memo')
            ->orderByDesc('e.date')
            ->selectRaw('e.date as date, e.memo as memo, SUM(journal_lines.debit_paise) as amount')
            ->toBase()
            ->get()
            ->map(fn (object $row) => [
                'date' => CarbonImmutable::parse($row->date)->toDateString(),
                'memo' => is_string($row->memo) ? $row->memo : null,
                'amountPaise' => (int) $row->amount,
            ])
            ->all());
    }
}
