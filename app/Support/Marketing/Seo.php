<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * What every marketing page puts in its <head> (spec §21, D83): the canonical URL (no
 * query string), the site URL for absolute OG images, and the JSON-LD blocks. The page
 * component writes them through Inertia's <Head>, so they render server-side.
 */
final class Seo
{
    /**
     * @param  Collection<int, Plan>|null  $plans  adds SoftwareApplication with offers
     * @return array{canonical: string, siteUrl: string, ogImage: string, jsonLd: list<array<string, mixed>>}
     */
    public static function for(Request $request, ?Collection $plans = null): array
    {
        $site = self::siteUrl();
        $path = trim($request->path(), '/');

        $jsonLd = [self::organization($site)];

        if ($plans !== null) {
            $jsonLd[] = self::application($site, $plans);
        }

        return [
            'canonical' => $path === '' ? $site.'/' : $site.'/'.$path,
            'siteUrl' => $site,
            'ogImage' => $site.'/og-image.png',
            'jsonLd' => $jsonLd,
        ];
    }

    public static function siteUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** @return array<string, mixed> */
    private static function organization(string $site): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => (string) config('marketing.organization.name'),
            'url' => $site.'/',
            'logo' => $site.'/brand/coffer-monogram.svg',
            'email' => (string) config('marketing.organization.email'),
        ];
    }

    /**
     * SoftwareApplication with one Offer per priced plan (monthly, INR), read from the
     * plans table so it never disagrees with the Pricing page.
     *
     * @param  Collection<int, Plan>  $plans
     * @return array<string, mixed>
     */
    private static function application(string $site, Collection $plans): array
    {
        $offers = $plans
            ->filter(fn (Plan $plan) => ! $plan->custom_pricing && $plan->price('monthly', 'inr') !== null)
            ->map(fn (Plan $plan) => [
                '@type' => 'Offer',
                'name' => $plan->name,
                'price' => sprintf('%d.%02d', intdiv((int) $plan->price('monthly', 'inr'), 100), ((int) $plan->price('monthly', 'inr')) % 100),
                'priceCurrency' => 'INR',
                'url' => $site.'/pricing',
                'category' => 'subscription',
            ])
            ->values()
            ->all();

        return [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => (string) config('marketing.organization.name'),
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'url' => $site.'/',
            'description' => 'Books, timesheets, partner equity, JV settlements and an encrypted credentials vault for every company you run.',
            'offers' => $offers,
        ];
    }
}
