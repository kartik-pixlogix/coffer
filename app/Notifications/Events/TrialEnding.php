<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\Workspace;
use App\Notifications\CofferNotification;

/** The workspace's free trial ends soon (D80); workspace-wide, so no company. */
final class TrialEnding extends CofferNotification
{
    public static function for(Workspace $workspace, int $days): self
    {
        $when = $days <= 1 ? 'tomorrow' : "in {$days} days";
        $date = $workspace->trialEndsAt()?->timezone('Asia/Kolkata')->format('j M Y') ?? 'then';

        return new self(
            null,
            "Your Coffer trial ends {$when}",
            "{$workspace->name} keeps every record. Choose a plan by {$date} to keep adding to it.",
            route('billing.index'),
        );
    }

    public function event(): string
    {
        return 'billing.trial_ending';
    }

    public function icon(): string
    {
        return 'hourglass';
    }

    public function tone(): string
    {
        return 'warn';
    }

    public function linkLabel(): string
    {
        return 'Choose a plan';
    }

    public function mailAction(): string
    {
        return 'Choose a plan';
    }
}
