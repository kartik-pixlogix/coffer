<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vault;

use App\Enums\VaultAuditAction;
use App\Enums\VaultRequestStatus;
use App\Enums\VaultType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultAccessRequest;
use App\Models\VaultItem;
use App\Services\Auth\TwoFactorCodes;
use App\Services\Vault\ItemPresenter;
use App\Services\Vault\VaultAccess;
use App\Services\Vault\VaultAudit;
use App\Services\Vault\VaultHealth;
use App\Services\Vault\VaultPolicy;
use App\Services\Vault\VaultSession;
use App\Services\Vault\VaultSharing;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vault home (Vault.dc.html, MobileVault.dc.html; spec §14): the rail of vaults and smart
 * filters, the item list and the drawer (`?item=`), behind the lock. Items and the drawer
 * are only sent while the vault is unlocked, and never carry a secret value. Also the
 * unlock (2FA code or password, VaultLockPolicies.dc.html), lock and idle heartbeat.
 */
final class VaultController extends Controller
{
    public function index(Request $request, CurrentCompany $current, VaultAccess $access, ItemPresenter $presenter, VaultSession $session, VaultHealth $health, VaultAudit $audit, VaultSharing $sharing): Response
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
            'scope' => $this->scope($request),
            'sharing' => $unlocked ? $this->sharing($user, $access, $sharing) : null,
            'abilities' => [
                'create' => $creatable->isNotEmpty(),
                'newVault' => $twoFactor && $access->isAdmin($user),
                'admin' => $twoFactor && $access->isAdmin($user),
                'audit' => $twoFactor && $access->mayAudit($user),
                'export' => $twoFactor && VaultPolicy::for($company)->allowsExport($current->role()),
            ],
        ]);
    }

    /** Unlock with a 2FA code (also a fresh step-up for reveals) or the account password. */
    public function unlock(Request $request, VaultSession $session, VaultAudit $audit, TwoFactorCodes $codes): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        // V2 (D108): the vault needs 2FA, so unlock and step-up take a 2FA or recovery code;
        // the account password alone never opens it.
        $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ], ['code.required' => 'Enter the 6-digit code or a recovery code.']);

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Turn on two-factor sign-in to use the vault.'], 403);
        }

        $key = 'vault-unlock:'.$user->id;
        $max = (int) config('vault.unlock_attempts');
        $field = 'code';

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            return response()->json(['errors' => [$field => ["Too many tries. Wait {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').' and try again.']]], 429);
        }

        $result = $codes->check($user, $request->string('code')->toString());

        // V3: a code just spent on sign-in is not a wrong code; don't count it.
        if ($result === TwoFactorCodes::REPLAYED) {
            return response()->json(['errors' => [$field => [TwoFactorCodes::REPLAY_MESSAGE]]], 422);
        }

        if ($result !== TwoFactorCodes::VALID) {
            RateLimiter::hit($key, (int) config('vault.unlock_lockout_seconds'));
            $audit->record(VaultAuditAction::FailedUnlock, $user, null, null, ['method' => $field]);
            $left = max(0, $max - RateLimiter::attempts($key));
            $tail = $left > 0 ? " {$left} ".($left === 1 ? 'try' : 'tries').' left before a 5-minute pause.' : ' Wait 5 minutes and try again.';

            return response()->json(['errors' => [$field => ['That code didn’t match.'.$tail]]], 422);
        }

        RateLimiter::clear($key);
        $wasUnlocked = $session->isUnlocked();
        $session->unlock(withCode: true);
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

    /** The rail's starting filter (`?scope=weak`, links from the health page). */
    private function scope(Request $request): ?string
    {
        $scope = $request->query('scope');

        return is_string($scope) && in_array($scope, ['fav', 'shared', 'expiring', 'weak', 'reused', 'breached', 'old', 'no2fa'], true) ? $scope : null;
    }

    /**
     * Sharing on the vault home (D72): vaults the user can share, vaults they can ask for
     * (their role allows more than they have), and the inbox badge for Managers.
     *
     * @return array{shareVaults: list<int>, requestable: list<array{id: int, name: string, level: string|null}>, ceiling: string|null, pending: int|null}
     */
    private function sharing(User $user, VaultAccess $access, VaultSharing $sharing): array
    {
        $ceiling = $access->ceiling($user);
        $vaults = Vault::query()->where('type', VaultType::Shared->value)->orderBy('position')->orderBy('name')->get();
        $manages = $sharing->managesAnything($user);

        $shareVaults = [];
        $requestable = [];

        foreach ($vaults as $vault) {
            if ($sharing->canShareVault($user, $vault)) {
                $shareVaults[] = $vault->id;
            }

            $level = $access->vaultLevel($user, $vault);

            if ($ceiling !== null && ! $access->isAdmin($user) && ($level === null || $level->rank() < $ceiling->rank())) {
                $requestable[] = ['id' => $vault->id, 'name' => $vault->name, 'level' => $level?->value];
            }
        }

        return [
            'shareVaults' => $shareVaults,
            'requestable' => $requestable,
            'ceiling' => $ceiling?->value,
            'pending' => $manages ? VaultAccessRequest::query()->with(['vault', 'item'])->where('status', VaultRequestStatus::Pending->value)->get()
                ->filter(fn (VaultAccessRequest $r) => $sharing->canDecide($user, $r))->count() : null,
        ];
    }
}
