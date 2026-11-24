/** App\Support\Marketing\Seo: what a marketing page writes into <head> (D83). */
export type SeoProps = {
    /** Absolute URL of this page without a query string. */
    canonical: string;
    siteUrl: string;
    /** Absolute URL of the brand OG image (public/og-image.png). */
    ogImage: string;
    /** schema.org blocks: Organization, plus SoftwareApplication with offers. */
    jsonLd: Record<string, unknown>[];
};

export type MarketingFeature = 'finance' | 'timesheets' | 'vault' | 'reports';

export type LegalPageKey = 'terms' | 'privacy' | 'cookies' | 'dpa';

export type ContactTopicKey =
    | 'demo'
    | 'sales'
    | 'support'
    | 'security'
    | 'other';
