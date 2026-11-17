<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Jobs\CommitVaultImport;
use App\Jobs\GenerateReportExport;
use App\Models\PlatformAdmin;
use App\Notifications\CofferNotification;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

/*
| Horizon and queues (D95).
*/

test('Horizon is for platform admins only, in every environment', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->get('/horizon')->assertForbidden();
    $this->actingAs(userWithRole(CompanyRole::Admin))->get('/horizon')->assertForbidden();
    $this->actingAs(userWithRole(CompanyRole::Admin))->getJson('/horizon/api/stats')->assertForbidden();

    $admin = PlatformAdmin::query()->create(['name' => 'Priya Platform', 'email' => 'priya@coffer.test', 'password' => 'platform-password']);

    $this->actingAs($admin, 'platform')->get('/horizon')->assertOk();
});

test('slow work goes to its own queues', function () {
    $queue = app('queue');

    expect($queue->resolveQueueFromQueueRoute(new GenerateReportExport(1)))->toBe('exports')
        ->and($queue->resolveQueueFromQueueRoute(new CommitVaultImport(1)))->toBe('imports')
        ->and($queue->resolveQueueFromQueueRoute(Mockery::mock(CofferNotification::class)))->toBe('mail');
});
test('a failed job is logged at critical with its class only', function () {
    Log::shouldReceive('critical')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Queue job failed'
        && $context['exception'] === RuntimeException::class && ! array_key_exists('payload', $context));

    $job = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\Example');
    $job->shouldReceive('getQueue')->andReturn('default');

    event(new JobFailed('sync', $job, new RuntimeException('boom')));
});

test('the queue retry window outlasts the longest job timeout', function () {
    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThan(600)
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan(600);

});
