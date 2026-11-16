<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file on an item (keys, certificates, recovery files; spec §14.1). The bytes on the
 * private disk are AES-256-GCM ciphertext; the name, type and size are metadata.
 *
 * @property int $id
 * @property int $company_id
 * @property int $vault_item_id
 * @property string $name
 * @property string|null $mime
 * @property int $size
 * @property string $path
 * @property int $key_version
 * @property int|null $uploaded_by
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['company_id', 'vault_item_id', 'name', 'mime', 'size', 'path', 'key_version', 'uploaded_by'])]
#[Hidden(['path'])]
class VaultAttachment extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['size' => 'integer', 'key_version' => 'integer'];
    }

    public static function aad(int $id): string
    {
        return "vault_attachment:{$id}";
    }

    /** @return BelongsTo<VaultItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(VaultItem::class, 'vault_item_id')->withTrashed();
    }
}
