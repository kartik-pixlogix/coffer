<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;

/* /.well-known/security.txt (RFC 9116, D151). */

test('security.txt names the contact form by default, with an expiry under a year away', function () {
    CarbonImmutable::setTestNow('2026-11-24 10:00:00');
    config(['security.contact' => null]);

    $body = $this->get('/.well-known/security.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->getContent();

    expect($body)->toContain('Contact: '.rtrim((string) config('app.url'), '/').'/contact?topic=security')
        ->and($body)->toContain('Expires: 2027-05-24T00:00:00Z')
        ->and($body)->toContain('Policy: ')
        ->and($body)->toContain('Canonical: ');
});

test('security.txt uses SECURITY_CONTACT, as a mailto for an email', function () {
    config(['security.contact' => 'security@unboundsolutions.in']);
    $this->get('/.well-known/security.txt')->assertSee('Contact: mailto:security@unboundsolutions.in');

    config(['security.contact' => 'https://example.com/report']);
    $this->get('/.well-known/security.txt')->assertSee('Contact: https://example.com/report');
});

test('the contact form takes a security report', function () {
    $this->get('/contact?topic=security')->assertOk()
        ->assertInertia(fn ($page) => $page->where('topic', 'security'));
});
