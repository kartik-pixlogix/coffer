<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vault;

use App\Enums\VaultAuditAction;
use App\Enums\VaultType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultItem;
use App\Services\Vault\ItemPresenter;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultAudit;
use App\Services\Vault\VaultHealth;
use App\Services\Vault\VaultSession;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Vault home (Vault.dc.html, MobileVault.dc.html; spec §14): the rail of vaults and smart
 * filters, the item list and the drawer (`?item=`), behind the lock. Items and the drawer
 * are only sent while the vault is unlocked, and never carry a secret value. Also the
 * unlock (2FA code or password, VaultLockPolicies.dc.html), lock and idle heartbeat.
 */
final class VaultController extends Controller
{
    public function index(Request $request, CurrentCompany $current, VaultAccess $access, ItemPresenter $presenter, VaultSession $session, VaultHealth $health, VaultAudit $audit): Response
    {
        /** @var User $user */
        $user = $request->user();
        $company = $current->require();
        $twoFactor = $user->hasTwoFactorEnabled();
        $unlocked = $twoFactor && $session->isUnlocked();

        if ($unlocked) {
            $session->touch();
        }

        $visible = $twoFactor ? $access->visibleItems($user) : new Collection;
        $summary = $unlocked ? $health->summarise($visible) : null;
        $creatable = $twoFactor ? $access->creatableVaults($user)->pluck('id')->flip() : collect();

        $vaults = $access->visibleVaults($user, $visible)->map(fn (Vault $vault) => [
            'id' => $vault->id,
            'name' => $vault->name,
            'personal' => $vault->type === VaultType::Personal,
            'count' => $visible->where('vault_id', $vault->id)->count(),
            'canAdd' => $creatable->has($vault->id),
        ])->values();

        $selectedId = $request->integer('item') ?: null;

        return Inertia::render('vault/index', [
            'company' => ['name' => $company->name],
            'vaultState' => [
                'twoFactor' => $twoFactor,
                ...$session->state(),
                'policy' => $session->policy()->toArray(),
            ],
            'vaults' => $vaults,
            'items' => $unlocked ? $presenter->rows($user, $visible, $summary['reused'] ?? []) : [],
            'health' => $summary === null ? null : [
                'score' => $summary['score'],
                'grade' => $summary['grade'],
                'counts' => $summary['counts'],
            ],
            'selectedId' => $selectedId,
            'selected' => function () use ($unlocked, $selectedId, $visible, $presenter, $user, $audit) {
                $item = $unlocked && $selectedId !== null ? $visible->firstWhere('id', $selectedId) : null;

                if (! $item instanceof VaultItem) {
                    return null;
                }

                $audit->record(VaultAuditAction::Viewed, $user, $item);

                return $presenter->detail($user, $item);
            },
            'openShare' => $request->boolean('share'),
            'abilities' => [
                'create' => $creatable->isNotEmpty(),
                'newVault' => $twoFactor && $access->isAdmin($user),
            ],
        ]);
    }

    /** Unlock with a 2FA code (also a fresh step-up for reveals) or the account password. */
    public function unlock(Request $request, VaultSession $session, VaultAudit $audit, TwoFactorAuthenticationProvider $provider): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate([
            'code' => ['required_without:password', 'nullable', 'string', 'max:32'],
            'password' => ['required_without:code', 'nullable', 'string', 'max:255'],
        ], ['code.required_without' => 'Enter the 6-digit code.', 'password.required_without' => 'Enter your password.']);

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Turn on two-factor sign-in to use the vault.'], 403);
        }

        $key = 'vault-unlock:'.$user->id;
        $max = (int) config('vault.unlock_attempts');
        $field = $request->filled('code') ? 'code' : 'password';

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            return response()->json(['errors' => [$field => ["Too many tries. Wait {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').' and try again.']]], 429);
        }

        $ok = $field === 'code'
            ? $this->codeMatches($user, $request->string('code')->toString(), $provider)
            : Hash::check($request->string('password')->toString(), $user->password);

        if (! $ok) {
            RateLimiter::hit($key, (int) config('vault.unlock_lockout_seconds'));
            $audit->record(VaultAuditAction::FailedUnlock, $user, null, null, ['method' => $field]);
            $left = max(0, $max - RateLimiter::attempts($key));
            $what = $field === 'code' ? 'That code didn’t match.' : 'That password didn’t work.';
            $tail = $left > 0 ? " {$left} ".($left === 1 ? 'try' : 'tries').' left before a 5-minute pause.' : ' Wait 5 minutes and try again.';

            return response()->json(['errors' => [$field => [$what.$tail]]], 422);
        }

        RateLimiter::clear($key);
        $wasUnlocked = $session->isUnlocked();
        $session->unlock(withCode: $field === 'code');
        $audit->record(VaultAuditAction::Unlocked, $user, null, null, ['method' => $field, 'step_up' => $wasUnlocked]);

        return response()->json(['ok' => true, 'state' => $session->state()]);
    }

    public function lock(Request $request, VaultSession $session, VaultAudit $audit): JsonResponse|RedirectResponse
    {
        if ($session->isUnlocked()) {
            $audit->record(VaultAuditAction::Locked, $request->user() instanceof User ? $request->user() : null);
        }

        $session->lock();

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['ok' => true, 'state' => $session->state()])
            : back();
    }

    /** The idle heartbeat while someone works in the vault without making requests. */
    public function touch(VaultSession $session): JsonResponse
    {
        $session->touch();

        return response()->json(['state' => $session->state()]);
    }

    /** A new shared vault (Admins; the board has no spot, so it's at the foot of the rail's vault list, D68). */
    public function storeVault(Request $request, CurrentCompany $current, VaultAccess $access): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($access->isAdmin($user), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('vaults', 'name')->where('company_id', $current->require()->id)],
        ], ['name.unique' => 'There’s already a vault with that name.', 'name.required' => 'Name the vault.']);

        $vault = Vault::query()->create([
            'name' => trim($data['name']),
            'type' => VaultType::Shared,
            'position' => (int) Vault::query()->max('position') + 1,
            'created_by' => $user->id,
        ]);

        activity('vault')->performedOn($vault)->causedBy($user)->withProperties(['name' => $vault->name])->log('Vault created');
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$vault->name} vault created"]);

        return back();
    }

    private function codeMatches(User $user, string $code, TwoFactorAuthenticationProvider $provider): bool
    {
        $code = trim(str_replace(' ', '', $code));

        if ($code === '') {
            return false;
        }

        if (preg_match('/^\d{6}$/', $code) === 1 && $provider->verify((string) decrypt((string) $user->two_factor_secret), $code)) {
            return true;
        }

        if (in_array($code, $user->recoveryCodes(), true)) {
            $user->replaceRecoveryCode($code);

            return true;
        }

        return false;
    }
}
