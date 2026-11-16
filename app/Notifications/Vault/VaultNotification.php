<?php

declare(strict_types=1);

namespace App\Notifications\Vault;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A vault notification (spec §19 "Vault" events, D72): stored on the database channel for
 * Phase 8's panel and mailed when the user's preference allows. The payload is generic —
 * title, body, url, icon, kind — plus the category and company for the panel's filters.
 * Built from names and labels only: never a secret value, and the queued payload holds
 * nothing but these strings.
 */
abstract class VaultNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $companyId,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
    ) {}

    /** Preference key, e.g. "vault.expiring". */
    abstract public function event(): string;

    /** lucide icon name for the panel row. */
    abstract public function icon(): string;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }

        return array_values(array_filter([
            $notifiable->wantsNotification($this->event(), 'database') ? 'database' : null,
            $notifiable->wantsNotification($this->event(), 'mail') ? 'mail' : null,
        ]));
    }

    /** @return array{title: string, body: string, url: string, icon: string, kind: string, category: string, company_id: int} */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $this->icon(),
            'kind' => $this->event(),
            'category' => 'vault',
            'company_id' => $this->companyId,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->line($this->body)
            ->action('Open in Coffer', $this->url);
    }
}
