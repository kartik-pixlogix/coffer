<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Models\User;
use App\Models\Workspace;

/**
 * A new paying tenant for a sign-up (spec §18): owned by the user, on a 14-day trial
 * with no card. Its first company is made in the create-company wizard.
 */
final class CreateWorkspace
{
    public function handle(User $owner, string $name): Workspace
    {
        return Workspace::query()->create([
            'name' => $name,
            'owner_id' => $owner->id,
            'trial_ends_at' => now()->addDays(Workspace::TRIAL_DAYS),
        ]);
    }
}
