<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\Company;
use App\Notifications\CofferNotification;
use App\Services\Team\SalarySummary;
use Carbon\CarbonImmutable;

/** The monthly salary run finished (spec §19 "salary run done", §8.3). */
final class SalaryRunDone extends CofferNotification
{
    public static function for(Company $company, CarbonImmutable $month, SalarySummary $summary): self
    {
        return new self(
            $company->id,
            'Salaries for '.$month->format('F Y').' are posted',
            ucfirst($summary->describe()),
            route('team.index'),
        );
    }

    public function event(): string
    {
        return 'salary.run';
    }

    public function icon(): string
    {
        return 'users';
    }

    public function linkLabel(): string
    {
        return 'Open Team';
    }
}
