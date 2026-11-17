<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Ops\Health;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Queued by the scheduler every five minutes; /health reads when a worker last ran it (D97). */
final class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Cache::forever(Health::QUEUE_HEARTBEAT, now()->toIso8601String());
    }
}
