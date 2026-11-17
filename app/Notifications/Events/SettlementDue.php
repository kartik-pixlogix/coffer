<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\Company;
use App\Notifications\CofferNotification;
use App\Support\Money;

/** One JV side owes another more than the threshold (spec §19 "settlement due"). */
final class SettlementDue extends CofferNotification
{
    public static function for(Company $company, string $from, string $to, int $paise, string $period): self
    {
        return new self(
            $company->id,
            'Settlement due: '.Money::format($paise),
            "{$from} owes {$to} for {$period}",
            route('settlements.index'),
        );
    }

    public function event(): string
    {
        return 'settlement.due';
    }

    public function icon(): string
    {
        return 'arrow-left-right';
    }

    public function linkLabel(): string
    {
        return 'Record the transfer';
    }
}
