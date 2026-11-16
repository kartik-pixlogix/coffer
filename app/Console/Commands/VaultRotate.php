<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReencryptVaultData;
use App\Models\Company;
use App\Models\VaultKey;
use App\Services\Vault\VaultKeys;
use Illuminate\Console\Command;

/**
 * Vault key rotation (spec §14.3, D66).
 *
 * - `--master`: re-wrap every company's data keys with the current VAULT_MASTER_KEY (the
 *   old key must be in VAULT_PREVIOUS_MASTER_KEYS). Quick: secrets aren't touched.
 * - otherwise: rotate the data key of each company given (or `--all`): a new key version
 *   seals new data at once, and a queued, chunked job re-encrypts the old rows. Running
 *   it again while rows are still on old keys resumes instead of starting over.
 */
final class VaultRotate extends Command
{
    protected $signature = 'coffer:vault-rotate
        {--master : Re-wrap data keys with the current master key}
        {--company=* : Rotate these companies\' data keys}
        {--all : Rotate every company\'s data key}
        {--sync : Re-encrypt now instead of queueing}';

    protected $description = 'Rotate the vault master key or companies’ data keys';

    public function handle(VaultKeys $keys): int
    {
        if ($this->option('master')) {
            $count = $keys->rewrapAll();
            $this->info($count === 0 ? 'Every data key is already wrapped with the current master key.' : "Re-wrapped {$count} data ".($count === 1 ? 'key' : 'keys').'.');

            if (! $this->option('all') && $this->option('company') === []) {
                return self::SUCCESS;
            }
        }

        /** @var list<string> $ids */
        $ids = $this->option('company');
        $companies = Company::query()
            ->when(! $this->option('all'), fn ($q) => $q->whereIn('id', array_map('intval', $ids)))
            ->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->error('Name a company with --company=ID, or use --all (or --master to re-wrap keys).');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            $latest = (int) VaultKey::query()->where('company_id', $company->id)->max('version');
            $pending = $latest === 0 ? 0 : ReencryptVaultData::remaining($company->id, $latest);

            if ($latest !== 0 && $pending > 0) {
                $version = $latest;
                $this->line("{$company->name}: resuming rotation to key v{$version} ({$pending} left).");
            } else {
                $version = $keys->addVersion($company->id);
                $this->line("{$company->name}: new data key v{$version}.");
            }

            if ($this->option('sync')) {
                do {
                    app()->call([new ReencryptVaultData($company->id, $version, chain: false), 'handle']);
                } while (ReencryptVaultData::remaining($company->id, $version) > 0);
            } else {
                ReencryptVaultData::dispatch($company->id, $version);
            }
        }

        $this->info($this->option('sync') ? 'Re-encrypted.' : 'Re-encryption queued.');

        return self::SUCCESS;
    }
}
