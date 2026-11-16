<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Company;
use App\Models\User;

/**
 * "Credentials expiring soon" on the dashboard (spec §5, Main.dc.html): vault items
 * expiring within 30 days and the vault health score, for users with vault access.
 * The vault ships in Phase 7; until then NoVaultPanel returns null and the panel isn't
 * rendered (D64).
 *
 * TODO(Phase 7): bind a vault-backed implementation in AppServiceProvider.
 */
interface DashboardVaultPanel
{
    /**
     * Null hides the panel (no vault access, or no vault yet). Never include secrets.
     *
     * @return array{items: list<array{id: int, name: string, initials: string, expiresOn: string, days: int, href: string}>, healthScore: int|null, href: string}|null
     */
    public function for(Company $company, User $user): ?array;
}
