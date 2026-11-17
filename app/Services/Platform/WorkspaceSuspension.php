<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\PlatformAdmin;
use App\Models\Workspace;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Suspend / unsuspend a workspace (spec §22, D86). Suspension blocks every company page of
 * the workspace with a clear "suspended" page; nothing is deleted and the books are never
 * touched. Both are written to the `platform` activity log with the reason.
 */
final class WorkspaceSuspension
{
    public function suspend(Workspace $workspace, PlatformAdmin $admin, string $reason): void
    {
        $reason = trim($reason);

        if (Str::length($reason) < 5) {
            throw new InvalidArgumentException('Give a reason for the suspension.');
        }

        $workspace->forceFill([
            'suspended_at' => now(),
            'suspended_reason' => $reason,
            'suspended_by' => $admin->id,
        ])->save();

        activity('platform')
            ->causedBy($admin)
            ->performedOn($workspace)
            ->withProperties(['reason' => $reason])
            ->log('workspace suspended');
    }

    public function unsuspend(Workspace $workspace, PlatformAdmin $admin, ?string $note = null): void
    {
        $workspace->forceFill([
            'suspended_at' => null,
            'suspended_reason' => null,
            'suspended_by' => null,
        ])->save();

        activity('platform')
            ->causedBy($admin)
            ->performedOn($workspace)
            ->withProperties(array_filter(['note' => $note]))
            ->log('workspace unsuspended');
    }
}
