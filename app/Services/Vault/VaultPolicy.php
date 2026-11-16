<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Models\Company;

/**
 * A company's vault policies (spec §14.4 "Policies"). Part A reads the defaults in
 * config/vault.php for every company; part B's Policies page stores overrides and
 * changes only `for()` (D69).
 */
final readonly class VaultPolicy
{
    public function __construct(
        public int $reauthMinutes,
        public int $idleLockMinutes,
        public int $clipboardClearSeconds,
        public int $revealSeconds,
    ) {}

    public static function for(?Company $company = null): self
    {
        /** @var array{reauth_minutes: int, idle_lock_minutes: int, clipboard_clear_seconds: int, reveal_seconds: int} $p */
        $p = config('vault.policy');

        return new self(
            reauthMinutes: (int) $p['reauth_minutes'],
            idleLockMinutes: (int) $p['idle_lock_minutes'],
            clipboardClearSeconds: (int) $p['clipboard_clear_seconds'],
            revealSeconds: (int) $p['reveal_seconds'],
        );
    }

    /** @return array{reauthMinutes: int, idleLockMinutes: int, clipboardClearSeconds: int, revealSeconds: int} */
    public function toArray(): array
    {
        return [
            'reauthMinutes' => $this->reauthMinutes,
            'idleLockMinutes' => $this->idleLockMinutes,
            'clipboardClearSeconds' => $this->clipboardClearSeconds,
            'revealSeconds' => $this->revealSeconds,
        ];
    }
}
