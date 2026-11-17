import { Head } from '@inertiajs/react';
import type { SeoProps } from '@/types';

type Props = {
    seo: SeoProps;
    /** Page title; the app adds " - Coffer". */
    title: string;
    description: string;
    /** og:type; "website" for every page but legal ones ("article"). */
    type?: 'website' | 'article';
};

const SITE_NAME = 'Coffer';

/** JSON inside <script> must not be able to close the tag. */
const safeJson = (value: unknown): string =>
    JSON.stringify(value)
        .replace(/</g, '\\u003c')
        .replace(/>/g, '\\u003e')
        .replace(/&/g, '\\u0026');

/**
 * Title, description, canonical, Open Graph, Twitter card and JSON-LD for a marketing
 * page (spec §21, D83). Written through Inertia's <Head>, so the SSR server puts them in
 * the first HTML response for search engines and link previews.
 */
export function SeoHead({ seo, title, description, type = 'website' }: Props) {
    const fullTitle = `${title} - ${SITE_NAME}`;

    return (
        <Head title={title}>
            <meta
                head-key="description"
                name="description"
                content={description}
            />
            <link head-key="canonical" rel="canonical" href={seo.canonical} />
            <meta head-key="og:type" property="og:type" content={type} />
            <meta
                head-key="og:site_name"
                property="og:site_name"
                content={SITE_NAME}
            />
            <meta head-key="og:title" property="og:title" content={fullTitle} />
            <meta
                head-key="og:description"
                property="og:description"
                content={description}
            />
            <meta head-key="og:url" property="og:url" content={seo.canonical} />
            <meta
                head-key="og:image"
                property="og:image"
                content={seo.ogImage}
            />
            <meta
                head-key="og:image:width"
                property="og:image:width"
                content="1200"
            />
            <meta
                head-key="og:image:height"
                property="og:image:height"
                content="630"
            />
            <meta
                head-key="og:image:alt"
                property="og:image:alt"
                content="Coffer: books, hours, equity and shared logins for every company you run"
            />
            <meta head-key="og:locale" property="og:locale" content="en_IN" />
            <meta
                head-key="twitter:card"
                name="twitter:card"
                content="summary_large_image"
            />
            <meta
                head-key="twitter:title"
                name="twitter:title"
                content={fullTitle}
            />
            <meta
                head-key="twitter:description"
                name="twitter:description"
                content={description}
            />
            <meta
                head-key="twitter:image"
                name="twitter:image"
                content={seo.ogImage}
            />
            <script
                head-key="ld-json"
                type="application/ld+json"
                dangerouslySetInnerHTML={{
                    __html: safeJson(
                        seo.jsonLd.length === 1 ? seo.jsonLd[0] : seo.jsonLd,
                    ),
                }}
            />
        </Head>
    );
}
