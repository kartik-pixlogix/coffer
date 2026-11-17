<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/** Notifications are "Kept for 30 days" (Notifications.dc.html, spec §19, D78). */
final class NotificationsPrune extends Command
{
    protected $signature = 'coffer:notifications-prune';

    protected $description = 'Delete in-app notifications older than the retention period';

    public function handle(): int
    {
        $deleted = DatabaseNotification::query()
            ->where('created_at', '<', now()->subDays((int) config('notifications.retention_days')))
            ->delete();

        $this->info("Deleted {$deleted} old notifications.");

        return self::SUCCESS;
    }
}
