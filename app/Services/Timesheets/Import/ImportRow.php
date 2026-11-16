<?php

declare(strict_types=1);

namespace App\Services\Timesheets\Import;

use Carbon\CarbonImmutable;

/** One checked CSV row (spec §8.2 preview): what it will import, or why it can't. */
final class ImportRow
{
    /** @param list<string> $cells */
    public function __construct(
        /** Data row number, 1-based (the header isn't counted). */
        public readonly int $number,
        /** The row's raw cells, for the error CSV. */
        public readonly array $cells,
        public readonly string $member,
        public readonly ?int $memberId,
        public readonly string $dateRaw,
        /** A day, or the first of the month for a monthly total. */
        public readonly ?CarbonImmutable $date,
        public readonly bool $monthly,
        public readonly string $hoursRaw,
        public readonly ?int $centi,
        public readonly string $project,
        public readonly ?int $projectId,
        public readonly bool $billable,
        public readonly ?string $notes,
        public ?string $error = null,
    ) {}

    public function ok(): bool
    {
        return $this->error === null;
    }

    /** For duplicate checks: the same member, day, hours and project. */
    public function key(): string
    {
        return implode('|', [$this->memberId, $this->date?->toDateString(), $this->monthly ? 'm' : 'd', $this->centi, mb_strtolower($this->project)]);
    }
}
