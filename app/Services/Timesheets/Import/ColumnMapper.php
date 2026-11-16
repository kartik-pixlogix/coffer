<?php

declare(strict_types=1);

namespace App\Services\Timesheets\Import;

/**
 * Matches CSV columns to Coffer fields (spec §8.2, BulkImport.dc.html "Map columns").
 * Headers are matched by name or alias ("Log owner" → member, "Log Date" → date, "Log
 * Hours" / "Time spent" → hours, "Item Name" → project, or notes when there's a project
 * column); a column nobody named can still be matched to members by its values.
 */
final class ColumnMapper
{
    /** The Coffer fields, in the order the mapping select lists them. */
    public const array FIELDS = ['member', 'date', 'hours', 'project', 'billable', 'notes'];

    public const array REQUIRED = ['member', 'date', 'hours', 'project'];

    private const array ALIASES = [
        'member' => ['member', 'developer', 'log owner', 'owner', 'person', 'employee', 'team member', 'name', 'email', 'member email', 'user', 'resource'],
        'date' => ['date', 'log date', 'day', 'work date', 'entry date', 'worked on'],
        'hours' => ['hours', 'log hours', 'time spent', 'time', 'duration', 'hrs', 'hours logged', 'spent'],
        'project' => ['project', 'project name', 'client project'],
        'billable' => ['billable', 'is billable', 'billable?', 'chargeable'],
        'notes' => ['notes', 'note', 'description', 'comment', 'comments', 'task', 'details', 'work'],
    ];

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     * @param  list<string>  $memberKeys  lower-cased member names and emails
     * @return array{mapping: array<int, string|null>, how: array<int, 'auto'|'values'|null>}
     */
    public static function guess(array $header, array $rows, array $memberKeys): array
    {
        $mapping = [];
        $how = [];
        $taken = [];

        foreach ($header as $i => $name) {
            $field = self::fieldFor($name);

            if ($field !== null && ! in_array($field, $taken, true)) {
                $mapping[$i] = $field;
                $how[$i] = 'auto';
                $taken[] = $field;
            } else {
                $mapping[$i] = null;
                $how[$i] = null;
            }
        }

        // "Item Name" is the project, unless another column already is.
        foreach ($header as $i => $name) {
            if ($mapping[$i] === null && self::key($name) === 'item name') {
                $field = in_array('project', $taken, true) ? 'notes' : 'project';

                if (! in_array($field, $taken, true)) {
                    $mapping[$i] = $field;
                    $how[$i] = 'auto';
                    $taken[] = $field;
                }
            }
        }

        // No member column by name: the column whose values are mostly team members.
        if (! in_array('member', $taken, true)) {
            foreach ($header as $i => $name) {
                if ($mapping[$i] === null && self::looksLikeMembers(array_column($rows, $i), $memberKeys)) {
                    $mapping[$i] = 'member';
                    $how[$i] = 'values';

                    break;
                }
            }
        }

        return ['mapping' => $mapping, 'how' => $how];
    }

    /**
     * Up to three distinct sample values per column for the mapping table.
     *
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    public static function samples(array $rows, int $column): array
    {
        $values = [];

        foreach ($rows as $row) {
            $value = $row[$column] ?? '';

            if ($value !== '' && ! in_array($value, $values, true)) {
                $values[] = $value;
            }

            if (count($values) === 3) {
                break;
            }
        }

        return $values;
    }

    /**
     * @param  array<int, string|null>  $mapping
     * @return list<string> required fields no column maps to
     */
    public static function missing(array $mapping): array
    {
        return array_values(array_diff(self::REQUIRED, array_filter($mapping)));
    }

    private static function fieldFor(string $header): ?string
    {
        $key = self::key($header);

        foreach (self::ALIASES as $field => $aliases) {
            if (in_array($key, $aliases, true)) {
                return $field;
            }
        }

        return null;
    }

    private static function key(string $header): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower(trim($header, " \t?:*"))));
    }

    /**
     * @param  list<string>  $values
     * @param  list<string>  $memberKeys
     */
    private static function looksLikeMembers(array $values, array $memberKeys): bool
    {
        $values = array_slice(array_values(array_filter($values, fn ($v) => $v !== '')), 0, 50);

        if ($values === [] || $memberKeys === []) {
            return false;
        }

        $hits = count(array_filter($values, fn ($v) => in_array(strtolower($v), $memberKeys, true)));

        return $hits * 2 >= count($values);
    }
}
