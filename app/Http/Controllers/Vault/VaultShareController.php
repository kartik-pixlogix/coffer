<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vault;

use App\Actions\Vault\GrantVaultAccess;
use App\Actions\Vault\RevokeVaultAccess;
use App\Enums\CompanyRole;
use App\Enums\VaultAccessDuration;
use App\Enums\VaultAccessLevel;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultGrant;
use App\Models\VaultItem;
use App\Services\Vault\VaultSharing;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The share drawer (VaultShare.dc.html, spec §14.2, D72): who has access to an item or a
 * vault and why, people to add, and adding, changing or removing grants. JSON for the
 * drawer; Manage on the target (or Admin) for everything, the vault unlocked for changes.
 */
final class VaultShareController extends Controller
{
    public function __construct(private readonly VaultSharing $sharing) {}

    public function showItem(Request $request, VaultItem $item): JsonResponse
    {
        return $this->panel($request, $item);
    }

    public function showVault(Request $request, Vault $vault): JsonResponse
    {
        return $this->panel($request, $vault);
    }

    public function storeItem(Request $request, VaultItem $item, GrantVaultAccess $grant, CurrentCompany $current): JsonResponse
    {
        return $this->store($request, $item, $grant, $current);
    }

    public function storeVault(Request $request, Vault $vault, GrantVaultAccess $grant, CurrentCompany $current): JsonResponse
    {
        return $this->store($request, $vault, $grant, $current);
    }

    /** Change someone's level from the "Who has access" list (the end date stays). */
    public function update(Request $request, VaultGrant $grant, GrantVaultAccess $action): JsonResponse
    {
        $target = $this->targetOf($grant);
        $this->authorizeShare($request, $target);

        $data = $request->validate(['level' => ['required', Rule::enum(VaultAccessLevel::class)]], ['level.required' => 'Pick a level.']);
        $level = VaultAccessLevel::from($data['level']);

        if ($level !== $grant->level) {
            $action->handle($this->user($request), $target, $grant->user, $level, $grant->expires_at, 'change');
        }

        return $this->payload($request, $target, "{$grant->user->name} now has {$level->label()} access");
    }

    public function destroy(Request $request, VaultGrant $grant, RevokeVaultAccess $revoke): JsonResponse
    {
        $target = $this->targetOf($grant);
        $this->authorizeShare($request, $target);

        $name = $grant->user->name;
        $revoke->handle($this->user($request), $grant);

        return $this->payload($request, $target, "{$name} no longer has access");
    }

    private function panel(Request $request, Vault|VaultItem $target): JsonResponse
    {
        $this->authorizeShare($request, $target);

        return $this->payload($request, $target);
    }

    private function store(Request $request, Vault|VaultItem $target, GrantVaultAccess $grant, CurrentCompany $current): JsonResponse
    {
        $this->authorizeShare($request, $target);

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:20'],
            'user_ids.*' => ['integer', 'distinct'],
            'level' => ['required', Rule::enum(VaultAccessLevel::class)],
            'ends' => ['required', Rule::enum(VaultAccessDuration::class)],
        ], [
            'user_ids.required' => 'Pick someone to share with.',
            'level.required' => 'Pick a level.',
        ]);

        $level = VaultAccessLevel::from($data['level']);
        $endsAt = VaultAccessDuration::from($data['ends'])->endsAt();
        $people = [];

        foreach ($data['user_ids'] as $id) {
            $user = $this->sharing->isMember((int) $id);

            if ($user === null) {
                throw ValidationException::withMessages(['user_ids' => 'Pick people from this company.']);
            }

            if ($current->require()->roleOf($user) === CompanyRole::Admin) {
                throw ValidationException::withMessages(['user_ids' => "{$user->name} is an Admin and already has full access."]);
            }

            $people[] = $user;
        }

        foreach ($people as $user) {
            $grant->handle($this->user($request), $target, $user, $level, $endsAt);
        }

        $names = count($people) === 1 ? $people[0]->name : count($people).' people';

        return $this->payload($request, $target, "Shared with {$names}");
    }

    private function payload(Request $request, Vault|VaultItem $target, ?string $message = null): JsonResponse
    {
        $this->sharing->flush();
        $viewer = $this->user($request);
        $vault = $target instanceof VaultItem ? $target->vault : $target;

        return response()->json([
            'target' => [
                'kind' => $target instanceof VaultItem ? 'item' : 'vault',
                'id' => $target->id,
                'name' => $target->name,
                'vault' => $vault->name,
                'monogram' => $target instanceof VaultItem ? $target->monogram() : mb_strtoupper(mb_substr($vault->name, 0, 2)),
                'restricted' => $target instanceof VaultItem && $target->restricted,
            ],
            'access' => $this->sharing->accessList($target, $viewer),
            'candidates' => $this->sharing->candidates($target),
            'message' => $message,
        ])->header('Cache-Control', 'no-store');
    }

    private function authorizeShare(Request $request, Vault|VaultItem $target): void
    {
        abort_unless($this->sharing->canShare($this->user($request), $target), 403);
    }

    private function targetOf(VaultGrant $grant): Vault|VaultItem
    {
        $target = $grant->vault_item_id !== null ? $grant->item : $grant->vault;
        abort_if($target === null, 404);

        return $target;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        \assert($user instanceof User);

        return $user;
    }
}
