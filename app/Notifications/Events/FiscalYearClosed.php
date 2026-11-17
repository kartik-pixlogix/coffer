<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\Company;
use App\Notifications\CofferNotification;

/** A fiscal year was closed (spec §4.6): capital carried forward, the year locked. */
final class FiscalYearClosed extends CofferNotification
{
    public static function for(Company $company, string $label, string $by): self
    {
        return new self(
            $company->id,
            "{$label} is closed",
            "{$by} closed {$company->name}’s books for {$label}. Capital is carried forward and the year is locked.",
            route('capital-accounts.index'),
        );
    }

    public function event(): string
    {
        return 'fy.closed';
    }

    public function icon(): string
    {
        return 'lock';
    }

    public function linkLabel(): string
    {
        return 'See capital accounts';
    }
}
