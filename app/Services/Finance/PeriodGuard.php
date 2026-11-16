<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Ledger\Exceptions\PeriodLockedException;
use App\Ledger\FiscalYears;
use App\Models\Company;
use Closure;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * Spec §4.6: nothing dated inside a closed fiscal year can be created, edited or deleted.
 * Records check every date they touch up front, and a lock the ledger finds anyway
 * (a race with a close) becomes the same validation error.
 */
final class PeriodGuard
{
    public function __construct(private readonly FiscalYears $years) {}

    /** @param iterable<DateTimeInterface|null> $dates */
    public function assertOpen(Company $company, iterable $dates, string $field): void
    {
        foreach ($dates as $date) {
            if ($date === null) {
                continue;
            }

            $closed = $this->years->closedYearFor($company, $date);

            if ($closed !== null) {
                throw ValidationException::withMessages([$field => PeriodLockedException::for($closed->label)->getMessage()]);
            }
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Closure $callback, string $field): mixed
    {
        try {
            return $callback();
        } catch (PeriodLockedException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }
}
