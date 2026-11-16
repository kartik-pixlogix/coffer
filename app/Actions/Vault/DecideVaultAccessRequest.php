<?php

declare(strict_types=1);

namespace App\Actions\Vault;

use App\Enums\VaultRequestStatus;
use App\Models\User;
use App\Models\VaultAccessRequest;
use App\Notifications\Vault\VaultAccessDecided;
use App\Services\Vault\VaultSharing;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Approve (which writes the grant, from now for the asked duration) or deny an access
 * request (spec §14.2). The requester is notified either way.
 */
final readonly class DecideVaultAccessRequest
{
    public function __construct(private GrantVaultAccess $grant, private VaultSharing $sharing) {}

    public function approve(User $by, VaultAccessRequest $request): VaultAccessRequest
    {
        DB::transaction(function () use ($by, $request): void {
            $target = $this->sharing->targetOf($request) ?? throw new LogicException('The request has no target.');
            $this->grant->handle($by, $target, $request->user, $request->level, $request->duration->endsAt(), 'request');
            $this->close($by, $request, VaultRequestStatus::Approved, null);
        });

        $request->user->notify(VaultAccessDecided::for($request->refresh()));

        return $request;
    }

    public function deny(User $by, VaultAccessRequest $request, ?string $note): VaultAccessRequest
    {
        $this->close($by, $request, VaultRequestStatus::Denied, $note);
        $request->user->notify(VaultAccessDecided::for($request->refresh()));

        return $request;
    }

    private function close(User $by, VaultAccessRequest $request, VaultRequestStatus $status, ?string $note): void
    {
        $request->update([
            'status' => $status,
            'decided_by' => $by->id,
            'decided_at' => now(),
            'decision_note' => $note === null || trim($note) === '' ? null : trim($note),
        ]);

        activity('vault')->performedOn($request)->causedBy($by)
            ->withProperties(['status' => $status->value, 'user_id' => $request->user_id])
            ->log('Vault access request '.$status->value);
    }
}
