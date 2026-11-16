<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Vault\Totp;
use Database\Seeders\DemoVaultSeeder;
use Illuminate\Console\Command;

/**
 * Local helper: the current sign-in / vault code of the seeded demo accounts, which all
 * share DemoVaultSeeder::DEMO_2FA_KEY (D71). Refuses to run in production.
 */
final class DemoTwoFactorCode extends Command
{
    protected $signature = 'coffer:demo-2fa';

    protected $description = 'Print the current 2FA code of the demo accounts (local only)';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        $code = Totp::now(DemoVaultSeeder::DEMO_2FA_KEY);
        $this->line("{$code['code']}  (valid for {$code['secondsLeft']} more seconds; demo accounts only)");

        return self::SUCCESS;
    }
}
