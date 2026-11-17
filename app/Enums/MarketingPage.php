<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The feature-detail pages (spec §21: one template, D83) and the legal pages. Their copy
 * lives with the page components; the slugs here drive the routes and the sitemap.
 */
enum MarketingPage: string
{
    case Finance = 'finance';
    case Timesheets = 'timesheets';
    case Vault = 'vault';
    case Reports = 'reports';

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_map(fn (self $page) => $page->value, self::cases());
    }

    /** @return list<string> Terms, Privacy, Cookies and the DPA (draft copy, D84). */
    public static function legal(): array
    {
        return ['terms', 'privacy', 'cookies', 'dpa'];
    }
}
