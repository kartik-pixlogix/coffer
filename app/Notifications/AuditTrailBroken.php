<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The nightly audit trail check found a row changed or removed outside the app (D153). */
final class AuditTrailBroken extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Company $company, public readonly string $problem) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject("{$this->company->name}: the audit trail was changed")
            ->line("Tonight’s check of {$this->company->name}’s audit trail found a record that was changed or removed outside Coffer.")
            ->line($this->problem)
            ->line('Coffer never edits the audit trail, so this needs looking into. Nothing else has been changed.');
    }
}
