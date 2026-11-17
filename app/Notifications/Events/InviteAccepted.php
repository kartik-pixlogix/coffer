<?php

declare(strict_types=1);

namespace App\Notifications\Events;

use App\Models\Invite;
use App\Models\User;
use App\Notifications\CofferNotification;

/** Someone joined from your invite (spec §19 "invite accepted"). */
final class InviteAccepted extends CofferNotification
{
    public static function for(Invite $invite, User $joined): self
    {
        return new self(
            $invite->company_id,
            "{$joined->name} joined {$invite->company->name}",
            'As '.ucfirst($invite->role->value)." · from your invite, signed in as {$joined->email}",
            route('settings.members'),
        );
    }

    public function event(): string
    {
        return 'invite.accepted';
    }

    public function icon(): string
    {
        return 'user-check';
    }

    public function linkLabel(): string
    {
        return 'See members';
    }
}
