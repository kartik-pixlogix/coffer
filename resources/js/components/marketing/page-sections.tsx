import type { ReactNode } from 'react';
import { Reveal } from '@/components/marketing/motion';
import { ButtonLink } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * The hero of the pages built from Landing sections: eyebrow, two-tone title, lead.
 * Its parts rise in one after another as the page paints (CSS, so it plays from the
 * server-rendered HTML; D103).
 */
export function PageHero({
    eyebrow,
    title,
    titleFaint,
    lead,
    centered = false,
    children,
}: {
    eyebrow: string;
    title: string;
    titleFaint?: string;
    lead?: ReactNode;
    centered?: boolean;
    children?: ReactNode;
}) {
    return (
        <section
            className={cn(
                'lp-in lp-page-hero lp-rise-group',
                centered && 'lp-page-hero--center',
            )}
            aria-labelledby="page-h"
        >
            <div className="lp-rise">
                <span className="lp-eyebrow">
                    <span className="cf-dot-title" />
                    {eyebrow}
                </span>
            </div>
            <div className="lp-rise">
                <h1 id="page-h" className="lp-h1 lp-h1--sm">
                    {title}
                    {titleFaint && (
                        <>
                            {' '}
                            <span className="lp-faint">{titleFaint}</span>
                        </>
                    )}
                </h1>
            </div>
            {lead && (
                <div className="lp-rise">
                    <p className="lp-lead">{lead}</p>
                </div>
            )}
            {children && <div className="lp-rise">{children}</div>}
        </section>
    );
}

/**
 * Landing's closing lime band: Start free trial / Book a demo. Book a demo is the
 * dark-outline secondary for lime surfaces (D102), not the glass button.
 */
export function CtaBand({
    id,
    title = 'Bring every company under one roof.',
    body = 'Start with one company today. Add the JV, the team and the vault when you’re ready.',
}: {
    id?: string;
    title?: string;
    body?: string;
}) {
    return (
        <section
            id={id}
            className="lp-in"
            style={{ paddingBottom: 72 }}
            aria-labelledby="cta-h"
        >
            <Reveal
                className="lp-band lp-band--cta"
                distance={40}
                fromScale={0.97}
            >
                <div className="lp-band-blob" aria-hidden="true" />
                <div className="lp-band-body">
                    <h2
                        id="cta-h"
                        className="lp-h2"
                        style={{ maxWidth: 760, margin: '0 auto' }}
                    >
                        {title}
                    </h2>
                    <p
                        className="t-body"
                        style={{
                            margin: '16px auto 0',
                            maxWidth: 560,
                            fontSize: 17,
                            lineHeight: '26px',
                        }}
                    >
                        {body}
                    </p>
                    <div className="lp-cta-row" style={{ marginTop: 28 }}>
                        <ButtonLink
                            href="/register"
                            variant="primary"
                            size="lg"
                        >
                            Start free trial
                        </ButtonLink>
                        <ButtonLink
                            href="/contact?topic=demo"
                            variant="ghost"
                            size="lg"
                            className="lp-btn-onlime"
                        >
                            Book a demo
                        </ButtonLink>
                    </div>
                </div>
            </Reveal>
        </section>
    );
}
