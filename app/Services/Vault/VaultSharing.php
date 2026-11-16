<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Enums\CompanyRole;
use App\Enums\VaultAccessLevel;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Partner;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultAccessRequest;
use App\Models\VaultGrant;
use App\Models\VaultItem;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;

/**
 * The share drawer and the requests inbox (VaultShare.dc.html, spec §14.2, D72): who may
 * share a vault or an item, who has access and why (role, vault grant or item grant), and
 * who can be added. Levels themselves always come from VaultAccess.
 */
final class VaultSharing
{
    /** @var Collection<int, Membership>|null */
    private ?Collection $memberships = null;

    /** @var array<int, array{colour: int|null, about: string|null}>|null */
    private ?array $directory = null;

    public function __construct(private readonly VaultAccess $access, private readonly CurrentCompany $current) {}

    /** Manage on the item, outside a personal vault (a personal vault is its owner's alone). */
    public function canShareItem(User $user, VaultItem $item): bool
    {
        return $user->hasTwoFactorEnabled()
            && ! $item->vault->isPersonal()
            && $this->access->can($user, $item, VaultAccessLevel::Manage);
    }

    public function canShareVault(User $user, Vault $vault): bool
    {
        return $user->hasTwoFactorEnabled()
            && ! $vault->isPersonal()
            && ($this->access->vaultLevel($user, $vault)?->atLeast(VaultAccessLevel::Manage) ?? false);
    }

    public function canShare(User $user, Vault|VaultItem $target): bool
    {
        return $target instanceof VaultItem ? $this->canShareItem($user, $target) : $this->canShareVault($user, $target);
    }

    /** Approve or deny: a Manager of what was asked for, or an Admin. */
    public function canDecide(User $user, VaultAccessRequest $request): bool
    {
        $target = $this->targetOf($request);

        return $target !== null && $request->user_id !== $user->id && $this->canShare($user, $target);
    }

    public function targetOf(VaultAccessRequest $request): Vault|VaultItem|null
    {
        return $request->vault_item_id !== null ? $request->item : $request->vault;
    }

    /**
     * Anyone who can approve a request for this target (for the "asked for access" notification).
     *
     * @return Collection<int, User>
     */
    public function deciders(Vault|VaultItem $target, ?User $except = null): Collection
    {
        return $this->memberships()
            ->map(fn (Membership $m) => $m->user)
            ->filter(fn (User $user) => $user->id !== $except?->id && $this->canShare($user, $target))
            ->values();
    }

    /** Does anything in the company let this user approve requests (the inbox badge)? */
    public function managesAnything(User $user): bool
    {
        if ($this->access->isAdmin($user)) {
            return true;
        }

        if ($this->access->ceiling($user) !== VaultAccessLevel::Manage) {
            return false;
        }

        return VaultGrant::query()->active()->where('user_id', $user->id)->where('level', VaultAccessLevel::Manage->value)->exists();
    }

