<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CheckVaultBreaches;
use App\Models\Company;
use App\Services\Vault\PwnedPasswords;
use App\Services\Vault\VaultPolicy;
use Illuminate\Console\Command;

/** Queues the weekly breach check for every company that has it on (spec §14.3, D74). */
final class VaultBreachCheck extends Command
{
    protected $signature = 'coffer:vault-breach-check {--company=* : Only these companies}';

    protected $description = 'Queue the Have I Been Pwned check of vault passwords';

    public function handle(): int
    {
        if (! PwnedPasswords::enabled()) {
            $this->info('Breach checks are off (VAULT_HIBP_ENABLED=false).');

            return self::SUCCESS;
        }

        /** @var list<string> $only */
        $only = $this->option('company');
        $queued = 0;

        foreach (Company::query()->when($only !== [], fn ($q) => $q->whereIn('id', array_map('intval', $only)))->get() as $company) {
            if (VaultPolicy::for($company)->breachChecks) {
                CheckVaultBreaches::dispatch($company->id);
                $queued++;
            }
        }

        $this->info("Queued breach checks for {$queued} ".($queued === 1 ? 'company' : 'companies').'.');

        return self::SUCCESS;
    }
}
