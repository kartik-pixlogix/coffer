<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Models\Membership;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultGrant;
use App\Models\VaultItem;
use App\Support\CurrentCompany;
use App\Support\Permissions\Access;
use App\Support\Permissions\Catalog;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who reaches which vault item, and at what level (spec §14.2, D68). The one place
 * policies, pages and panels ask; part B's sharing adds grants and requests, not rules.
 *
 * - The role sets a ceiling: Admins Manage everything (except other people's personal
 *   vaults); without the Credentials vault page there is no access; without "Reveal and
 *   copy" the ceiling is View, without "Share and manage" it is Edit.
 * - A grant on the item wins over the vault's grant (it may be stricter or wider), and a
 *   restricted item ("Admins only") ignores vault grants. Ended grants don't count.
 * - A personal vault belongs to its owner alone.
 */
final class VaultAccess
{
    /** @var array<int, array{vaults: array<int, VaultAccessLevel>, items: array<int, VaultAccessLevel>}> */
    private array $grants = [];

    /** @var array<int, VaultAccessLevel|null> */
    private array $caps = [];

    /** @var array<int, CompanyRole|null> */
    private array $roles = [];

    /** @var Collection<int, Membership>|null */
    private ?Collection $memberships = null;

    public function __construct(private readonly CurrentCompany $current, private readonly Access $access) {}

    /** The most the user's role allows in the current company, or null for no vault at all. */
    public function ceiling(User $user): ?VaultAccessLevel
    {
        if (array_key_exists($user->id, $this->caps)) {
            return $this->caps[$user->id];
        }

        $role = $this->roleOf($user);
        $allows = fn (string $permission): bool => $this->isSignedIn($user)
            ? $this->access->allows($user, $permission)
            : $user->checkPermissionTo($permission);

        $cap = match (true) {
            $role === null => null,
            $role === CompanyRole::Admin => VaultAccessLevel::Manage,
            ! $allows(Catalog::pagePermission('vault')) => null,
            ! $allows(Catalog::actionPermission('vault.reveal')) => VaultAccessLevel::View,
            ! $allows(Catalog::actionPermission('vault.share')) => VaultAccessLevel::Edit,
            default => VaultAccessLevel::Manage,
        };

        return $this->caps[$user->id] = $cap;
    }

    public function isAdmin(User $user): bool
    {
        return $this->roleOf($user) === CompanyRole::Admin;
    }

    public function vaultLevel(User $user, Vault $vault): ?VaultAccessLevel
    {
        $cap = $this->ceiling($user);

        if ($cap === null) {
            return null;
        }

        if ($vault->isPersonal()) {
            return $vault->owner_id === $user->id ? $cap : null;
        }

        if ($cap === VaultAccessLevel::Manage && $this->isAdmin($user)) {
            return VaultAccessLevel::Manage;
        }

        $grant = $this->grantsOf($user)['vaults'][$vault->id] ?? null;

        return $grant === null ? null : VaultAccessLevel::lower($cap, $grant);
    }

    public function itemLevel(User $user, VaultItem $item): ?VaultAccessLevel
    {
        $cap = $this->ceiling($user);

        if ($cap === null) {
            return null;
        }

        $vault = $item->vault;

        if ($vault->isPersonal()) {
            return $vault->owner_id === $user->id ? $cap : null;
        }

        if ($this->isAdmin($user)) {
            return VaultAccessLevel::Manage;
        }

        $grants = $this->grantsOf($user);
        $itemGrant = $grants['items'][$item->id] ?? null;

        if ($itemGrant !== null) {
            return VaultAccessLevel::lower($cap, $itemGrant);
        }

        if ($item->restricted) {
            return null;
        }

        $vaultGrant = $grants['vaults'][$item->vault_id] ?? null;

        return $vaultGrant === null ? null : VaultAccessLevel::lower($cap, $vaultGrant);
    }

    public function can(User $user, VaultItem $item, VaultAccessLevel $level): bool
    {
        return $this->itemLevel($user, $item)?->atLeast($level) ?? false;
    }

    /**
     * Every item the user can see in the current company, with its level.
     *
     * @return Collection<int, VaultItem> each with a `level` attribute
     */
    public function visibleItems(User $user): Collection
    {
        if ($this->ceiling($user) === null) {
            return new Collection;
        }

        $items = VaultItem::query()->with('vault')->orderBy('name')->get();

        return $items->filter(function (VaultItem $item) use ($user): bool {
            $level = $this->itemLevel($user, $item);
            $item->setAttribute('level', $level);

            return $level !== null;
        })->values();
    }

