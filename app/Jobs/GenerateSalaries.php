<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Models\Member;
use App\Services\Team\SalaryGenerator;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-runs salary for a company-month, or one member's month (spec §8.3): after hours
 * change in a month that already has a salary, the entry is reversed and re-posted.
 */
final class GenerateSalaries implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $companyId,
        /** "YYYY-MM" */
        public readonly string $month,
        public readonly ?int $memberId = null,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return "{$this->companyId}:{$this->month}:".($this->memberId ?? 'all');
    }

    public function handle(CurrentCompany $current, SalaryGenerator $salaries): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        $month = CarbonImmutable::createFromFormat('!Y-m', $this->month) ?: CarbonImmutable::today();

        $current->run($company, function () use ($company, $month, $salaries): void {
            if ($this->memberId === null) {
                $salaries->runMonth($company, $month);

                return;
            }

            $member = Member::query()->withTrashed()->with('rates')->find($this->memberId);

            if ($member !== null) {
                $salaries->runMember($company, $member, $month);
            }
        });
    }
}
