<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One encrypted field of an item (password, TOTP key, a secret custom field…). Holds
 * only ciphertext; decrypt through App\Services\Vault\ItemSecrets.
 *
 * @property int $id
 * @property int $company_id
 * @property int $vault_item_id
 * @property string $field
 * @property string $ciphertext
 * @property int $key_version
 * @property string|null $fingerprint
 * @property-read VaultItem $item
 */
#[Fillable(['company_id', 'vault_item_id', 'field', 'ciphertext', 'key_version', 'fingerprint'])]
#[Hidden(['ciphertext', 'fingerprint'])]
class VaultSecret extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['key_version' => 'integer'];
    }

    public static function aad(int $itemId, string $field): string
    {
        return "vault_secret:{$itemId}:{$field}";
    }

    /** @return BelongsTo<VaultItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(VaultItem::class, 'vault_item_id')->withTrashed();
    }
}
