<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\VaultPolicySetting;

/**
 * A company's vault policies (spec §14.4 "Policies", VaultLockPolicies.dc.html, D76):
 * re-auth interval, a fresh code for every reveal, idle lock, lock on tab close,
 * clipboard clear and reveal hide times, who can export (and whether a reason is
 * asked), breach checks and the generator's minimum length. Saved per company on the
 * Policies page; the defaults in config/vault.php apply until then.
 */
final readonly class VaultPolicy
{
    /** The choices the Policies page offers (the board's, plus 30 min idle lock). */
    public const array REAUTH = [5, 15, 60, 480];

    public const array IDLE = [5, 15, 30, 60];

    public const array CLIPBOARD = [15, 30, 60, 90];

    public const array REVEAL = [15, 30, 60];

    public const array GENERATOR = [12, 16, 20, 24];

    public const array EXPORT_WHO = ['admins', 'admins_partners', 'none'];

    /** With "a fresh code before every reveal", a code is good for one reveal within this long. */
    public const int FRESH_CODE_SECONDS = 120;

    public function __construct(
        public int $reauthMinutes,
        public int $idleLockMinutes,
        public int $clipboardClearSeconds,
        public int $revealSeconds,
        public bool $breachChecks = true,
        public bool $freshCodeEachReveal = false,
        public bool $lockOnClose = false,
        public string $exportWho = 'admins',
        public bool $exportReason = true,
        public int $generatorMinLength = 12,
    ) {}

    public static function for(?Company $company = null): self
    {
        $saved = $company === null ? null : VaultPolicySetting::query()->withoutGlobalScope('company')->where('company_id', $company->id)->first();

        return $saved === null ? self::defaults() : self::fromSetting($saved);
    }

    public static function defaults(): self
    {
        /** @var array{reauth_minutes: int, idle_lock_minutes: int, clipboard_clear_seconds: int, reveal_seconds: int, breach_checks: bool, fresh_code_each_reveal: bool, lock_on_close: bool, export_who: string, export_reason: bool, generator_min_length: int} $p */
        $p = config('vault.policy');

        return self::fromArray($p);
    }

    /** @param  array{reauth_minutes: int, idle_lock_minutes: int, clipboard_clear_seconds: int, reveal_seconds: int, breach_checks: bool, fresh_code_each_reveal: bool, lock_on_close: bool, export_who: string, export_reason: bool, generator_min_length: int}  $p */
    public static function fromArray(array $p): self
    {
        return new self(
            reauthMinutes: (int) $p['reauth_minutes'],
            idleLockMinutes: (int) $p['idle_lock_minutes'],
            clipboardClearSeconds: (int) $p['clipboard_clear_seconds'],
            revealSeconds: (int) $p['reveal_seconds'],
            breachChecks: (bool) $p['breach_checks'],
            freshCodeEachReveal: (bool) $p['fresh_code_each_reveal'],
            lockOnClose: (bool) $p['lock_on_close'],
            exportWho: in_array($p['export_who'], self::EXPORT_WHO, true) ? $p['export_who'] : 'admins',
            exportReason: (bool) $p['export_reason'],
            generatorMinLength: (int) $p['generator_min_length'],
        );
    }

    private static function fromSetting(VaultPolicySetting $s): self
    {
        return self::fromArray([
            'reauth_minutes' => $s->reauth_minutes,
            'idle_lock_minutes' => $s->idle_lock_minutes,
            'clipboard_clear_seconds' => $s->clipboard_clear_seconds,
            'reveal_seconds' => $s->reveal_seconds,
            'breach_checks' => $s->breach_checks,
            'fresh_code_each_reveal' => $s->fresh_code_each_reveal,
            'lock_on_close' => $s->lock_on_close,
            'export_who' => $s->export_who,
            'export_reason' => $s->export_reason,
            'generator_min_length' => $s->generator_min_length,
        ]);
    }

    /** "Who can export": Admins, Admins and Partners, or no one. */
    public function allowsExport(?CompanyRole $role): bool
    {
        return match ($this->exportWho) {
            'none' => false,
            'admins_partners' => $role === CompanyRole::Admin || $role === CompanyRole::Partner,
            default => $role === CompanyRole::Admin,
        };
    }

    /**
     * What the pages need (the lock card, idle lock, reveal and clipboard timers, the generator).
     *
     * @return array{reauthMinutes: int, idleLockMinutes: int, clipboardClearSeconds: int, revealSeconds: int, freshCodeEachReveal: bool, lockOnClose: bool, generatorMinLength: int}
     */
    public function toArray(): array
    {
        return [
            'reauthMinutes' => $this->reauthMinutes,
            'idleLockMinutes' => $this->idleLockMinutes,
            'clipboardClearSeconds' => $this->clipboardClearSeconds,
            'revealSeconds' => $this->revealSeconds,
            'freshCodeEachReveal' => $this->freshCodeEachReveal,
            'lockOnClose' => $this->lockOnClose,
            'generatorMinLength' => $this->generatorMinLength,
        ];
    }

    /**
     * The Policies form (snake_case, as it is saved).
     *
     * @return array{reauth_minutes: int, idle_lock_minutes: int, fresh_code_each_reveal: bool, lock_on_close: bool, clipboard_clear_seconds: int, reveal_seconds: int, export_who: string, export_reason: bool, breach_checks: bool, generator_min_length: int}
     */
    public function toForm(): array
    {
        return [
            'reauth_minutes' => $this->reauthMinutes,
            'idle_lock_minutes' => $this->idleLockMinutes,
            'fresh_code_each_reveal' => $this->freshCodeEachReveal,
            'lock_on_close' => $this->lockOnClose,
            'clipboard_clear_seconds' => $this->clipboardClearSeconds,
            'reveal_seconds' => $this->revealSeconds,
            'export_who' => $this->exportWho,
            'export_reason' => $this->exportReason,
            'breach_checks' => $this->breachChecks,
            'generator_min_length' => $this->generatorMinLength,
        ];
    }
}
