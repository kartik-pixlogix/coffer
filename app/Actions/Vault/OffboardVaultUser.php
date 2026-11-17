<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Enums\VaultAccessLevel;
use App\Enums\VaultRequestStatus;
use App\Models\User;
use App\Models\VaultAccessRequest;
use App\Models\VaultGrant;
use App\Models\VaultItem;
use App\Models\VaultOffboarding;
use App\Services\Vault\VaultAccess;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;

/**
 * Offboarding (spec §14.2, D73): when someone is removed from the company, deactivated or
 * loses vault access through a role change, keep "N items they could use → rotate these"
 * — every item they reached at Use or higher just before. Removal also takes their grants
 * away (audited "left") and closes their open requests.
 */
final readonly class OffboardVaultUser
{
    public function __construct(
        private VaultAccess $access,
        private RevokeVaultAccess $revoke,
        private CurrentCompany $current,
    ) {}

    /** @return Collection<int, VaultItem> items the user can Use or more in the current company */
    public function usable(User $user): Collection
    {
        $this->access->flush();

        if ($this->access->ceiling($user) === null) {
            return new Collection;
        }

        return VaultItem::query()->with('vault')->orderBy('name')->get()
            ->filter(fn (VaultItem $item) => $this->access->can($user, $item, VaultAccessLevel::Use))
            ->values();
    }

    /**
     * @param  'removed'|'role'|'deactivated'  $reason
     * @param  Collection<int, VaultItem>  $items  what they could use (before the change)
     */
    public function record(User $user, ?User $by, string $reason, Collection $items): ?VaultOffboarding
    {
        if ($items->isEmpty()) {
            return null;
        }

        $offboarding = VaultOffboarding::query()->create([
            'company_id' => $this->current->require()->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'reason' => $reason,
            'item_ids' => array_values(array_map(fn (VaultItem $i): int => $i->id, $items->all())),
            'created_by' => $by?->id,
        ]);

        activity('vault')->performedOn($offboarding)->causedBy($by)
            ->withProperties(['user_id' => $user->id, 'reason' => $reason, 'items' => $items->count()])
            ->log('Vault offboarding list created');

        return $offboarding;
    }

    /** Removed from the company: their grants go (audited) and their open requests close. */
    public function removeAccess(User $user, ?User $by): void
    {
        foreach (VaultGrant::query()->with(['user', 'vault'])->where('user_id', $user->id)->get() as $grant) {
            $this->revoke->handle($by, $grant, 'left');
        }

        VaultAccessRequest::query()->where('user_id', $user->id)->where('status', VaultRequestStatus::Pending->value)
            ->update(['status' => VaultRequestStatus::Cancelled->value]);

        $this->access->flush();
    }
}
