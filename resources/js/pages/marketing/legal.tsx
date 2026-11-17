import { Link } from '@inertiajs/react';
import { FileWarning } from 'lucide-react';
import { PageHero } from '@/components/marketing/page-sections';
import { SeoHead } from '@/components/marketing/seo-head';
import { AlertBanner } from '@/components/ui/alert-banner';
import { buttonClass } from '@/components/ui/button';
import { LEGAL, LEGAL_ORDER } from '@/lib/marketing/legal';
import type { LegalPageKey, SeoProps } from '@/types';

type Props = { page: LegalPageKey; seo: SeoProps };

/**
 * Terms, Privacy, Cookies and the DPA (spec §21/§23, D84): one template, placeholder copy
 * marked as a draft for counsel, each mentioning the DPDPA.
 */
export default function Legal({ page, seo }: Props) {
    const doc = LEGAL[page];

    return (
        <>
            <SeoHead
                seo={seo}
                title={doc.title}
                description={doc.description}
                type="article"
            />

            <PageHero eyebrow="Legal" title={doc.title} lead={doc.intro}>
                <nav className="lp-legal-nav" aria-label="Legal pages">
                    {LEGAL_ORDER.map((key) => (
                        <Link
                            key={key}
                            href={`/${key}`}
                            aria-current={key === page ? 'page' : undefined}
                            className={buttonClass({
                                variant: key === page ? 'primary' : 'secondary',
                                size: 'sm',
                            })}
                        >
                            {LEGAL[key].title}
                        </Link>
                    ))}
                </nav>
            </PageHero>

            <section className="lp-in lp-pb" aria-label={doc.title}>
                <AlertBanner
                    variant="warn"
                    icon={FileWarning}
                    title="Draft — to be reviewed by counsel"
                    role="status"
                >
                    This is placeholder text, not legal advice and not yet a
                    binding agreement.
                </AlertBanner>
                <article className="lp-prose" style={{ marginTop: 24 }}>
                    {doc.sections.map((section) => (
                        <section
                            key={section.heading}
                            className="lp-stack lp-stack--sm"
                        >
                            <h2>{section.heading}</h2>
                            {section.paragraphs.map((paragraph) => (
                                <p key={paragraph}>{paragraph}</p>
                            ))}
                            {section.list && (
                                <ul>
                                    {section.list.map((item) => (
                                        <li key={item}>{item}</li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    ))}
                    <p className="t-caption t-muted">
                        Questions about this page?{' '}
                        <Link href="/contact?topic=other" className="cf-link">
                            Contact us
                        </Link>
                        .
                    </p>
                </article>
            </section>
        </>
    );
}
