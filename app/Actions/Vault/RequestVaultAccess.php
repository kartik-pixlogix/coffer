<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Enums\VaultAccessDuration;
use App\Enums\VaultAccessLevel;
use App\Enums\VaultRequestStatus;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultAccessRequest;
use App\Models\VaultItem;
use App\Notifications\Vault\VaultAccessRequested;
use App\Services\Vault\VaultSharing;
use Illuminate\Support\Facades\Notification;

/**
 * Ask for access to a vault or an item, with a reason (spec §14.2). Everyone who could
 * approve it — its Managers and the Admins — is notified.
 */
final readonly class RequestVaultAccess
{
    public function __construct(private VaultSharing $sharing) {}

    public function handle(User $user, Vault|VaultItem $target, VaultAccessLevel $level, VaultAccessDuration $duration, string $reason): VaultAccessRequest
    {
        $request = VaultAccessRequest::query()->create([
            'company_id' => $target->company_id,
            'user_id' => $user->id,
            'vault_id' => $target instanceof Vault ? $target->id : null,
            'vault_item_id' => $target instanceof VaultItem ? $target->id : null,
            'level' => $level,
            'duration' => $duration,
            'reason' => trim($reason),
            'status' => VaultRequestStatus::Pending,
        ]);

        activity('vault')->performedOn($target)->causedBy($user)
            ->withProperties(['level' => $level->value, 'duration' => $duration->value])
            ->log('Vault access requested');

        Notification::send($this->sharing->deciders($target, $user), VaultAccessRequested::for($request));

        return $request;
    }
}
