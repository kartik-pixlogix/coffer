<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Timesheets\LogTime;
use App\Enums\ProjectStatus;
use App\Http\Requests\Timesheets\TimeEntryRequest;
use App\Models\Member;
use App\Models\Partner;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\TimeEntryPolicy;
use App\Services\Team\Earnings;
use App\Services\Timesheets\Hours;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Timesheets (Timesheets.dc.html, MobileLogHours.dc.html, spec §8.1): the month's KPIs,
 * the team summary, filtered entries (25 a page) and logging by day or by month.
 * Members see and log only their own hours.
 */
final class TimesheetsController extends Controller
{
    public const int PER_PAGE = 25;

    public function index(Request $request, CurrentCompany $current, Earnings $earnings, TimeEntryPolicy $policy): Response
    {
        /** @var User $user */
        $user = $request->user();
        $company = $current->require();
        $today = CarbonImmutable::today();
        $month = $this->monthFrom($request->query('month')) ?? $today->startOfMonth();
        $monthEnd = $month->endOfMonth()->startOfDay();
        $ownOnly = $policy->ownOnly();
        $ownMember = $ownOnly ? Member::query()->where('user_id', $user->id)->first() : null;

        /** @var array{member?: string, partner?: string, project?: string, billable?: string, q?: string} $filters */
        $filters = array_filter($request->only(['member', 'partner', 'project', 'billable', 'q']), fn ($v) => is_string($v) && $v !== '');

        $inMonth = fn (): Builder => TimeEntry::query()
            ->whereBetween('date', [$month->toDateString(), $monthEnd->toDateString()])
            ->when($ownOnly, fn (Builder $q) => $q->where('member_id', $ownMember->id ?? 0));

        // KPIs (spec §8.1): this month, billable, non-billable, logged today.
        $totals = $inMonth()->selectRaw('COALESCE(SUM(hours_centi), 0) as total, COALESCE(SUM(CASE WHEN billable THEN hours_centi ELSE 0 END), 0) as billable, COUNT(DISTINCT member_id) as members')->toBase()->first();
        $todayRows = TimeEntry::query()->with('member:id,name')->whereDate('date', $today->toDateString())
            ->when($ownOnly, fn (Builder $q) => $q->where('member_id', $ownMember->id ?? 0))->get();

        // Team this month: the month's hours (month mode logs ahead) and earnings; fixed costs accrue by day.
        $members = Member::query()->with('rates')
            ->when($ownOnly, fn (Builder $q) => $q->whereKey($ownMember->id ?? 0))
            ->orderBy('name')->get();
        $figures = $earnings->forMonth($members, $month);
        $elapsed = $month->isSameMonth($today) ? $today->day / $month->daysInMonth : ($month->lessThan($today) ? 1 : 0);

        $entries = $inMonth()
            ->with(['member:id,name,partner_id', 'project:id,name'])
            ->when($filters['member'] ?? null, fn (Builder $q, string $id) => $q->where('member_id', (int) $id))
            ->when($filters['partner'] ?? null, fn (Builder $q, string $id) => $q->where('partner_id', (int) $id))
            ->when($filters['project'] ?? null, fn (Builder $q, string $id) => $q->where('project_id', (int) $id))
            ->when(($filters['billable'] ?? null) === 'billable', fn (Builder $q) => $q->where('billable', true))
            ->when(($filters['billable'] ?? null) === 'non', fn (Builder $q) => $q->where('billable', false))
            ->when($filters['q'] ?? null, function (Builder $q, string $search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $q->where(fn (Builder $w) => $w->where('notes', 'like', $like)
                    ->orWhereHas('member', fn (Builder $m) => $m->where('name', 'like', $like))
                    ->orWhereHas('project', fn (Builder $p) => $p->where('name', 'like', $like)));
            })
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Phone layout (MobileLogHours.dc.html): the week around the chosen day.
        $day = $this->dayFrom($request->query('day')) ?? $today;
        $weekStart = $day->startOfWeek(CarbonImmutable::MONDAY);
        $week = TimeEntry::query()->with(['member:id,name', 'project:id,name'])
            ->whereBetween('date', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->when($ownOnly, fn (Builder $q) => $q->where('member_id', $ownMember->id ?? 0))
            ->orderByDesc('date')->orderByDesc('id')
            ->get();

        $batchSizes = TimeEntry::query()->whereIn('batch', $entries->getCollection()->pluck('batch')->merge($week->pluck('batch'))->filter()->unique())
            ->groupBy('batch')->selectRaw('batch, COUNT(*) as n')->pluck('n', 'batch');
        $row = fn (TimeEntry $e) => [
            'id' => $e->id,
            'date' => $e->date->toDateString(),
            'memberId' => $e->member_id,
            'memberName' => $e->member->name,
            'partnerId' => $e->partner_id,
            'projectId' => $e->project_id,
            'projectName' => $e->project->name,
            'hoursCenti' => $e->hours_centi,
            'billable' => $e->billable,
            'notes' => $e->notes,
            'batch' => $e->batch,
            'batchSize' => $e->batch !== null ? (int) ($batchSizes[$e->batch] ?? 1) : 1,
        ];

        return Inertia::render('timesheets/index', [
            'company' => ['name' => $company->name, 'workingDays' => $company->working_days, 'hoursPerDayCenti' => $company->hours_per_day_centi],
            'today' => $today->toDateString(),
            'month' => $month->toDateString(),
            'filters' => (object) $filters,
            'kpis' => [
                'totalCenti' => (int) $totals->total,
                'billableCenti' => (int) $totals->billable,
                'members' => (int) $totals->members,
                'todayCenti' => (int) $todayRows->sum('hours_centi'),
                'todayEntries' => $todayRows->count(),
                'todayNames' => $todayRows->pluck('member.name')->unique()->values(),
            ],
            'team' => $members
                ->filter(fn (Member $m) => $m->isActive() || $figures[$m->id]->hoursCenti > 0)
                ->map(fn (Member $m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'partnerId' => $m->partner_id,
                    'active' => $m->isActive(),
                    'hoursCenti' => $figures[$m->id]->hoursCenti,
                    'fixed' => $figures[$m->id]->fixed,
                    'earningsPaise' => $figures[$m->id]->fixed
                        ? (int) round($figures[$m->id]->amountPaise * $elapsed)
                        : $figures[$m->id]->amountPaise,
                ])->values(),
            'entries' => $entries->through($row),
            'week' => ['day' => $day->toDateString(), 'start' => $weekStart->toDateString(), 'entries' => $week->map($row)->values()],
            'options' => [
                'members' => $members->filter(fn (Member $m) => $m->isActive())
                    ->map(fn (Member $m) => ['id' => $m->id, 'name' => $m->name, 'partnerId' => $m->partner_id])->values(),
                'partners' => Partner::query()->active()->orderBy('position')->orderBy('id')->get()
                    ->map(fn (Partner $p) => ['id' => $p->id, 'name' => $p->name, 'colour' => $p->colour])->values(),
                'projects' => Project::query()->orderBy('name')->get()
                    ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name, 'active' => $p->status === ProjectStatus::Active])->values(),
            ],
            'abilities' => [
                'log' => $user->can('action.time.log') && (! $ownOnly || $ownMember !== null),
                'import' => $user->can('action.time.log') && ! $ownOnly,
            ],
        ]);
    }

    public function store(TimeEntryRequest $request, CurrentCompany $current, LogTime $log): RedirectResponse
    {
        $member = $request->member();
        Gate::authorize('create', [TimeEntry::class, $member]);

        /** @var User $actor */
        $actor = $request->user();
        $company = $current->require();
        $centi = $request->centi();

        $entries = $request->input('mode') === 'month'
            ? $log->month($company, $actor, $member, $request->monthStart(), $centi, $request->boolean('saturdays'), $request->details())
            : $log->day($company, $actor, $member, $request->day(), $centi, $request->details());

        activity('timesheets')->performedOn($member)->causedBy($actor)
            ->withProperties(['entries' => $entries->count(), 'hours_centi' => $centi])
            ->log('logged hours');

        $this->flashLogged($member, $entries->pluck('date')->all(), Hours::format($centi).' h logged for '.$member->name);

        return back();
    }

    public function update(TimeEntryRequest $request, TimeEntry $entry, CurrentCompany $current, LogTime $log): RedirectResponse
    {
        Gate::authorize('update', $entry);
        $member = $request->member();
        Gate::authorize('create', [TimeEntry::class, $member]);

        $centi = $request->centi();
        $date = $request->day();
        $log->assertOpen($current->require(), [$entry->date, $date]);
        $details = $request->details();

        $entry->update([
            'member_id' => $member->id,
            'partner_id' => $details['partner_id'] ?? $member->partner_id,
            'project_id' => $details['project_id'],
            'date' => $date->toDateString(),
            'hours_centi' => $centi,
            'billable' => $details['billable'],
            'notes' => $details['notes'],
        ]);

        activity('timesheets')->performedOn($entry)->causedBy($request->user())->log('edited hours');
        $this->flashLogged($member, [$date], 'Entry updated');

        return back();
    }

    /** Delete one entry, or with `?batch=1` every entry logged with it (spec §8.1). */
    public function destroy(Request $request, TimeEntry $entry, CurrentCompany $current, LogTime $log): RedirectResponse
    {
        Gate::authorize('delete', $entry);

        $entries = $request->boolean('batch') && $entry->batch !== null
            ? TimeEntry::query()->where('batch', $entry->batch)->get()
            : $entry->newCollection([$entry]);

        $log->assertOpen($current->require(), $entries->pluck('date'));

        // One by one, so each deletion re-runs a posted salary for its month (spec §8.3).
        DB::transaction(fn () => $entries->each(fn (TimeEntry $e) => $e->delete()));

        activity('timesheets')->performedOn($entry->member)->causedBy($request->user())
            ->withProperties(['entries' => $entries->count()])
            ->log('deleted hours');

        $count = $entries->count();
        Inertia::flash('toast', ['type' => 'success', 'message' => $count === 1 ? 'Entry deleted' : "{$count} entries deleted"]);

        return back();
    }

    /** @param iterable<CarbonImmutable> $dates */
    private function flashLogged(Member $member, iterable $dates, string $message): void
    {
        $warning = LogTime::longDayWarning($member, $dates);

        Inertia::flash('toast', $warning === null
            ? ['type' => 'success', 'message' => $message]
            : ['type' => 'warning', 'message' => "{$message}. {$warning}"]);
    }

    private function dayFrom(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }

    private function monthFrom(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m', $value)?->startOfMonth() ?: null;
    }
}
