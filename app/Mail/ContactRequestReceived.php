<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ContactRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the team about a Contact / Book a demo request (spec §21, D84). Queued; the
 * payload is the stored request's id, and replies go straight to the person.
 */
final class ContactRequestReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly ContactRequest $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->request->email, $this->request->name)],
            subject: $this->request->topic->label().': '.$this->request->name.($this->request->company ? ' ('.$this->request->company.')' : ''),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.contact-request', with: [
            'lead' => $this->request,
        ]);
    }
}
