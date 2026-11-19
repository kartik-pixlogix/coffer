<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Enums\MarketingPage;
use App\Http\Controllers\Controller;
use App\Support\Marketing\Seo;
use Illuminate\Http\Response;

/**
 * `/sitemap.xml` and `/robots.txt` (spec §21, D83). Robots keeps crawlers out of the app,
 * the sign-in flow and the super-admin panel; the sitemap lists only the public site.
 */
final class SitemapController extends Controller
{
    /** Paths crawlers must stay out of: everything behind sign-in, auth, and /admin. */
    public const array DISALLOW = [
        '/admin', '/horizon', '/health', '/home', '/dashboard', '/companies', '/partners', '/team', '/timesheets',
        '/income', '/expenses', '/reimbursements', '/capital', '/loans', '/settlements',
        '/salary', '/statements', '/reports', '/vault', '/settings', '/account', '/profile',
        '/billing', '/notifications', '/search', '/invites', '/access-requests', '/login', '/logout',
        '/forgot-password', '/reset-password', '/two-factor-challenge',
        '/email', '/user', '/auth', '/session', '/stripe', '/storage', '/balance-sheet',
        '/trial-balance', '/_inertia', '/dev', '/up',
    ];

    public function sitemap(): Response
    {
        $site = Seo::siteUrl();
        $paths = [
            ['/', '1.0', 'weekly'],
            ['/pricing', '0.9', 'weekly'],
            ...array_map(fn (string $slug) => ['/features/'.$slug, '0.8', 'monthly'], MarketingPage::slugs()),
            ['/security', '0.7', 'monthly'],
            ['/contact', '0.6', 'yearly'],
            ...array_map(fn (string $slug) => ['/'.$slug, '0.3', 'yearly'], MarketingPage::legal()),
        ];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($paths as [$path, $priority, $frequency]) {
            $loc = htmlspecialchars($path === '/' ? $site.'/' : $site.$path, ENT_XML1);
            $xml .= "  <url><loc>{$loc}</loc><changefreq>{$frequency}</changefreq><priority>{$priority}</priority></url>\n";
        }

        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $lines = ['User-agent: *', 'Allow: /'];

        foreach (self::DISALLOW as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.Seo::siteUrl().'/sitemap.xml';

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
