<?php

declare(strict_types=1);

namespace App\Actions\Timesheets;

use App\Ledger\Exceptions\PeriodLockedException;
use App\Ledger\FiscalYears;
use App\Models\Company;
use App\Models\Member;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Timesheets\Hours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Logs hours (spec §8.1). Day mode is one entry. Month mode spreads the total over the
 * month's working days (Mon–Fri, plus Saturdays when included) with largest-remainder
 * rounding at 0.01 h, so the entries add up to the total exactly; they share a batch
 * that can be deleted together. Nothing can be logged inside a closed fiscal year.
 */
final class LogTime
{
    public function __construct(private readonly FiscalYears $years) {}

    /**
     * @param  array{project_id: int, partner_id: int|null, billable: bool, notes: string|null}  $details
     * @return Collection<int, TimeEntry>
     */
    public function day(Company $company, User $actor, Member $member, CarbonImmutable $date, int $centi, array $details): Collection
    {
        $this->assertOpen($company, [$date]);

        return DB::transaction(fn () => collect([$this->create($member, $date, $centi, $details, null, $actor)]));
    }

    /**
     * @param  array{project_id: int, partner_id: int|null, billable: bool, notes: string|null}  $details
     * @return Collection<int, TimeEntry>
     */
    public function month(Company $company, User $actor, Member $member, CarbonImmutable $month, int $totalCenti, bool $saturdays, array $details): Collection
    {
        $days = Hours::workingDays($month, Hours::mask($company->working_days, $saturdays));

        if ($days === []) {
            throw ValidationException::withMessages(['month' => 'This month has no working days.']);
        }

        if ($totalCenti > Hours::MAX * count($days)) {
            throw ValidationException::withMessages(['hours' => 'That’s more than 24 h a day over '.count($days).' working days.']);
        }

        $this->assertOpen($company, $days);

        return $this->batch($member, $days, $totalCenti, $details, $actor);
    }

    /**
     * One batch over the given days (month mode, monthly import rows). Days that would get
     * 0.00 h (a tiny total over many days) get no entry.
     *
     * @param  list<CarbonImmutable>  $days
     * @param  array{project_id: int, partner_id: int|null, billable: bool, notes: string|null}  $details
     * @return Collection<int, TimeEntry>
     */
    public function batch(Member $member, array $days, int $totalCenti, array $details, ?User $actor, ?int $importId = null): Collection
    {
        $batch = (string) Str::uuid();
        $parts = Hours::distribute($totalCenti, count($days));

        return DB::transaction(function () use ($member, $days, $parts, $details, $actor, $batch, $importId): Collection {
            $entries = collect();

            foreach ($days as $i => $day) {
                if ($parts[$i] > 0) {
                    $entries->push($this->create($member, $day, $parts[$i], $details, $batch, $actor, $importId));
                }
            }

            return $entries;
        });
    }

    /**
     * "Rohan Desai has 13.5 h on 14 Nov 2026, more than 12 h." for the first day the member
     * now has more than 12 h on (spec §8.1); null when every day is fine.
     *
     * @param  iterable<CarbonImmutable>  $dates
     */
    public static function longDayWarning(Member $member, iterable $dates): ?string
    {
        $days = collect($dates)->map(fn (CarbonImmutable $d) => $d->toDateString())->unique()->values();

        $row = TimeEntry::query()->withoutGlobalScope('company')
            ->where('member_id', $member->id)
            ->whereIn('date', $days)
            ->groupBy('date')
            ->havingRaw('SUM(hours_centi) > ?', [Hours::LONG_DAY])
            ->orderBy('date')
            ->selectRaw('date, SUM(hours_centi) as centi')
            ->toBase()
            ->first();

        if ($row === null) {
            return null;
        }

        $date = CarbonImmutable::parse($row->date)->format('j M Y');

        return "{$member->name} has ".Hours::format((int) $row->centi)." h on {$date}, more than 12 h.";
    }

    /**
     * One imported day row (spec §8.2): already checked, so no further rules here.
     *
     * @param  array{project_id: int, partner_id: int|null, billable: bool, notes: string|null}  $details
     */
    public function imported(Member $member, CarbonImmutable $date, int $centi, array $details, ?User $actor, int $importId): TimeEntry
    {
        return $this->create($member, $date, $centi, $details, null, $actor, $importId);
    }

    /** @param iterable<CarbonImmutable> $dates */
    public function assertOpen(Company $company, iterable $dates): void
    {
        foreach ($dates as $date) {
            $closed = $this->years->closedYearFor($company, $date);

            if ($closed !== null) {
                throw ValidationException::withMessages(['date' => PeriodLockedException::for($closed->label)->getMessage()]);
            }
        }
    }

    /** @param array{project_id: int, partner_id: int|null, billable: bool, notes: string|null} $details */
    private function create(Member $member, CarbonImmutable $date, int $centi, array $details, ?string $batch, ?User $actor, ?int $importId = null): TimeEntry
    {
        /** @var TimeEntry */
        return TimeEntry::query()->create([
            'company_id' => $member->company_id,
            'member_id' => $member->id,
            'partner_id' => $details['partner_id'] ?? $member->partner_id,
            'project_id' => $details['project_id'],
            'date' => $date->toDateString(),
            'hours_centi' => $centi,
            'billable' => $details['billable'],
            'notes' => $details['notes'],
            'batch' => $batch,
            'import_id' => $importId,
            'created_by' => $actor?->id,
        ]);
    }
}
