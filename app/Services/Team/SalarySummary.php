<?php

declare(strict_types=1);

namespace App\Services\Team;

/** What a salary run did, for the toast and the command output. */
final class SalarySummary
{
    /** @var array<'posted'|'updated'|'unchanged'|'removed'|'none'|'locked', int> */
    public array $counts = ['posted' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'none' => 0, 'locked' => 0];

    /** @param 'posted'|'updated'|'unchanged'|'removed'|'none'|'locked' $outcome */
    public function add(string $outcome): void
    {
        $this->counts[$outcome]++;
    }

    /** "6 posted, 1 updated, 2 unchanged" (zero counts left out). */
    public function describe(): string
    {
        $parts = [];

        foreach (['posted', 'updated', 'removed', 'unchanged'] as $key) {
            if ($this->counts[$key] > 0) {
                $parts[] = "{$this->counts[$key]} {$key}";
            }
        }

        if ($this->counts['locked'] > 0) {
            $parts[] = 'the month is in a closed fiscal year';
        }

        return $parts === [] ? 'nothing to post' : implode(', ', $parts);
    }
}
