<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * Demo data (spec §24) carries known passwords, a shared demo 2FA key and a platform admin,
 * so it must never reach a production database (D93). Plans come from their migration.
 */
trait DemoOnly
{
    protected function refuseInProduction(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeders do not run in production: they create known passwords and 2FA keys.');
        }
    }
}
