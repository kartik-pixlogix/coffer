<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prints fresh vault keys for .env (D66). It never writes them anywhere: keys belong in
 * the environment or a secrets manager, and a lost master key can't be recovered.
 */
final class VaultKeyCommand extends Command
{
    protected $signature = 'coffer:vault-key';

    protected $description = 'Print a new VAULT_MASTER_KEY and VAULT_FINGERPRINT_KEY (nothing is written)';

    public function handle(): int
    {
        $this->line('VAULT_MASTER_KEY=base64:'.base64_encode(random_bytes(32)));
        $this->line('VAULT_FINGERPRINT_KEY=base64:'.base64_encode(random_bytes(32)));
        $this->newLine();
        $this->comment('Add these to .env (or your secrets manager). Keep a safe copy: without the master key the vault can’t be opened.');
        $this->comment('Changing the fingerprint key later only affects reuse detection until items are saved again.');
        $this->comment('Rotating the master key: put the old one in VAULT_PREVIOUS_MASTER_KEYS, set the new one, run coffer:vault-rotate --master.');

        return self::SUCCESS;
    }
}
