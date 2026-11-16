<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyRole;
use App\Enums\InviteKind;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Database\Factories\InviteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * An invitation to join a company (spec §2.3): by email (single use) or share link
 * (anyone with it, until it expires or is revoked).
 *
 * @property int $id
 * @property InviteKind $kind
 * @property string|null $email
 * @property CompanyRole $role
 * @property int|null $partner_id
 * @property int|null $member_id
 * @property int $invited_by
 * @property string $token_hash
 * @property string $token_encrypted
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $declined_at
 * @property int $uses
 * @property CarbonImmutable|null $last_sent_at
 * @property CarbonImmutable|null $created_at
 * @property-read Partner|null $partner
 * @property-read Member|null $member
 * @property-read User $inviter
 */
#[Fillable([
    'company_id', 'kind', 'email', 'role', 'partner_id', 'member_id', 'invited_by',
    'token_hash', 'token_encrypted', 'expires_at', 'last_sent_at',
])]
#[Hidden(['token_hash', 'token_encrypted'])]
class Invite extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<InviteFactory> */
    use HasFactory;

    /** Expiry choices in days (spec §2.3, D26). */
    public const array EXPIRY_DAYS = [1, 3, 7, 14];

    public const int DEFAULT_EXPIRY_DAYS = 7;

    protected function casts(): array
    {
        return [
            'kind' => InviteKind::class,
            'role' => CompanyRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'declined_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'uses' => 'integer',
        ];
    }

    /** A new random token; store its hash and encrypted copy with {@see tokenAttributes()}. */
    public static function newToken(): string
    {
        return Str::random(40);
    }

    /** @return array{token_hash: string, token_encrypted: string} */
    public static function tokenAttributes(string $token): array
    {
        return [
            'token_hash' => self::hashToken($token),
            'token_encrypted' => Crypt::encryptString($token),
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Look an invite up by its plain token, across companies (the accept page has no current company). */
    public static function findByToken(string $token): ?self
    {
        return self::withoutGlobalScope('company')->where('token_hash', self::hashToken($token))->first();
    }

    public function token(): string
    {
        return Crypt::decryptString($this->token_encrypted);
    }

    public function url(): string
    {
        return route('invites.show', $this->token());
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Invites still waiting: not revoked, declined, used (email) or expired.
     *
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->whereNull('declined_at')
            ->where('expires_at', '>', now())
            ->where(fn (Builder $q) => $q->where('kind', InviteKind::Link->value)->orWhereNull('accepted_at'));
    }

    public function isLink(): bool
    {
        return $this->kind === InviteKind::Link;
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && $this->declined_at === null
            && $this->expires_at->isFuture()
            && ($this->isLink() || $this->accepted_at === null);
    }

    /** Why the link no longer works, for the expired state. */
    public function endedAt(): CarbonImmutable
    {
        return $this->revoked_at ?? $this->declined_at ?? $this->accepted_at ?? $this->expires_at;
    }
}
