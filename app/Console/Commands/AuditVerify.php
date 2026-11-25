<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Notifications\AuditTrailBroken;
use App\Services\Audit\AuditChain;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Checks every company's audit trail hash chain (D153). A broken chain means a row was
 * changed, removed or reordered outside the app: it's logged at critical and the company's
 * Admins get an email. The result is kept for /health and the Audit trail page.
 */
final class AuditVerify extends Command
{
    public const string RESULT = 'ops:audit:verified';

    protected $signature = 'coffer:audit-verify {--company= : Only this company id}';

    protected $description = 'Check that no audit trail row was changed or removed';

    public function handle(AuditChain $chain): int
    {
        $broken = 0;

        $companies = Company::query()->withTrashed()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            $problem = $chain->verify($company->id);

            if ($problem === null) {
                continue;
            }

            $broken++;
            $message = "Event {$problem['seq']} {$problem['problem']}.";
            Log::critical('Audit trail chain is broken', ['company_id' => $company->id, 'seq' => $problem['seq'], 'problem' => $problem['problem']]);
            Notification::send($company->admins(), new AuditTrailBroken($company, $message));
            $this->error("{$company->name}: {$message}");
        }

        if (! $this->option('company')) {
            Cache::forever(self::RESULT, ['at' => CarbonImmutable::now()->toIso8601String(), 'broken' => $broken]);
        }

        $this->info($broken === 0 ? "All {$companies->count()} audit trails are intact." : "{$broken} of {$companies->count()} audit trails are broken.");

        return $broken === 0 ? self::SUCCESS : self::FAILURE;
    }
}
