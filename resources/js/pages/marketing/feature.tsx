import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { CheckList } from '@/components/marketing/marketing-parts';
import { CtaBand, PageHero } from '@/components/marketing/page-sections';
import { SeoHead } from '@/components/marketing/seo-head';
import { Reveal, RevealGroup, RevealItem } from '@/components/marketing/motion';
import { ButtonLink } from '@/components/ui/button';
import { FEATURE_ORDER, FEATURES } from '@/lib/marketing/features';
import type { FeatureSection, PreviewRow } from '@/lib/marketing/features';
import { cn } from '@/lib/utils';
import type { MarketingFeature, SeoProps } from '@/types';

type Props = { feature: MarketingFeature; seo: SeoProps };

const PILL: Record<NonNullable<PreviewRow['pill']>['tone'], string> = {
    pos: 'cf-pill--pos',
    warn: 'cf-pill--warn',
    neg: 'cf-pill--neg',
    lime: 'cf-pill--lime',
    graphite: 'cf-pill--graphite',
};

function Preview({ preview }: { preview: FeatureSection['preview'] }) {
    return (
        <div className="lp-panel-demo" aria-hidden="true">
            <span className="t-caption t-muted">{preview.caption}</span>
            {preview.headline && (
                <div className="cf-card cf-card--graphite">
                    <span className="t-caption lp-on-dark-muted">
                        {preview.headline.label}
                    </span>
                    <div className="t-money lp-figure-lime lp-figure-lime--lg">
                        {preview.headline.value}
                    </div>
                    {preview.headline.sub && (
                        <span className="t-caption lp-on-dark-muted">
                            {preview.headline.sub}
                        </span>
                    )}
                </div>
            )}
            {preview.rows.map((row) => (
                <div
                    key={row.label}
                    className="cf-card cf-kv lp-prev-row"
                    style={{ alignItems: 'center', gap: 12 }}
                >
                    <span className="cf-stack">
                        <span className="t-label-strong">{row.label}</span>
                        {row.sub && (
                            <span className="t-caption t-muted">{row.sub}</span>
                        )}
                    </span>
                    <span className="cf-row lp-prev-val" style={{ gap: 8 }}>
                        <span className="t-money-sm">{row.value}</span>
                        {row.pill && (
                            <span
                                className={cn(
                                    'cf-pill cf-pill--sm',
                                    PILL[row.pill.tone],
                                )}
                            >
                                {row.pill.text}
                            </span>
                        )}
                    </span>
                </div>
            ))}
        </div>
    );
}

/**
 * The feature-detail template (spec §21, DESIGN_MAP "use Landing sections", D83): one
 * component for Finance, Timesheets, Vault and Reports, filled from lib/marketing/features.
 */
export default function Feature({ feature, seo }: Props) {
    const content = FEATURES[feature];
    const others = FEATURE_ORDER.filter((key) => key !== feature);

    return (
        <>
            <SeoHead
                seo={seo}
                title={content.name}
                description={content.description}
            />

            <PageHero
                eyebrow={content.eyebrow}
                title={content.title}
                titleFaint={content.titleFaint}
                lead={content.lead}
                centered
            >
                <div className="lp-cta-row">
                    <ButtonLink
                        href="/register"
                        variant="primary"
                        size="lg"
                        disc={ArrowRight}
                    >
                        Start free trial
                    </ButtonLink>
                    <ButtonLink
                        href="/contact?topic=demo"
                        variant="secondary"
                        size="lg"
                    >
                        Book a demo
                    </ButtonLink>
                </div>
            </PageHero>

            <div className="lp-in lp-stack" style={{ gap: 24 }}>
                {content.sections.map((section, index) => (
                    <Reveal
                        as="section"
                        key={section.title}
                        className={cn(
                            'lp-detail',
                            index % 2 === 1 && 'lp-detail--flip',
                        )}
                        aria-labelledby={`sec-${index}`}
                        distance={40}
                    >
                        <div className="lp-panel-copy">
                            <h2
                                id={`sec-${index}`}
                                className="lp-h3 lp-panel-title"
                            >
                                {section.title}
                            </h2>
                            <p className="t-body t-muted lp-body-lg">
                                {section.body}
                            </p>
                            <CheckList items={section.points} />
                        </div>
                        <Preview preview={section.preview} />
                    </Reveal>
                ))}
            </div>

            <section className="lp-sec" aria-labelledby="extras-h">
                <div className="lp-in">
                    <Reveal>
                        <h2
                            id="extras-h"
                            className="lp-h2 lp-h2--sm"
                            style={{ marginBottom: 28 }}
                        >
                            Also included
                        </h2>
                    </Reveal>
                    <RevealGroup className="lp-grid-4">
                        {content.extras.map((extra) => (
                            <RevealItem
                                key={extra.title}
                                className="lp-pillar lp-card-lift"
                                lift
                            >
                                <h3 className="lp-h3" style={{ fontSize: 20 }}>
                                    {extra.title}
                                </h3>
                                <p
                                    className="t-body t-muted"
                                    style={{ margin: 0 }}
                                >
                                    {extra.body}
                                </p>
                            </RevealItem>
                        ))}
                    </RevealGroup>
                </div>
            </section>

            <section className="lp-in lp-pb" aria-labelledby="more-h">
                <Reveal>
                    <h2
                        id="more-h"
                        className="lp-h2 lp-h2--sm"
                        style={{ marginBottom: 28 }}
                    >
                        More of Coffer
                    </h2>
                </Reveal>
                <RevealGroup className="lp-grid-3">
                    {others.map((key) => (
                        <RevealItem key={key} className="lp-cell" lift>
                            <Link
                                href={`/features/${key}`}
                                className="lp-pillar"
                            >
                                <span className="t-caption t-muted">
                                    {FEATURES[key].eyebrow}
                                </span>
                                <h3 className="lp-h3">{FEATURES[key].name}</h3>
                                <p
                                    className="t-body t-muted"
                                    style={{ margin: 0 }}
                                >
                                    {FEATURES[key].lead}
                                </p>
                            </Link>
                        </RevealItem>
                    ))}
                </RevealGroup>
            </section>

            <CtaBand />
        </>
    );
}
