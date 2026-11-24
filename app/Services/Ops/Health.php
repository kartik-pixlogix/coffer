<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Ledger\LedgerGuard;
use App\Models\VaultKey;
use App\Services\Vault\VaultKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The deep health check behind GET /health (spec §23 "a health-check endpoint", D97). Each
 * check answers ok / warn / fail with a short detail, never a secret, a path or an exception
 * message: database, cache, queue heartbeat (a job the scheduler queues every five minutes),
 * scheduler heartbeat (written every minute), the vault master key (loaded, and it opens
 * every stored data key), the ledger guard triggers (D148) and the last backup.
 */
class Health
{
    public const string QUEUE_HEARTBEAT = 'ops:queue:last';

    public const string SCHEDULER_HEARTBEAT = 'ops:scheduler:last';

    public function __construct(private readonly VaultKeys $keys) {}

    /** @return array{status: 'ok'|'warn'|'fail', checks: array<string, array{status: 'ok'|'warn'|'fail', detail: string}>} */
    public function report(): array
    {
        $checks = [
            'database' => $this->guard(function (): array {
                DB::select('select 1');

                return ['ok', 'connected'];
            }),
            'cache' => $this->guard(function (): array {
                $key = 'ops:health:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                $read = Cache::get($key);
                Cache::forget($key);

                return $read === 'ok' ? ['ok', 'read and write'] : ['fail', 'could not read back'];
            }),
            'queue' => $this->guard(fn (): array => $this->heartbeat(self::QUEUE_HEARTBEAT, warnAfter: 15, failAfter: 60, what: 'job')),
            'scheduler' => $this->guard(fn (): array => $this->heartbeat(self::SCHEDULER_HEARTBEAT, warnAfter: 5, failAfter: 30, what: 'tick')),
            'vault' => $this->guard(function (): array {
                $known = $this->keys->masterIds();
                $used = VaultKey::query()->withoutGlobalScopes()->distinct()->pluck('master_key_id')->all();
                $missing = array_diff($used, $known);

                return $missing === [] ? ['ok', 'master key loaded'] : ['fail', count($missing).' data key(s) wrapped by a master key that is not configured'];
            }),
            'ledger_guard' => $this->guard(fn (): array => match (true) {
                ! LedgerGuard::supported() => ['warn', 'not available on this database'],
                LedgerGuard::installed() => ['ok', 'posted rows locked'],
                default => ['warn', 'triggers missing: run coffer:ledger-guard --install'],
            }),
            'backup' => $this->guard(fn (): array => $this->heartbeat(Backup::HEARTBEAT, warnAfter: 26 * 60, failAfter: 72 * 60, what: 'backup')),
        ];

        $statuses = array_column($checks, 'status');
        $status = in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');

        return ['status' => $status, 'checks' => $checks];
    }

    /** @return array{0: 'ok'|'warn'|'fail', 1: string} */
    private function heartbeat(string $key, int $warnAfter, int $failAfter, string $what): array
    {
        $last = Cache::get($key);

        if (! is_string($last)) {
            return ['warn', "no {$what} recorded yet"];
        }

        $minutes = (int) CarbonImmutable::parse($last)->diffInMinutes(CarbonImmutable::now(), true);
        $detail = "last {$what} {$minutes} min ago";

        return [$minutes >= $failAfter ? 'fail' : ($minutes >= $warnAfter ? 'warn' : 'ok'), $detail];
    }

    /**
     * @param  callable(): array{0: 'ok'|'warn'|'fail', 1: string}  $check
     * @return array{status: 'ok'|'warn'|'fail', detail: string}
     */
    private function guard(callable $check): array
    {
        try {
            [$status, $detail] = $check();

            return ['status' => $status, 'detail' => $detail];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => class_basename($e)];
        }
    }
}
