<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Models\DataExport;
use App\Services\Privacy\DataExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Builds a DPDPA export (D98) on the exports queue; the payload is the export id only. */
final class GenerateDataExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $exportId) {}

    public function handle(DataExporter $exporter): void
    {
        $export = DataExport::query()->with(['user', 'workspace.owner'])->find($this->exportId);

        if ($export === null || $export->status !== ExportStatus::Queued) {
            return;
        }

        $local = tempnam(sys_get_temp_dir(), 'cfex');

        try {
            if ($local === false) {
                throw new \RuntimeException('No temporary file for the export.');
            }

            if ($export->kind === DataExport::WORKSPACE && $export->workspace !== null) {
                $exporter->workspace($export->workspace, $local);
            } else {
                $exporter->account($export->user, $local);
            }

            $path = "privacy-exports/{$export->user_id}/".now()->format('Ymd-His')."-{$export->kind}-{$export->id}.zip";
            $stream = fopen($local, 'rb');

            if ($stream === false) {
                throw new \RuntimeException('Could not read the export archive.');
            }

            Storage::disk((string) config('privacy.disk'))->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $export->forceFill([
                'status' => ExportStatus::Ready,
                'path' => $path,
                'size' => (int) filesize($local),
                'expires_at' => now()->addDays((int) config('privacy.export_ttl_days')),
            ])->save();
        } catch (Throwable $e) {
            // Row contents never go to the log: the export id and exception class only.
            Log::warning('Data export failed', ['export' => $export->id, 'exception' => $e::class]);
            $export->forceFill(['status' => ExportStatus::Failed, 'error' => 'The export couldn’t be built. Try again.'])->save();
        } finally {
            if (is_string($local)) {
                @unlink($local);
            }
        }
    }
}
