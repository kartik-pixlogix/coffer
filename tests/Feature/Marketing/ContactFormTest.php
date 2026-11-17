<?php

declare(strict_types=1);

use App\Enums\ContactTopic;
use App\Mail\ContactRequestReceived;
use App\Models\ContactRequest;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Contact / Book a demo (spec §21, D84): validation, the stored lead, queued mail to the
| configured address, the honeypot and the per-IP rate limit.
*/

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    config(['marketing.contact_email' => 'sales@coffer.test']);
});

function contactPayload(array $overrides = []): array
{
    return [
        'topic' => 'demo',
        'name' => 'Asha Rao',
        'email' => 'Asha@AshaRetail.in',
        'company' => 'Asha Retail',
        'phone' => '+91 98765 43210',
        'team_size' => '6-20',
        'message' => 'We run two companies and a JV.',
        'website' => '',
        ...$overrides,
    ];
}

it('stores the request, queues mail to the team and shows the success state', function () {
    $this->post('/contact', contactPayload())
        ->assertRedirect(route('marketing.contact'))
        ->assertSessionHas('contact.sent', ['topic' => 'demo', 'name' => 'Asha']);

    $lead = ContactRequest::query()->sole();
    expect($lead->topic)->toBe(ContactTopic::Demo)
        ->and($lead->email)->toBe('asha@asharetail.in')
        ->and($lead->team_size)->toBe('6-20')
        ->and($lead->ip_address)->toBe('127.0.0.1');

    Mail::assertQueued(ContactRequestReceived::class, fn (ContactRequestReceived $mail) => $mail->hasTo('sales@coffer.test')
        && $mail->hasReplyTo('asha@asharetail.in')
        && $mail->request->is($lead));

    $this->withSession(['contact.sent' => ['topic' => 'demo', 'name' => 'Asha']])
        ->get('/contact')
        ->assertInertia(fn (Assert $page) => $page->where('sent.name', 'Asha'));
});

it('renders the queued mail with the lead', function () {
    $this->post('/contact', contactPayload(['topic' => 'sales']));

    $html = (new ContactRequestReceived(ContactRequest::query()->sole()))->render();

    expect($html)->toContain('Asha Rao')->toContain('Asha Retail')->toContain('We run two companies and a JV.');
});

it('validates the form', function (array $overrides, string $field) {
    $this->from('/contact')->post('/contact', contactPayload($overrides))
        ->assertRedirect('/contact')
        ->assertSessionHasErrors($field);

    expect(ContactRequest::query()->count())->toBe(0);
    Mail::assertNothingQueued();
})->with([
    'no name' => [['name' => ''], 'name'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
    'unknown topic' => [['topic' => 'jobs'], 'topic'],
    'bad phone' => [['phone' => 'call me maybe'], 'phone'],
    'bad team size' => [['team_size' => '7'], 'team_size'],
    'message needed outside a demo' => [['topic' => 'support', 'message' => ''], 'message'],
    'message too long' => [['message' => str_repeat('a', 5001)], 'message'],
]);

it('lets a demo request go without a message', function () {
    $this->post('/contact', contactPayload(['message' => '']))->assertSessionHasNoErrors();

    expect(ContactRequest::query()->sole()->message)->toBeNull();
});

it('thanks a bot that fills the honeypot but stores and sends nothing', function () {
    $this->post('/contact', contactPayload(['website' => 'https://spam.example']))
        ->assertRedirect(route('marketing.contact'))
        ->assertSessionHas('contact.sent');

    expect(ContactRequest::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('rate-limits submissions per IP with a form error', function () {
    config(['marketing.contact_per_hour' => 3]);

    foreach (range(1, 3) as $i) {
        $this->post('/contact', contactPayload(['email' => "lead{$i}@example.com"]))->assertSessionHasNoErrors();
    }

    $this->from('/contact')->post('/contact', contactPayload(['email' => 'lead4@example.com']))
        ->assertRedirect('/contact')
        ->assertSessionHasErrors('form');

    expect(ContactRequest::query()->count())->toBe(3);
    Mail::assertQueuedCount(3);
});
