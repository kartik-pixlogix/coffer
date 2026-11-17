import { Head, Link, router, usePage } from '@inertiajs/react';
import { RotateCw } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button, ButtonLink, TextButton } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { login, welcome } from '@/routes';
import { store as reportBrokenLink } from '@/routes/broken-links';
import type { Auth } from '@/types/auth';

type Status = 403 | 404 | 419 | 500 | 503;

type Props = {
    status: Status;
    /** Host and path that failed, shown on the 404: "coffer.app/expenses/123". */
    path: string;
    /** "Go to the dashboard" / "Go to my timesheets"; null when nobody is signed in. */
    landing: { url: string; label: string } | null;
    /** Reports, when the role may open it ("Open Reports"). */
    reports: string | null;
    /** "Or jump to" on the 404: what the role may open. */
    places: { label: string; url: string }[];
};

const COPY: Record<Status, { head: string; title: string; body: string }> = {
    403: {
        head: 'No access',
        title: 'You can’t open this page',
        body: 'Your account doesn’t include it. If you think it should, ask an Admin of the company to change your role.',
    },
    404: {
        head: 'Page not found',
        title: 'This page isn’t here',
        body: 'The link may be old, or the page moved when the company was renamed. Nothing in your books has changed.',
    },
    419: {
        head: 'Page expired',
        title: 'This page expired',
        body: 'A page left open too long stops accepting changes, to keep your account safe. Reload it and try again. Nothing was saved.',
    },
    500: {
        head: 'Something went wrong',
        title: 'Something went wrong on our side',
        body: 'We’ve logged the error and will look into it. Nothing in your books has changed. Try again in a minute.',
    },
    503: {
        head: 'Down for maintenance',
        title: 'Coffer is down for maintenance',
        body: 'We’re making an update and will be back in a few minutes. Your books and vault are safe.',
    },
};

function reload() {
    window.location.reload();
}

/**
 * Error pages (design/screens/State404.dc.html; 403 / 419 / 500 / 503 follow the
 * same card, D89). Signed in, the card sits in the app shell like the board;
 * otherwise (a guest, or maintenance mode) it stands alone on the canvas.
 */
export default function ErrorPage({
    status,
    path,
    landing,
    reports,
    places,
}: Props) {
    const props = usePage().props;
    const auth = props.auth as Auth | undefined;
    const signedIn = auth?.user != null && props.currentCompany != null;
    const copy = COPY[status] ?? COPY[500];
    const [reported, setReported] = useState(false);

    const home =
        landing !== null ? (
            <ButtonLink
                href={landing.url}
                variant={
                    status === 404 || status === 403 ? 'primary' : 'secondary'
                }
                size="lg"
            >
                {landing.label}
            </ButtonLink>
        ) : auth?.user != null ? null : (
            <ButtonLink
                href={status === 503 ? welcome() : login()}
                variant={
                    status === 404 || status === 403 ? 'primary' : 'secondary'
                }
                size="lg"
            >
                {status === 503 ? 'Go to the home page' : 'Sign in'}
            </ButtonLink>
        );

    const retry = (
        <Button variant="primary" size="lg" icon={RotateCw} onClick={reload}>
            {status === 419 ? 'Reload the page' : 'Try again'}
        </Button>
    );

    const card = (
        <section className="cf-card err-card" aria-labelledby="err-h">
            <Head title={copy.head} />
            <p className="err-big" aria-hidden="true">
                {status}
            </p>
            <h1 id="err-h" className="t-heading err-title">
                {copy.title}
            </h1>
            <p className="t-body t-muted err-body">{copy.body}</p>
            {status === 404 && (
                <span className="cf-pill cf-pill--outline t-money-sm err-path">
                    {path}
                </span>
            )}
            <div className="cf-row err-actions">
                {status === 419 || status === 500 || status === 503
                    ? retry
                    : null}
                {home}
                {status === 404 && reports !== null && (
                    <ButtonLink href={reports} variant="secondary" size="lg">
                        Open Reports
                    </ButtonLink>
                )}
            </div>
            {status === 404 && (
                <div className="err-jump">
                    {places.length > 0 && (
                        <>
                            <span className="t-label t-muted">Or jump to</span>
                            <div className="cf-row err-chips">
                                {places.map((place) => (
                                    <Link
                                        key={place.url}
                                        href={place.url}
                                        className="cf-chip err-chip"
                                    >
                                        {place.label}
                                    </Link>
                                ))}
                            </div>
                        </>
                    )}
                    {reported ? (
                        <p
                            className="t-caption t-muted err-report"
                            role="status"
                        >
                            Thanks. We’ll fix the link.
                        </p>
                    ) : (
                        <TextButton
                            className="t-caption err-report"
                            onClick={() =>
                                router.post(
                                    reportBrokenLink().url,
                                    { path },
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                        onFinish: () => setReported(true),
                                    },
                                )
                            }
                        >
                            Tell us this link is broken
                        </TextButton>
                    )}
                </div>
            )}
        </section>
    );

    if (signedIn) {
        return <AppLayout>{card}</AppLayout>;
    }

    return <StandaloneFrame>{card}</StandaloneFrame>;
}

/** A guest or maintenance-mode error: the wordmark and the card on the canvas. */
function StandaloneFrame({ children }: { children: ReactNode }) {
    return (
        <div className="cf-page err-page">
            <main className="err-main">
                <a href="/" className="cf-wordmark err-wordmark">
                    Coffer
                </a>
                {children}
            </main>
        </div>
    );
}
