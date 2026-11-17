<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VaultImportFormat;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vault import in progress or done (D77). The uploaded file is sealed on the private
 * disk at `path` until the import is committed or cancelled; nothing here holds a secret.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property int $vault_id
 * @property string $name
 * @property VaultImportFormat $format
 * @property string|null $path
 * @property int|null $key_version
 * @property int $size
 * @property int $rows
 * @property list<string>|null $header
 * @property array<int, string|null>|null $mapping
 * @property string $status uploaded | queued | running | done | failed | cancelled
 * @property array{created?: int, duplicates?: list<array{line: int, name: string}>, skipped?: list<array{line: int, reason: string}>, error?: string}|null $summary
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property-read User $user
 * @property-read Vault $vault
 */
#[Fillable(['company_id', 'user_id', 'vault_id', 'name', 'format', 'path', 'key_version', 'size', 'rows', 'header', 'mapping', 'status', 'summary', 'finished_at'])]
class VaultImport extends Model
{
    use BelongsToCompany;

    public const string DISK = 'local';

    protected function casts(): array
    {
        return [
            'format' => VaultImportFormat::class,
            'header' => 'array',
            'mapping' => 'array',
            'summary' => 'array',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Vault, $this> */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'uploaded';
    }
}
