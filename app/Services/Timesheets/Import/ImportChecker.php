<?php

declare(strict_types=1);

namespace App\Services\Timesheets\Import;

use App\Enums\ProjectStatus;
use App\Ledger\FiscalYears;
use App\Models\Company;
use App\Models\Member;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\Timesheets\Hours;
use Illuminate\Support\Collection;

/**
 * Checks every CSV row before anything is saved (spec §8.2 preview): unknown member
 * (with a "Did you mean" suggestion), bad or impossible date, hours out of range,
 * duplicates of an existing entry or an earlier row, and dates in a closed fiscal year.
 * Unknown projects aren't errors: they're created on import (BulkImport.dc.html).
 */
final class ImportChecker
{
    public function __construct(private readonly FiscalYears $years) {}

    /**
     * @param  list<list<string>>  $rows
     * @param  array<int, string|null>  $mapping  column index → field
     * @return list<ImportRow>
     */
    public function check(Company $company, array $rows, array $mapping): array
    {
        $col = array_flip(array_filter($mapping));
        $cell = fn (array $row, string $field): string => isset($col[$field]) ? trim($row[$col[$field]] ?? '') : '';

        /** @var Collection<int, Member> $members */
        $members = Member::query()->withoutGlobalScope('company')->where('company_id', $company->id)->get();
        $byKey = [];

        foreach ($members as $m) {
            $byKey[mb_strtolower($m->name)] = $m;

            if ($m->email !== null) {
                $byKey[mb_strtolower($m->email)] = $m;
            }
        }

        $projects = Project::query()->withoutGlobalScope('company')->where('company_id', $company->id)->get()
            ->keyBy(fn (Project $p) => mb_strtolower($p->name));
        $order = ImportDates::order(array_map(fn (array $row) => $cell($row, 'date'), $rows));

        $checked = [];

        foreach ($rows as $index => $row) {
            $memberRaw = $cell($row, 'member');
            $member = $byKey[mb_strtolower($memberRaw)] ?? null;
            $dateRaw = $cell($row, 'date');
            $parsed = $dateRaw === '' ? null : ImportDates::parse($dateRaw, $order);
            $hoursRaw = $cell($row, 'hours');
            $centi = Hours::parse($hoursRaw);
            $projectRaw = $cell($row, 'project');
            $project = $projects->get(mb_strtolower($projectRaw));
            $billable = self::billable($cell($row, 'billable'));
            $notes = $cell($row, 'notes');

            $date = $parsed !== null && $parsed['kind'] !== 'impossible' ? $parsed['date'] : null;
            $monthly = $parsed !== null && $parsed['kind'] === 'month';

            $item = new ImportRow(
                number: $index + 1,
                cells: $row,
                member: $member->name ?? $memberRaw,
                memberId: $member?->id,
                dateRaw: $dateRaw,
                date: $date,
                monthly: $monthly,
                hoursRaw: $hoursRaw,
                centi: $centi,
                project: $project->name ?? $projectRaw,
                projectId: $project?->id,
                billable: $billable ?? true,
                notes: $notes === '' ? null : mb_substr($notes, 0, 500),
            );

            $item->error = match (true) {
                $memberRaw === '' => 'Add who did the work.',
                $member === null => $this->unknownMember($memberRaw, $members),
                $dateRaw === '' => 'Add the date.',
                $parsed === null => "“{$dateRaw}” isn’t a date. Use 2026-04-01, 01/04/2026 or 1-Apr-26.",
                $parsed['kind'] === 'impossible' => $parsed['message'],
                $hoursRaw === '' => 'Add the hours.',
                $centi === null => "“{$hoursRaw}” isn’t a number of hours. Use 2.5 or 2:30.",
                $centi < Hours::MIN => 'Hours can’t be less than 0.25.',
                ! $monthly && $centi > Hours::MAX => 'Hours can’t be more than 24 in a day.',
                $projectRaw === '' => 'Add the project.',
                $project !== null && $project->status === ProjectStatus::Archived => "{$project->name} is archived. Restore it in Settings › Projects first.",
                $billable === null => 'Billable should be yes or no.',
                default => null,
            };

            if ($item->error === null && $monthly && $date !== null && $centi !== null) {
                $days = count(Hours::workingDays($date, Hours::mask($company->working_days, false)));

                if ($centi > Hours::MAX * $days) {
                    $item->error = "That’s more than 24 h a day over {$days} working days.";
                }
            }

            if ($item->error === null && $date !== null) {
                $closed = $this->years->closedYearFor($company, $date);

                if ($closed !== null) {
                    $item->error = "{$closed->label} is closed, so this date can’t be imported.";
                }
            }

            $checked[] = $item;
        }

        $this->duplicates($checked);
        $this->dayCaps($checked);

        return $checked;
    }

