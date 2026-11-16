<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Company;
use App\Models\Invite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Ask for a new link" on an expired invite: tells the person who sent it. */
final class InviteRenewalRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Invite $invite, public readonly Company $company) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $who = $this->invite->email ?? 'Someone with your share link';

        return (new MailMessage)
            ->subject("{$who} asked for a new invite to {$this->company->name}")
            ->line("{$who} opened an invite to {$this->company->name} that no longer works and asked for a new link.")
            ->action('Open members and invites', route('settings.members'))
            ->line('Nothing is shared with them until you send a new invite.');
    }
}
