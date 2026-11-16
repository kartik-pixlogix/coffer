<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Models\VaultAttachment;
use App\Models\VaultItemVersion;
use App\Models\VaultSecret;
use App\Services\Vault\ItemAttachments;
use App\Services\Vault\ItemSecrets;
use App\Services\Vault\ItemVersions;
use App\Services\Vault\VaultKeys;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Data-key rotation (D66): re-seals one chunk of a company's vault data (secrets, then
 * attachments, then version snapshots) still under a key older than `$toVersion`, then
 * queues itself for the next chunk. When nothing old is left the older keys are retired.
 * Resumable: a failed or interrupted run just picks up the rows still on old versions.
 * The payload holds ids only.
 */
final class ReencryptVaultData implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $companyId, public readonly int $toVersion, public readonly bool $chain = true) {}

    public function handle(CurrentCompany $current, VaultKeys $keys, ItemSecrets $secrets, ItemAttachments $attachments, ItemVersions $versions): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        $chunk = (int) config('vault.rotation_chunk');

        $done = $current->run($company, function () use ($chunk, $secrets, $attachments, $versions): int {
            $count = 0;

            foreach (VaultSecret::query()->where('key_version', '<', $this->toVersion)->orderBy('id')->limit($chunk)->get() as $row) {
                DB::transaction(fn () => $secrets->reseal($row));
                $count++;
            }

            if ($count < $chunk) {
                foreach (VaultAttachment::query()->where('key_version', '<', $this->toVersion)->orderBy('id')->limit($chunk - $count)->get() as $row) {
                    $attachments->reseal($row);
                    $count++;
                }
            }

            if ($count < $chunk) {
                foreach (VaultItemVersion::query()->where('key_version', '<', $this->toVersion)->orderBy('id')->limit($chunk - $count)->get() as $row) {
                    DB::transaction(fn () => $versions->reseal($row));
                    $count++;
                }
            }

            return $count;
        });

        if ($done > 0 && self::remaining($this->companyId, $this->toVersion) > 0) {
            if ($this->chain) {
                self::dispatch($this->companyId, $this->toVersion);
            }

            return;
        }

        $keys->retireBelow($this->companyId, $this->toVersion);
        Log::info('Vault data key rotated', ['company_id' => $this->companyId, 'version' => $this->toVersion]);
    }

    /** Rows of the company still sealed under a key older than `$version`. */
    public static function remaining(int $companyId, int $version): int
    {
        return VaultSecret::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('key_version', '<', $version)->count()
            + VaultAttachment::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('key_version', '<', $version)->count()
            + VaultItemVersion::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('key_version', '<', $version)->count();
    }
}
