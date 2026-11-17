<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\VaultImport;
use App\Services\Vault\Transfer\VaultImporter;
use Illuminate\Console\Command;

/**
 * Uploads that were never imported or cancelled are deleted after a day (D77): even
 * sealed, a file of someone's passwords shouldn't sit on disk. Runs hourly.
 */
final class VaultImportsClean extends Command
{
    protected $signature = 'coffer:vault-imports-clean';

    protected $description = 'Delete vault import uploads left unfinished for a day';

    public function handle(VaultImporter $importer): int
    {
        $stale = VaultImport::query()->withoutGlobalScope('company')->where('status', 'uploaded')
            ->where('created_at', '<', now()->subDay())->get();

        foreach ($stale as $import) {
            $importer->discard($import);
            $import->update(['status' => 'cancelled', 'finished_at' => now()]);
        }

        $this->info("Deleted {$stale->count()} unfinished vault ".($stale->count() === 1 ? 'upload' : 'uploads').'.');

        return self::SUCCESS;
    }
}
