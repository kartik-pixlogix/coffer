<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A Coffer notification (spec §19, D78): stored on the database channel for the bell and
 * the Notifications page, and mailed with the brand template, each when the user's
 * preference allows. The payload is generic — title, body, url, icon, kind, category,
 * company — plus an optional `subject` the panel uses for inline actions (e.g. approve an
 * expense). Built from names and labels only, never a secret; queued after the commit.
 */
abstract class CofferNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int|null  $companyId  null for workspace-wide events (billing), shown in every company
     * @param  array{type: string, id: int}|null  $subject  the record inline actions work on
     */
    public function __construct(
        public readonly ?int $companyId,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly ?array $subject = null,
    ) {
        $this->afterCommit();
    }

    /** Preference key from NotificationEvents, e.g. "expense.submitted". */
    abstract public function event(): string;

    /** lucide icon name for the panel row. */
    abstract public function icon(): string;

    /** The row's link text (Notifications.dc.html: "Record the transfer", "Open in vault"…). */
    public function linkLabel(): string
    {
        return 'Open';
    }

    /** Icon disc tone: warn, neg, lime or plain. */
    public function tone(): string
    {
        return 'plain';
    }

    /** The email's button. */
    public function mailAction(): string
    {
        return 'Open in Coffer';
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        return array_values(array_filter(
            NotificationEvents::CHANNELS,
            fn (string $channel) => $notifiable->wantsNotification($this->event(), $channel),
        ));
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string, kind: string, category: string, company_id: int|null, tone: string, link: string, subject: array{type: string, id: int}|null}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $this->icon(),
            'kind' => $this->event(),
            'category' => NotificationEvents::category($this->event()),
            'company_id' => $this->companyId,
            'tone' => $this->tone(),
            'link' => $this->linkLabel(),
            'subject' => $this->subject,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mail()
            ->subject($this->title)
            ->greeting($this->title)
            ->line($this->body)
            ->action($this->mailAction(), $this->url);
    }

    /** A brand-template message (resources/views/vendor/mail, D79) for subclasses to extend. */
    protected function mail(): MailMessage
    {
        return (new MailMessage)->theme('coffer');
    }
}
