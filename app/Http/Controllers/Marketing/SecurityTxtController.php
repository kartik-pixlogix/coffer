<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Support\Marketing\Seo;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

/**
 * `/.well-known/security.txt` (RFC 9116, Phase 10, D151): where to report a vulnerability.
 * Contact is SECURITY_CONTACT (an email or https URL) or, unset, the contact form's security
 * topic, so it never names a mailbox nobody reads. Expires six months out from each request (the
 * RFC wants it under a year away; the file is generated, so it never goes stale).
 */
final class SecurityTxtController extends Controller
{
    public function __invoke(): Response
    {
        $site = Seo::siteUrl();
        $contact = trim((string) config('security.contact'));

        $lines = [
            'Contact: '.($contact === '' ? $site.'/contact?topic=security' : (str_contains($contact, '@') && ! str_starts_with($contact, 'mailto:') ? 'mailto:'.$contact : $contact)),
            'Expires: '.CarbonImmutable::now()->utc()->addMonths(6)->startOfDay()->format('Y-m-d\TH:i:s\Z'),
            'Preferred-Languages: en',
            'Canonical: '.$site.'/.well-known/security.txt',
            'Policy: '.$site.'/security',
        ];

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
