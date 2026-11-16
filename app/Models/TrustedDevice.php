<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser that skips the 2FA code for 30 days (spec §17). The cookie holds the
 * plain token; only its hash is stored.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string $label
 * @property string|null $ip_address
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable $expires_at
 */
#[Fillable(['user_id', 'token_hash', 'label', 'ip_address', 'last_used_at', 'expires_at'])]
#[Hidden(['token_hash'])]
class TrustedDevice extends Model
{
    public const int DAYS = 30;

    public const string COOKIE = 'coffer_trusted_device';

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
