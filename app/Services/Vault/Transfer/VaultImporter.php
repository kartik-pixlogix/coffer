<?php

declare(strict_types=1);

namespace App\Services\Vault\Transfer;

use App\Actions\Vault\SaveVaultItem;
use App\Enums\VaultAuditAction;
use App\Enums\VaultImportFormat;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultImport;
use App\Services\Vault\ItemTemplates;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultAudit;
use App\Services\Vault\VaultCrypto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Vault import (spec §14.4, D77), the timesheet flow's three steps: upload (the file is
 * sealed with the company's data key on the private disk at once — it holds plaintext
 * secrets), map a plain CSV's columns and preview (new, duplicate, invalid — names only),
 * then commit in a queued job that saves each new row through SaveVaultItem as the person
 * importing, reports duplicates and skipped rows by line, and deletes the upload.
 */
final readonly class VaultImporter
{
    public const int MAX_KB = 5120;

    public const int PREVIEW_ROWS = 200;

    public function __construct(
        private VaultCrypto $crypto,
        private ImportParser $parser,
        private ImportAnalyser $analyser,
        private SaveVaultItem $save,
        private VaultAudit $audit,
        private VaultAccess $access,
        private ExportCrypto $exportCrypto,
    ) {}

    /** @throws RuntimeException with a message for the person */
    public function store(User $user, Vault $vault, UploadedFile $file, #[SensitiveParameter] ?string $passphrase): VaultImport
    {
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        $format = ImportParser::detect($bytes);

        if ($format === VaultImportFormat::Coffer) {
            if ($passphrase === null || $passphrase === '') {
                throw new RuntimeException('This is a Coffer export. Enter its passphrase to import it.');
            }

            $bytes = $this->exportCrypto->open($bytes, $passphrase);
        }

        $parsed = $this->parser->parse($format, $bytes);
        $header = $format->needsMapping() ? CsvTable::read($bytes)->header : null;

        $uuid = (string) Str::uuid();
        $path = "vault-imports/{$vault->company_id}/{$uuid}.bin";
        $sealed = $this->crypto->encrypt($vault->company_id, $bytes, self::aad($vault->company_id, $path));
        unset($bytes);
        Storage::disk(VaultImport::DISK)->put($path, $sealed->ciphertext);

        return VaultImport::query()->create([
            'company_id' => $vault->company_id,
            'user_id' => $user->id,
            'vault_id' => $vault->id,
            'name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'format' => $format,
            'path' => $path,
            'key_version' => $sealed->keyVersion,
            'size' => (int) $file->getSize(),
            'rows' => count($parsed['rows']) + count($parsed['skipped']),
            'header' => $header,
            'mapping' => $header === null ? null : ImportParser::guessMapping($header),
            'status' => 'uploaded',
        ]);
    }

    /**
     * The preview: counts and up to 200 rows, by name — never a value.
     *
     * @return array{counts: array{new: int, duplicate: int, invalid: int, skipped: int}, rows: list<array<string, mixed>>, skipped: list<array{line: int, reason: string}>}
     */
    public function preview(VaultImport $import): array
    {
        ['rows' => $analysed, 'skipped' => $skipped] = $this->analysed($import);
        $counts = ['new' => 0, 'duplicate' => 0, 'invalid' => 0, 'skipped' => count($skipped)];
        $rows = [];

        foreach ($analysed as ['row' => $row, 'status' => $status, 'reason' => $reason]) {
            $counts[$status]++;

            if (count($rows) < self::PREVIEW_ROWS) {
                $rows[] = [
                    'line' => $row->line,
                    'type' => $row->type->value,
                    'typeLabel' => $row->type->label(),
                    'name' => $row->name,
                    'subtitle' => $row->subtitle(),
                    'url' => $row->url(),
                    // Which secrets came in, by label (never the values).
                    'secrets' => array_map(fn (string $k) => ItemTemplates::byKey($row->type)[$k]['label'] ?? ($k === ItemTemplates::NOTES ? 'Notes' : $k), array_keys($row->secrets)),
                    'customFields' => count($row->customFields),
                    'tags' => $row->tags,
                    'status' => $status,
                    'reason' => $reason,
                    'notes' => $row->notes,
                ];
            }
        }

        return ['counts' => $counts, 'rows' => $rows, 'skipped' => $skipped];
    }

    /** The queued commit: save new rows, report the rest, delete the upload, audit. */
    public function commit(VaultImport $import): void
    {
        $user = $import->user;
        $vault = $import->vault;
        $this->access->flush();

        if (! $this->access->canCreateIn($user, $vault)) {
            $this->finish($import, 'failed', ['error' => 'You can no longer add items to this vault.']);

            return;
        }

        try {
            ['rows' => $analysed, 'skipped' => $skipped] = $this->analysed($import);
        } catch (RuntimeException $e) {
            $this->finish($import, 'failed', ['error' => $e->getMessage()]);

            return;
        }

        $created = 0;
        $duplicates = [];
        $mayRestrict = $this->access->isAdmin($user);

        foreach ($analysed as ['row' => $row, 'status' => $status, 'reason' => $reason]) {
            if ($status === 'duplicate') {
                $duplicates[] = ['line' => $row->line, 'name' => $row->name];

                continue;
            }

            if ($status === 'invalid') {
                $skipped[] = ['line' => $row->line, 'reason' => (string) $reason];

                continue;
            }

            try {
                $this->save->handle($user, $this->payload($vault, $row), null, [], $mayRestrict);
                $created++;
            } catch (Throwable $e) {
                // Never the row's contents: its line and the kind of failure only.
                Log::warning("Vault import {$import->id}: line {$row->line} couldn’t be saved (".class_basename($e).').');
                $skipped[] = ['line' => $row->line, 'reason' => 'Couldn’t be saved'];
            }
        }

        $this->audit->record(VaultAuditAction::Imported, $user, $vault, null, [
            'format' => $import->format->value,
            'created' => $created,
            'duplicates' => count($duplicates),
            'skipped' => count($skipped),
        ], $import->company_id);

        activity('vault')->performedOn($vault)->causedBy($user)
            ->withProperties(['import_id' => $import->id, 'created' => $created, 'duplicates' => count($duplicates), 'skipped' => count($skipped)])
            ->log('Vault items imported');

        $this->finish($import, 'done', ['created' => $created, 'duplicates' => $duplicates, 'skipped' => $skipped]);
    }

    /** Cancel, or tidy up a finished one: the sealed upload goes. */
    public function discard(VaultImport $import): void
    {
        if ($import->path !== null) {
            Storage::disk(VaultImport::DISK)->delete($import->path);
            $import->update(['path' => null]);
        }
    }

    /** @return array{rows: list<array{row: ImportRow, status: 'new'|'duplicate'|'invalid', reason: string|null}>, skipped: list<array{line: int, reason: string}>} */
    private function analysed(VaultImport $import): array
    {
        $parsed = $this->parser->parse($import->format, $this->read($import), $import->mapping ?? []);

        return ['rows' => $this->analyser->analyse($import->company_id, $parsed['rows']), 'skipped' => $parsed['skipped']];
    }

    private function read(VaultImport $import): string
    {
        if ($import->path === null || $import->key_version === null || ! Storage::disk(VaultImport::DISK)->exists($import->path)) {
            throw new RuntimeException('The uploaded file is gone. Upload it again.');
        }

        return $this->crypto->decrypt($import->company_id, (string) Storage::disk(VaultImport::DISK)->get($import->path), $import->key_version, self::aad($import->company_id, $import->path), 'import file');
    }

    /** @return array<string, mixed> SaveVaultItem's input (VaultItemRequest's shape) */
    private function payload(Vault $vault, ImportRow $row): array
    {
        return [
            'vault_id' => $vault->id,
            'type' => $row->type->value,
            'provider' => $row->provider,
            'name' => $row->name,
            'environment' => $row->environment,
            'fields' => $row->fields,
            'secrets' => $row->secrets,
            'custom_fields' => array_map(fn (array $c) => ['id' => null, 'label' => $c['label'], 'secret' => $c['secret'], 'value' => $c['value']], $row->customFields),
            'tags' => $row->tags,
            'expires_at' => $row->expiresAt,
            'restricted' => $row->restricted,
        ];
    }

    /** @param array<string, mixed> $summary */
    private function finish(VaultImport $import, string $status, array $summary): void
    {
        $this->discard($import);
        $import->update(['status' => $status, 'summary' => $summary, 'finished_at' => now()]);
    }

    private static function aad(int $companyId, string $path): string
    {
        return "vault_import:{$companyId}:{$path}";
    }
}
