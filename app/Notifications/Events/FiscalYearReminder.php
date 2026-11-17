<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\Company;
use App\Notifications\CofferNotification;

/** A year that ended is still open (spec §19 "FY close reminder"). */
final class FiscalYearReminder extends CofferNotification
{
    public static function for(Company $company, string $label, string $endedOn): self
    {
        return new self(
            $company->id,
            "{$label} is ready to close",
            "It ended on {$endedOn}. Closing posts each partner’s share of the profit and locks the year.",
            route('settings.company'),
        );
    }

    public function event(): string
    {
        return 'fy.reminder';
    }

    public function icon(): string
    {
        return 'calendar-check';
    }

    public function tone(): string
    {
        return 'warn';
    }

    public function linkLabel(): string
    {
        return 'Close the year';
    }
}
