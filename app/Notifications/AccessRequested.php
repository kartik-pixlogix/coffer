<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AccessRequest;
use App\Models\Company;
use App\Models\User;
use App\Support\Permissions\Catalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Emails a company's Admins when someone asks for a page they can't open. */
final class AccessRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AccessRequest $accessRequest,
        public readonly User $requester,
        public readonly Company $company,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $page = Catalog::PAGES[$this->accessRequest->page]['label'];

        $mail = (new MailMessage)
            ->subject("{$this->requester->name} asked to open {$page}")
            ->line("{$this->requester->name} asked for access to {$page} in {$this->company->name}.");

        if ($this->accessRequest->reason !== null) {
            $mail->line('Their reason: “'.$this->accessRequest->reason.'”');
        }

        return $mail
            ->action('Open roles and permissions', route('settings.roles'))
            ->line('Nothing changes until you update their role or the permission matrix.');
    }
}
