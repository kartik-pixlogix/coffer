<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Company;
use App\Services\Vault\Aead;
use App\Services\Vault\VaultKeys;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoices and receipts (spec §9, §10): PDF, JPG or PNG up to 10 MB, kept on the private
 * disk under the company's folder with a random name, and only ever served through an
 * authorised route.
 *
 * Encrypted at rest (Phase 10, D149): the bytes are sealed with the company's data key
 * (AES-256-GCM, AAD naming the company and the file's random name) and written as
 * `{random}.v{key version}.cfe`, so the file on disk is ciphertext, its name says nothing,
 * and key rotation can find files on old keys from the path alone. Files stored before
 * this (any other extension) are still served as they are until the backfill seals them.
 */
final readonly class Attachments
{
    public const string DISK = 'local';

    /** Validation rules shared by income invoices and expense receipts. */
    public const array RULES = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'];

    /** Tables and columns holding sealed finance files (for rotation and the backfill). */
    public const array COLUMNS = ['incomes' => 'attachment_path', 'expenses' => 'receipt_path'];

    private const string MAGIC = 'cfe1:';

    public function __construct(private VaultKeys $keys) {}

    /** @return array{path: string, name: string, mime: string, size: int} */
    public function store(Company $company, string $folder, UploadedFile $file): array
    {
        $bytes = file_get_contents($file->getRealPath());

        if ($bytes === false) {
            throw new RuntimeException('The upload could not be read.');
        }

        return [
            'path' => $this->write($company->id, "companies/{$company->id}/{$folder}", Str::random(40), $bytes),
            'name' => Str::limit(basename($file->getClientOriginalName()), 200, ''),
            'mime' => (string) $file->getMimeType(),
            'size' => strlen($bytes),
        ];
    }

    /** The plaintext bytes of a stored file. */
    public function read(int $companyId, string $path): string
    {
        $raw = $this->disk()->get($path) ?? throw new RuntimeException('The file is missing.');
        $parsed = self::parse($path);

        if ($parsed === null) {
            return $raw;
        }

        if (! str_starts_with($raw, self::MAGIC)) {
            throw new RuntimeException('The file is damaged.');
        }

        return Aead::open($this->keys->dataKey($companyId, $parsed['version']), substr($raw, strlen(self::MAGIC)), self::aad($companyId, $parsed['name']), 'file');
    }

    /**
     * Seals a file again under the active key (rotation), or for the first time (a file stored
     * before D149). Writes the new file first and returns its path; the caller saves the path,
     * then deletes the old file. Null when it is already on the active key.
     */
    public function reseal(int $companyId, string $path): ?string
    {
        $parsed = self::parse($path);
        $active = $this->keys->activeVersion($companyId);

        if ($parsed !== null && $parsed['version'] === $active) {
            return null;
        }

        return $this->write($companyId, dirname($path), $parsed['name'] ?? Str::random(40), $this->read($companyId, $path));
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            $this->disk()->delete($path);
        }
    }

    /** Shown inline (images and PDFs preview in the drawer) unless `$download`. */
    public function response(int $companyId, string $path, string $name, ?string $mime, bool $download): StreamedResponse
    {
        abort_unless($this->disk()->exists($path), 404);

        $bytes = $this->read($companyId, $path);
        $fallback = Str::ascii($name) !== '' ? str_replace(['/', '\\', '%'], '-', Str::ascii($name)) : 'file';

        return new StreamedResponse(function () use ($bytes): void {
            echo $bytes;
        }, 200, [
            'Content-Type' => $mime ?? 'application/octet-stream',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => HeaderUtils::makeDisposition($download ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE, $name, $fallback),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    private function write(int $companyId, string $folder, string $name, string $bytes): string
    {
        $version = $this->keys->activeVersion($companyId);
        $path = "{$folder}/{$name}.v{$version}.cfe";

        if (! $this->disk()->put($path, self::MAGIC.Aead::seal($this->keys->dataKey($companyId, $version), $bytes, self::aad($companyId, $name)))) {
            throw new RuntimeException('The file could not be saved.');
        }

        return $path;
    }

    /** @return array{name: string, version: int}|null null for a file stored before D149 */
    private static function parse(string $path): ?array
    {
        return preg_match('/([A-Za-z0-9]{40})\.v(\d+)\.cfe$/', $path, $m) === 1 ? ['name' => $m[1], 'version' => (int) $m[2]] : null;
    }

    private static function aad(int $companyId, string $name): string
    {
        return "finance_file:{$companyId}:{$name}";
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
