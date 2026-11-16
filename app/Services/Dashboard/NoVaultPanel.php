<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Company;
use App\Models\User;

/** Until the Credentials Vault ships (Phase 7) the dashboard has no vault panel (D64). */
final class NoVaultPanel implements DashboardVaultPanel
{
    public function for(Company $company, User $user): ?array
    {
        return null;
    }
}
