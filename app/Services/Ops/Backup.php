<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * One encrypted backup (spec §23, D96): `database.sql` from the dumper, the private and public
 * storage under `storage/`, and a `manifest.json`, every entry AES-256 encrypted with
 * BACKUP_PASSWORD (7-Zip, or PHP's ZipArchive, opens it). Written to the backup disk; older
 * backups past the retention are deleted by prune().
 */
class Backup
{
    public const string HEARTBEAT = 'ops:backup:last';

    public function __construct(private readonly DatabaseDumper $dumper) {}

    /** @return array{path: string, size: int, files: int} */
    public function run(bool $withFiles = true): array
    {
        $password = (string) config('backup.password');

        if (strlen($password) < 16) {
            throw new RuntimeException('Set BACKUP_PASSWORD (16+ characters): backups are always encrypted.');
        }

        $work = storage_path('framework/backup-'.bin2hex(random_bytes(6)));

        if (! is_dir($work) && ! mkdir($work, 0700, true)) {
            throw new RuntimeException('Could not create the backup work folder.');
        }

        $sql = $work.'/database.sql';
        $zipPath = $work.'/backup.zip';
        $now = CarbonImmutable::now();

        try {
            $this->dumper->dump($sql);

            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the backup archive.');
            }

            $zip->setPassword($password);
            $this->add($zip, $sql, 'database.sql');

            $files = 0;

            if ($withFiles) {
                /** @var array<string, string> $folders */
                $folders = (array) config('backup.folders');

                foreach ($folders as $name => $root) {
                    $files += $this->addTree($zip, $root, "storage/{$name}");
                }
            }

            $zip->addFromString('manifest.json', (string) json_encode([
                'app' => config('app.name'),
                'url' => config('app.url'),
                'environment' => app()->environment(),
                'created_at' => $now->toIso8601String(),
                'database' => config('database.default'),
                'files' => $files,
            ], JSON_PRETTY_PRINT));
            $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256);

            if (! $zip->close()) {
                throw new RuntimeException('Could not write the backup archive.');
            }

            $target = $this->folder().'/coffer-'.$now->format('Y-m-d-His').'.zip';
            $stream = fopen($zipPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException('Could not read the backup archive.');
            }

            try {
                if (! Storage::disk((string) config('backup.disk'))->writeStream($target, $stream)) {
                    throw new RuntimeException('Could not write the backup to the '.config('backup.disk').' disk.');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $size = (int) filesize($zipPath);
            Cache::forever(self::HEARTBEAT, $now->toIso8601String());

            return ['path' => $target, 'size' => $size, 'files' => $files];
        } finally {
            $this->removeTree($work);
        }
    }

    /**
     * Deletes backups older than the retention, never the newest one.
     *
     * @return list<string> the deleted paths
     */
    public function prune(): array
    {
        $disk = Storage::disk((string) config('backup.disk'));
        $cutoff = CarbonImmutable::now()->subDays(max(1, (int) config('backup.keep_days')));

        $backups = collect($disk->files($this->folder()))
            ->filter(fn (string $path): bool => preg_match('/coffer-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/', $path) === 1)
            ->sort()->values();

        $deleted = [];

        foreach ($backups->slice(0, -1) as $path) {
            if (preg_match('/coffer-(\d{4}-\d{2}-\d{2}-\d{6})\.zip$/', $path, $m) !== 1) {
                continue;
            }

            $taken = CarbonImmutable::createFromFormat('Y-m-d-His', $m[1]);

            if ($taken instanceof CarbonImmutable && $taken->lessThan($cutoff)) {
                $disk->delete($path);
                $deleted[] = $path;
            }
        }

        return $deleted;
    }

    private function folder(): string
    {
        return trim((string) config('backup.path'), '/');
    }

    private function add(ZipArchive $zip, string $file, string $name): void
    {
        $zip->addFile($file, $name);
        $zip->setEncryptionName($name, ZipArchive::EM_AES_256);
    }

    private function addTree(ZipArchive $zip, string $root, string $prefix): int
    {
        if (! is_dir($root)) {
            return 0;
        }

        $count = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getFilename() === '.gitignore') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            $this->add($zip, $file->getPathname(), "{$prefix}/{$relative}");
            $count++;
        }

        return $count;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
        }

        @rmdir($dir);
    }
}
