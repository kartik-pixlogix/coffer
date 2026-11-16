<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;

/**
 * The vault's lock and step-up state, kept in the session (spec §14.3, D69):
 *
 * - **Unlocked**: the user unlocked the vault (2FA code or password) and has used it within
 *   the idle-lock time. A new session starts locked; each vault request slides the timer.
 * - **Verified**: a 2FA code was entered within "Ask for a 2FA code again after" (15 min).
 *   Reveal, copy, one-time codes and downloads need both.
 */
final readonly class VaultSession
{
    private const string UNLOCKED = 'vault.unlocked_at';

    private const string ACTIVE = 'vault.active_at';

    private const string VERIFIED = 'vault.verified_at';

    public function __construct(private Session $session, private CurrentCompany $current) {}

    public function policy(): VaultPolicy
    {
        return VaultPolicy::for($this->current->get());
    }

    public function isUnlocked(): bool
    {
        $lockAt = $this->lockAt();

        return $lockAt !== null && $lockAt->isFuture();
    }

    /** When the vault locks itself if nothing else happens. */
    public function lockAt(): ?CarbonImmutable
    {
        $unlocked = $this->time(self::UNLOCKED);
        $active = $this->time(self::ACTIVE);

        return $unlocked === null || $active === null ? null : $active->addMinutes($this->policy()->idleLockMinutes);
    }

    public function isVerified(): bool
    {
        $until = $this->verifiedUntil();

        return $until !== null && $until->isFuture() && $this->isUnlocked();
    }

    /** Until when reveals go through without asking for a code again. */
    public function verifiedUntil(): ?CarbonImmutable
    {
        return $this->time(self::VERIFIED)?->addMinutes($this->policy()->reauthMinutes);
    }

    /** Unlock: a 2FA code also counts as a fresh step-up; a password doesn't. */
    public function unlock(bool $withCode): void
    {
        $now = now()->getTimestamp();
        $this->session->put(self::UNLOCKED, $now);
        $this->session->put(self::ACTIVE, $now);

        if ($withCode) {
            $this->session->put(self::VERIFIED, $now);
        }
    }

    /** Slide the idle timer (any vault request while unlocked). */
    public function touch(): void
    {
        if ($this->isUnlocked()) {
            $this->session->put(self::ACTIVE, now()->getTimestamp());
        }
    }

    public function lock(): void
    {
        $this->session->forget([self::UNLOCKED, self::ACTIVE, self::VERIFIED]);
    }

    /** @return array{unlocked: bool, verifiedUntil: string|null, lockAt: string|null} */
    public function state(): array
    {
        $unlocked = $this->isUnlocked();

        return [
            'unlocked' => $unlocked,
            'verifiedUntil' => $unlocked && $this->isVerified() ? $this->verifiedUntil()?->toIso8601String() : null,
            'lockAt' => $unlocked ? $this->lockAt()?->toIso8601String() : null,
        ];
    }

    private function time(string $key): ?CarbonImmutable
    {
        $value = $this->session->get($key);

        return is_int($value) ? CarbonImmutable::createFromTimestamp($value, (string) config('app.timezone')) : null;
    }
}
