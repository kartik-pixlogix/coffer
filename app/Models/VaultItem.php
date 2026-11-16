<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VaultEnvironment;
use App\Enums\VaultItemType;
use App\Models\Concerns\BelongsToCompany;
use App\Services\Vault\ProviderCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A vault item's metadata (spec §14.1). Secret values are never on this model: they are
 * VaultSecret rows (ciphertext only), so serialising an item can't leak one. Responses
 * are built field by field in App\Services\Vault\ItemPresenter.
 *
 * @property int $id
 * @property int $vault_id
 * @property VaultItemType $type
 * @property string|null $provider
 * @property string $name
 * @property string|null $subtitle
 * @property string|null $username
 * @property string|null $url
 * @property VaultEnvironment|null $environment
 * @property array<string, mixed>|null $fields non-secret template values
 * @property list<array{id: string, label: string, secret: bool, value?: string|null}>|null $custom_fields
 * @property list<string>|null $tags
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $rotated_at
 * @property int|null $strength
 * @property bool $has_totp
 * @property bool $restricted
 * @property bool $breached
 * @property CarbonImmutable|null $breach_checked_at
 * @property int $version
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Vault $vault
 * @property-read Collection<int, VaultSecret> $secrets
 * @property-read Collection<int, VaultAttachment> $attachments
 * @property-read Collection<int, VaultItemVersion> $versions
 * @property-read Collection<int, VaultGrant> $grants
 * @property-read Collection<int, VaultFavourite> $favourites
 * @property-read User|null $creator
 */
#[Fillable([
    'company_id', 'vault_id', 'type', 'provider', 'name', 'subtitle', 'username', 'url', 'environment', 'fields',
    'custom_fields', 'tags', 'expires_at', 'rotated_at', 'strength', 'has_totp', 'restricted', 'version',
    'created_by', 'updated_by',
])]
class VaultItem extends Model
{
    use BelongsToCompany, SoftDeletes;

    /** Relations that hold ciphertext are never serialised. */
    protected $hidden = ['secrets', 'versions'];

    protected $attributes = ['version' => 1, 'has_totp' => false, 'restricted' => false, 'breached' => false];

    protected function casts(): array
    {
        return [
            'type' => VaultItemType::class,
            'environment' => VaultEnvironment::class,
            'fields' => 'array',
            'custom_fields' => 'array',
            'tags' => 'array',
            'expires_at' => 'immutable_date',
            'rotated_at' => 'immutable_datetime',
            'strength' => 'integer',
            'has_totp' => 'boolean',
            'restricted' => 'boolean',
            'breached' => 'boolean',
            'breach_checked_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Vault, $this> */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    /** @return HasMany<VaultSecret, $this> */
    public function secrets(): HasMany
    {
        return $this->hasMany(VaultSecret::class);
    }

    /** @return HasMany<VaultAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(VaultAttachment::class);
    }

    /** @return HasMany<VaultItemVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(VaultItemVersion::class);
    }

    /** @return HasMany<VaultGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(VaultGrant::class);
    }

    /** @return HasMany<VaultFavourite, $this> */
    public function favourites(): HasMany
    {
        return $this->hasMany(VaultFavourite::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "GH": the provider's monogram, else the name's initials. */
    public function monogram(): string
    {
        return ProviderCatalog::monogramFor($this->provider, $this->name);
    }

    /** A non-secret template value. */
    public function field(string $key): ?string
    {
        $value = ($this->fields ?? [])[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
