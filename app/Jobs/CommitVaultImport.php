<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\VaultImport;
use App\Services\Vault\Transfer\VaultImporter;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Commits a vault import (D77). The payload is the import's id only: the file is read
 * from the private disk (sealed), parsed here and deleted when done.
 */
final class CommitVaultImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $importId) {}

    public function handle(CurrentCompany $current, VaultImporter $importer): void
    {
        $import = VaultImport::query()->withoutGlobalScope('company')->with(['user', 'vault', 'company'])->find($this->importId);

        if ($import === null || ! in_array($import->status, ['queued', 'running'], true)) {
            return;
        }

        $current->run($import->company, function () use ($import, $importer): void {
            $import->update(['status' => 'running']);
            $importer->commit($import);
        });
    }

    public function failed(?Throwable $e): void
    {
        $import = VaultImport::query()->withoutGlobalScope('company')->find($this->importId);

        if ($import !== null) {
            app(VaultImporter::class)->discard($import);
            $import->update(['status' => 'failed', 'summary' => ['error' => 'The import stopped. Nothing after the failure was saved; upload the file again.'], 'finished_at' => now()]);
        }
    }
}
