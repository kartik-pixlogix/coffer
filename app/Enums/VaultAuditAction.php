<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Vault audit events (spec §14.4 "Audit log"). The table is append-only. Part B adds the
 * sharing, access-request and export events through the same writer.
 */
enum VaultAuditAction: string
{
    case Viewed = 'viewed';
    case Revealed = 'revealed';
    case Copied = 'copied';
    case UsedCode = 'used_code';
    case Downloaded = 'downloaded';
    case Created = 'created';
    case Edited = 'edited';
    case Rotated = 'rotated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case Shared = 'shared';
    case Unshared = 'unshared';
    case Exported = 'exported';
    case Unlocked = 'unlocked';
    case FailedUnlock = 'failed_unlock';
    case Locked = 'locked';

    /** Past tense for timelines: "Rohan Desai copied the password". */
    public function verb(): string
    {
        return match ($this) {
            self::Viewed => 'viewed',
            self::Revealed => 'revealed',
            self::Copied => 'copied',
            self::UsedCode => 'used a one-time code for',
            self::Downloaded => 'downloaded',
            self::Created => 'created',
            self::Edited => 'edited',
            self::Rotated => 'rotated',
            self::Deleted => 'deleted',
            self::Restored => 'restored',
            self::Shared => 'shared',
            self::Unshared => 'removed access to',
            self::Exported => 'exported',
            self::Unlocked => 'unlocked the vault',
            self::FailedUnlock => 'failed to unlock the vault',
            self::Locked => 'locked the vault',
        };
    }

    /** The audit page's action label (VaultAudit.dc.html: Viewed, Revealed, Copied…). */
    public function label(): string
    {
        return match ($this) {
            self::UsedCode => 'Used code',
            self::FailedUnlock => 'Failed unlock',
            default => ucfirst($this->value),
        };
    }
}
