<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Vault\RevokeVaultAccess;
use App\Models\Company;
use App\Models\VaultGrant;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Ends grants whose "Access ends" date has passed (spec §14.2, D72): removes them and
 * writes an Unshared audit event ("expired") for each. VaultAccess already ignores ended
 * grants, so this only tidies up and keeps the log complete; it runs every 15 minutes.
 */
final class ExpireVaultGrants extends Command
{
    protected $signature = 'coffer:vault-expire-grants';

    protected $description = 'Remove vault grants whose end date has passed and audit them';

    public function handle(CurrentCompany $current, RevokeVaultAccess $revoke): int
    {
        $count = 0;
        $companyIds = VaultGrant::query()->withoutGlobalScope('company')
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->distinct()->pluck('company_id');

        foreach (Company::query()->whereIn('id', $companyIds)->get() as $company) {
            $count += (int) $current->run($company, function () use ($revoke): int {
                $grants = VaultGrant::query()->with('user')->whereNotNull('expires_at')->where('expires_at', '<=', now())->get();

                foreach ($grants as $grant) {
                    $revoke->handle(null, $grant, 'expired');
                }

                return $grants->count();
            });
        }

        $this->info($count === 0 ? 'No vault access ended.' : "Ended {$count} vault ".($count === 1 ? 'grant' : 'grants').'.');

        return self::SUCCESS;
    }
}