    /**
     * G4 (D116): imported day rows can't take a member past 24 h on a day, counting what's
     * already logged and the rows above them in the file.
     *
     * @param  list<ImportRow>  $rows
     */
    private function dayCaps(array $rows): void
    {
        $ok = array_values(array_filter($rows, fn (ImportRow $r) => $r->ok() && ! $r->monthly && $r->date !== null && $r->centi !== null));

        if ($ok === []) {
            return;
        }

        $dates = array_map(fn (ImportRow $r) => (string) $r->date?->toDateString(), $ok);
        $totals = [];

        TimeEntry::query()->withoutGlobalScope('company')
            ->whereIn('member_id', array_unique(array_map(fn (ImportRow $r) => $r->memberId, $ok)))
            ->whereBetween('date', [min($dates), max($dates)])
            ->get(['member_id', 'date', 'hours_centi'])
            ->each(function (TimeEntry $e) use (&$totals): void {
                $key = $e->member_id.'|'.$e->date->toDateString();
                $totals[$key] = ($totals[$key] ?? 0) + $e->hours_centi;
            });

        foreach ($ok as $row) {
            $key = $row->memberId.'|'.$row->date?->toDateString();
            $total = ($totals[$key] ?? 0) + (int) $row->centi;

            if ($total > Hours::MAX) {
                $row->error = "That puts {$row->member} at ".Hours::format($total).' h on '.$row->date?->format('j M Y').'. A day holds at most 24 h across all entries.';

                continue;
            }

            $totals[$key] = $total;
        }
    }

    /** @param list<ImportRow> $rows */
    private function duplicates(array $rows): void
    {
        $ok = array_filter($rows, fn (ImportRow $r) => $r->ok() && ! $r->monthly);
        $existing = [];

        if ($ok !== []) {
            $dates = array_map(fn (ImportRow $r) => $r->date?->toDateString(), $ok);

            TimeEntry::query()->withoutGlobalScope('company')
                ->with('project:id,name')
                ->whereIn('member_id', array_unique(array_map(fn (ImportRow $r) => $r->memberId, $ok)))
                ->whereBetween('date', [min($dates), max($dates)])
                ->get()
                ->each(function (TimeEntry $e) use (&$existing): void {
                    $existing[implode('|', [$e->member_id, $e->date->toDateString(), 'd', $e->hours_centi, mb_strtolower($e->project->name)])] = true;
                });
        }

        $seen = [];

        foreach ($rows as $row) {
            if (! $row->ok()) {
                continue;
            }

            $key = $row->key();

            if (isset($existing[$key])) {
                $row->error = "Already logged: {$row->member}, ".Hours::format((int) $row->centi).' h on '.$row->date?->format('j M Y').'.';
            } elseif (isset($seen[$key])) {
                $row->error = "Same as row {$seen[$key]}.";
            } else {
                $seen[$key] = $row->number;
            }
        }
    }

    /** @param Collection<int, Member> $members */
    private function unknownMember(string $raw, Collection $members): string
    {
        $best = null;
        $bestScore = 0.0;

        foreach ($members as $m) {
            similar_text(mb_strtolower($raw), mb_strtolower($m->name), $percent);

            if ($percent > $bestScore) {
                [$best, $bestScore] = [$m->name, $percent];
            }
        }

        return $best !== null && $bestScore >= 50
            ? "No member called “{$raw}”. Did you mean {$best}?"
            : "No member called “{$raw}”.";
    }

    /** yes / no, true / false, 1 / 0 (y / n); empty means billable. Null when it's none of these. */
    private static function billable(string $value): ?bool
    {
        return match (strtolower($value)) {
            '', 'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
    }
}