    /**
     * Vaults the user can open (a vault grant, a personal vault of theirs, or an item shared in it).
     *
     * @param  Collection<int, VaultItem>|null  $visible
     * @return Collection<int, Vault> each with a `level` attribute (null when only items in it are shared)
     */
    public function visibleVaults(User $user, ?Collection $visible = null): Collection
    {
        if ($this->ceiling($user) === null) {
            return new Collection;
        }

        $visible ??= $this->visibleItems($user);
        $withItems = $visible->pluck('vault_id')->unique()->flip();

        return Vault::query()->orderBy('position')->orderBy('name')->get()
            ->filter(function (Vault $vault) use ($user, $withItems): bool {
                $level = $this->vaultLevel($user, $vault);
                $vault->setAttribute('level', $level);

                return $level !== null || $withItems->has($vault->id);
            })->values();
    }

    /** May add items to this vault: "Create items" plus Edit on the vault. */
    public function canCreateIn(User $user, Vault $vault): bool
    {
        if (! $this->allowsAction($user, 'vault.create')) {
            return false;
        }

        return $this->vaultLevel($user, $vault)?->atLeast(VaultAccessLevel::Edit) ?? false;
    }

    /** @return Collection<int, Vault> */
    public function creatableVaults(User $user): Collection
    {
        return Vault::query()->orderBy('position')->orderBy('name')->get()
            ->filter(fn (Vault $vault) => $this->canCreateIn($user, $vault))->values();
    }

    /**
     * Everyone who can see the item, highest level first: Admins, then grants (spec §14.4
     * "shared-with avatars"). Part B's share drawer edits the grants behind this list.
     *
     * @return list<array{user: User, level: VaultAccessLevel, via: string}>
     */
    public function peopleWithAccess(VaultItem $item): array
    {
        $company = $this->current->require();
        $this->memberships ??= Membership::query()->with('user')->where('company_id', $company->id)->get();
        $memberships = $this->memberships;
        $people = [];

        foreach ($memberships as $membership) {
            $user = $membership->user;
            $level = $this->itemLevel($user, $item);

            if ($level === null) {
                continue;
            }

            $grants = $this->grantsOf($user);
            $via = match (true) {
                $item->vault->isPersonal() => 'owner',
                $membership->role === CompanyRole::Admin => 'admin',
                isset($grants['items'][$item->id]) => 'item',
                default => 'vault',
            };
            $people[] = ['user' => $user, 'level' => $level, 'via' => $via];
        }

        usort($people, fn (array $a, array $b) => [$b['level']->rank(), $a['user']->name] <=> [$a['level']->rank(), $b['user']->name]);

        return $people;
    }

    /** True when the user reaches the item through a grant (the "Shared with me" filter). */
    public function isSharedWith(User $user, VaultItem $item): bool
    {
        $grants = $this->grantsOf($user);

        return $item->created_by !== $user->id
            && (isset($grants['items'][$item->id]) || (! $item->restricted && isset($grants['vaults'][$item->vault_id])));
    }

    /** Forget cached grants and roles (after granting in the same request, between tests). */
    public function flush(): void
    {
        $this->grants = [];
        $this->caps = [];
        $this->memberships = null;
        $this->roles = [];
    }

    /** @return array{vaults: array<int, VaultAccessLevel>, items: array<int, VaultAccessLevel>} */
    private function grantsOf(User $user): array
    {
        if (isset($this->grants[$user->id])) {
            return $this->grants[$user->id];
        }

        $out = ['vaults' => [], 'items' => []];

        foreach (VaultGrant::query()->active()->where('user_id', $user->id)->get() as $grant) {
            if ($grant->vault_item_id !== null) {
                $out['items'][$grant->vault_item_id] = $grant->level;
            } elseif ($grant->vault_id !== null) {
                $out['vaults'][$grant->vault_id] = $grant->level;
            }
        }

        return $this->grants[$user->id] = $out;
    }

    private function allowsAction(User $user, string $action): bool
    {
        $permission = Catalog::actionPermission($action);

        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->isSignedIn($user) ? $this->access->allows($user, $permission) : $user->checkPermissionTo($permission);
    }

    private function roleOf(User $user): ?CompanyRole
    {
        if ($this->isSignedIn($user)) {
            return $this->current->role();
        }

        if (! array_key_exists($user->id, $this->roles)) {
            $this->roles[$user->id] = $this->current->get()?->roleOf($user);
        }

        return $this->roles[$user->id];
    }

    private function isSignedIn(User $user): bool
    {
        $signedIn = auth()->user();

        return $signedIn instanceof User && $signedIn->id === $user->id && $this->current->role() !== null;
    }
}
