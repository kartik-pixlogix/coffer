<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One event in a company's audit trail (D153): who changed which money record or setting,
 * when, from where, and each field's value before and after. Written only by
 * App\Services\Audit\AuditTrail; append-only here and in the database (LedgerGuard), and
 * hash-chained so `coffer:audit-verify` finds any row changed behind the app's back.
 *
 * @property int $id
 * @property int $company_id
 * @property int $seq
 * @property CarbonImmutable $occurred_at
 * @property int|null $user_id
 * @property string $actor user, system or console
 * @property string|null $impersonator the platform admin acting as the user, when one was
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property string|null $voucher_no
 * @property string $prev_hash
 * @property string $hash
 * @property-read User|null $user
 */
class AuditEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(fn () => throw new LogicException('Audit events are written by AuditTrail only.'));
        static::updating(fn () => throw new LogicException('The audit trail is append-only.'));
        static::deleting(fn () => throw new LogicException('The audit trail is append-only.'));
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'seq' => 'integer',
            'subject_id' => 'integer',
        ];
    }

    /** @return array<string, mixed> field → {from, to} or {changed: true}, or a plain detail (an export's row count) */
    public function changeSet(): array
    {
        // Read raw: Eloquent models already have a $changes property of their own.
        $raw = $this->getAttributes()['changes'] ?? '{}';
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
