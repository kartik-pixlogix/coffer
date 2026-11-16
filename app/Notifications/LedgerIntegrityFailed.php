<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The nightly ledger check found a broken invariant (spec §4.5): tell the company's Admins. */
final class LedgerIntegrityFailed extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $failures */
    public function __construct(public readonly Company $company, public readonly array $failures) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->error()
            ->subject("{$this->company->name}: the books don’t balance")
            ->line("Tonight’s check of {$this->company->name}’s books found a problem. Nothing has been changed, and we’re looking into it.");

        foreach (array_slice($this->failures, 0, 10) as $failure) {
            $mail->line('• '.$failure);
        }

        return $mail->line('Reports may show wrong figures until this is fixed.');
    }
}
