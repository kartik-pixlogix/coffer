<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Company;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The invite email (spec §2.3): who invited you, to which company, as what, until when. */
final class CompanyInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Invite $invite,
        public readonly Company $company,
        public readonly User $inviter,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $role = $this->invite->role->withArticle();

        return (new MailMessage)
            ->subject("{$this->inviter->name} invited you to {$this->company->name} on Coffer")
            ->line("{$this->inviter->name} invited you to join {$this->company->name} as {$role}.")
            ->action("Join {$this->company->name}", $this->invite->url())
            ->line('The link works until '.$this->invite->expires_at->format('j M Y').'. If you weren’t expecting it, you can ignore this email.');
    }
}
