import type { ReactNode } from 'react';
import { ButtonLink } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/** The hero of the pages built from Landing sections: eyebrow, two-tone title, lead. */
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
                'lp-in lp-page-hero',
                centered && 'lp-page-hero--center',
            )}
            aria-labelledby="page-h"
        >
            <span className="lp-eyebrow">
                <span className="cf-dot-title" />
                {eyebrow}
            </span>
            <h1 id="page-h" className="lp-h1 lp-h1--sm">
                {title}
                {titleFaint && (
                    <>
                        {' '}
                        <span className="lp-faint">{titleFaint}</span>
                    </>
                )}
            </h1>
            {lead && <p className="lp-lead">{lead}</p>}
            {children}
        </section>
    );
}

/** Landing's closing lime band: Start free trial / Book a demo. */
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
            <div className="lp-band lp-band--cta">
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
            </div>
        </section>
    );
}