    /**
     * "Who has access" (VaultShare.dc.html): everyone who reaches the target, highest
     * level first, with why. On an item, vault-grant rows are read-only (change them on
     * the vault); item grants and, on a vault, vault grants can be changed or removed.
     *
     * @return list<array{userId: int, name: string, you: bool, colour: int|null, about: string|null, level: string, granted: string|null, limited: bool, via: string, grantId: int|null, endsAt: string|null, editable: bool}>
     */
    public function accessList(Vault|VaultItem $target, User $viewer): array
    {
        $item = $target instanceof VaultItem ? $target : null;
        $vault = $item === null ? $target : $item->vault;

        $itemGrants = $item === null ? new Collection : VaultGrant::query()->active()->where('vault_item_id', $item->id)->get()->keyBy('user_id');
        $vaultGrants = VaultGrant::query()->active()->where('vault_id', $vault->id)->get()->keyBy('user_id');
        $rows = [];

        foreach ($this->memberships() as $membership) {
            $user = $membership->user;
            $level = $item !== null ? $this->access->itemLevel($user, $item) : $this->access->vaultLevel($user, $vault);

            if ($level === null) {
                continue;
            }

            /** @var VaultGrant|null $grant */
            $grant = null;
            $via = 'vault';

            if ($vault->isPersonal()) {
                $via = 'owner';
            } elseif ($membership->role === CompanyRole::Admin) {
                $via = 'admin';
            } elseif ($item !== null && $itemGrants->has($user->id)) {
                $via = 'item';
                $grant = $itemGrants->get($user->id);
            } else {
                $grant = $vaultGrants->get($user->id);
            }

            $editable = $grant !== null && ($via === 'item' || $item === null);

            $rows[] = [
                'userId' => $user->id,
                'name' => $user->name,
                'you' => $user->id === $viewer->id,
                ...$this->about($user, $membership->role),
                'level' => $level->value,
                'granted' => $grant?->level->value,
                'limited' => $grant !== null && $grant->level->rank() > $level->rank(),
                'via' => $via,
                'grantId' => $editable ? $grant->id : null,
                'endsAt' => $grant?->expires_at?->toIso8601String(),
                'editable' => $editable,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['via'] === 'admin' || $b['via'] === 'owner', VaultAccessLevel::from($b['level'])->rank(), $a['name']]
            <=> [$a['via'] === 'admin' || $a['via'] === 'owner', VaultAccessLevel::from($a['level'])->rank(), $b['name']]);

        return $rows;
    }

    /**
     * People who can be added: members of the company with the vault page, not Admins
     * (they already have everything) and without a grant on this target yet.
     *
     * @return list<array{userId: int, name: string, colour: int|null, about: string|null, ceiling: string, twoFactor: bool}>
     */
    public function candidates(Vault|VaultItem $target): array
    {
        $column = $target instanceof VaultItem ? 'vault_item_id' : 'vault_id';
        $granted = VaultGrant::query()->active()->where($column, $target->id)->pluck('user_id')->flip();
        $out = [];

        foreach ($this->memberships() as $membership) {
            $user = $membership->user;

            if ($membership->role === CompanyRole::Admin || $granted->has($user->id)) {
                continue;
            }

            $ceiling = $this->access->ceiling($user);

            if ($ceiling === null) {
                continue;
            }

            $out[] = [
                'userId' => $user->id,
                'name' => $user->name,
                ...$this->about($user, $membership->role),
                'ceiling' => $ceiling->value,
                'twoFactor' => $user->hasTwoFactorEnabled(),
            ];
        }

        usort($out, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** Is this user a member of the current company? */
    public function isMember(int $userId): ?User
    {
        return $this->memberships()->firstWhere('user_id', $userId)?->user;
    }

    /** Forget cached people (after a membership change in the same request). */
    public function flush(): void
    {
        $this->memberships = null;
        $this->directory = null;
        $this->access->flush();
    }

    /** @return Collection<int, Membership> */
    private function memberships(): Collection
    {
        return $this->memberships ??= Membership::query()->with('user')
            ->where('company_id', $this->current->require()->id)
            ->get();
    }

    /**
     * Partner colour and a short "Partner · Vertex Labs" / "Sr developer · team member" line.
     *
     * @return array{colour: int|null, about: string|null}
     */
    private function about(User $user, CompanyRole $role): array
    {
        if ($this->directory === null) {
            $this->directory = [];

            foreach (Partner::query()->with('side:id,name')->whereNotNull('user_id')->get() as $partner) {
                $this->directory[(int) $partner->user_id] = [
                    'colour' => $partner->colour,
                    'about' => 'Partner'.($partner->side !== null ? ' · '.$partner->side->name : ''),
                ];
            }

            foreach (Member::query()->with('partner:id,colour')->whereNotNull('user_id')->get() as $member) {
                $this->directory[(int) $member->user_id] ??= [
                    'colour' => $member->partner->colour,
                    'about' => ($member->designation ?: 'Team member').($member->designation ? ' · team member' : ''),
                ];
            }
        }

        $known = $this->directory[$user->id] ?? null;

        return [
            'colour' => $known['colour'] ?? null,
            'about' => $role === CompanyRole::Admin ? 'Admin' : ($known['about'] ?? $role->label()),
        ];
    }
}
