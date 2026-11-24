import {
    EyeOff,
    Fingerprint,
    KeyRound,
    ListChecks,
    Lock,
    ShieldCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { CheckList } from '@/components/marketing/marketing-parts';
import { CtaBand, PageHero } from '@/components/marketing/page-sections';
import { Reveal, RevealGroup, RevealItem } from '@/components/marketing/motion';
import { SeoHead } from '@/components/marketing/seo-head';
import { Icon } from '@/components/ui/icon';
import type { SeoProps } from '@/types';

type Props = { seo: SeoProps };

/** Spec §14.3 (vault) and §23 (platform), in plain words. Nothing here claims a certification. */
const PILLARS: {
    icon: LucideIcon;
    title: string;
    body: string;
    points: string[];
}[] = [
    {
        icon: KeyRound,
        title: 'Envelope encryption',
        body: 'Each company gets its own random 256-bit data key. That key is sealed with a master key kept outside the database, and every secret is encrypted with AES-256-GCM.',
        points: [
            'Only ciphertext and its nonce are stored',
            'Data keys can be re-sealed when the master key rotates',
            'One company’s key never opens another company’s vault',
        ],
    },
    {
        icon: EyeOff,
        title: 'Secrets stay on the server',
        body: 'Vault lists never carry a password, key or note. Revealing one field is its own request, and that request needs a recent 2FA code.',
        points: [
            'A fresh 2FA code after the policy window (15 minutes by default)',
            'Reveals and copies are rate-limited per person',
            'One-time codes are worked out on the server from the stored key',
        ],
    },
    {
        icon: Lock,
        title: 'Careful in the browser',
        body: 'What you reveal hides itself again, copies clear from the clipboard, and the vault locks when you step away.',
        points: [
            'A revealed password hides after 30 seconds',
            'The clipboard is cleared after the policy time, where the browser allows',
            'The vault locks after 15 minutes idle and unlocks with a 2FA code or password',
        ],
    },
    {
        icon: ShieldCheck,
        title: 'Two-factor where it matters',
        body: 'Admins and everyone who uses the vault must turn on two-factor sign-in. Without it, the vault asks you to set it up first.',
        points: [
            'Authenticator-app codes with recovery codes',
            'Five failed sign-ins lock the account for 15 minutes',
            'Passwords of at least 12 characters, stored as one-way hashes',
        ],
    },
    {
        icon: ListChecks,
        title: 'A record of who did what',
        body: 'Every view, reveal, copy, one-time code, edit, share, export and unlock is written to an append-only vault audit log with time, person, IP and device.',
        points: [
            'Vault events can’t be edited or deleted',
            'Business records keep an activity log of changes',
            'Admins can filter and export the audit log',
        ],
    },
    {
        icon: Fingerprint,
        title: 'Never in logs or diffs',
        body: 'Secret values are kept out of activity-log diffs, application logs, error reports, queued jobs and the data sent to every page.',
        points: [
            'Password reuse is spotted with a keyed fingerprint, never by comparing plain text',
            'Optional breach checks send only the first five characters of a SHA-1 hash',
            'Only “breached” and when it was checked is stored',
        ],
    },
];

const PLATFORM = [
    'Every connection uses HTTPS, with HSTS and a strict Content Security Policy',
    'CSRF protection on every form',
    'Rate limits on sign-in, reveals, exports and invites',
    'Self-hosted fonts and assets: no third-party trackers on the app',
    'Posted books can’t be edited or deleted, even directly in the database',
    'A nightly check that every company’s books still balance',
    'Nightly encrypted backups, with a monthly test restore',
];

const BUSINESS_DATA = [
    'GSTIN, addresses, bank details, emails and every note are encrypted with your company’s own key',
    'Receipts and invoices are encrypted on disk and only served to people allowed to see them',
    'A copy of the database shows only ciphertext for those fields',
];

const PRIVACY = [
    'Built for India’s Digital Personal Data Protection Act (DPDPA)',
    'Each company’s data is kept separate from every other company’s',
    'A Data Processing Agreement for workspaces that need one',
    'Download your personal data, or a whole workspace as CSV, from your account',
    'Delete your account or workspace: erased after 30 days, books kept under “Deleted user”',
];

/**
 * Security (spec §21): a truthful summary of spec §14.3 and §23 (D83). It describes how
 * Coffer is built and names no certification or audit it doesn't hold.
 */
export default function Security({ seo }: Props) {
    return (
        <>
            <SeoHead
                seo={seo}
                title="Security"
                description="How Coffer protects your books and your team’s passwords: per-company envelope encryption with AES-256-GCM, 2FA step-up to reveal, rate limits and an append-only audit log."
            />

            <PageHero
                eyebrow="Security"
                title="Your passwords and books,"
                titleFaint="locked down."
                lead="The same rules protect every company in Coffer: encryption, a second factor and a record of who did what. Here is exactly how it works."
                centered
            />

            <section className="lp-in" aria-label="How the vault is protected">
                <RevealGroup className="lp-grid-3" stagger={0.1}>
                    {PILLARS.map((pillar) => (
                        <RevealItem
                            as="article"
                            key={pillar.title}
                            className="lp-pillar lp-card-lift"
                            lift
                        >
                            <span className="lp-ic">
                                <Icon icon={pillar.icon} size={22} />
                            </span>
                            <h2 className="lp-h3">{pillar.title}</h2>
                            <p className="t-body t-muted" style={{ margin: 0 }}>
                                {pillar.body}
                            </p>
                            <CheckList items={pillar.points} />
                        </RevealItem>
                    ))}
                </RevealGroup>
            </section>

            <section className="lp-sec" aria-labelledby="platform-h">
                <div className="lp-in">
                    <Reveal
                        className="lp-band lp-band--sec cf-card--graphite"
                        distance={40}
                        fromScale={0.98}
                    >
                        <div>
                            <span className="lp-eyebrow lp-eyebrow--dark">
                                Platform
                            </span>
                            <h2
                                id="platform-h"
                                className="lp-h2 lp-h2--sm lp-on-dark"
                                style={{ marginTop: 16 }}
                            >
                                The basics, done properly.
                            </h2>
                            <p
                                className="t-body lp-on-dark-muted lp-body-lg"
                                style={{ marginTop: 16 }}
                            >
                                Coffer isn’t certified under SOC 2 or ISO 27001.
                                We build against OWASP ASVS Level 2 as our
                                target, and we’re glad to answer a security
                                questionnaire.
                            </p>
                        </div>
                        <div className="lp-sec-tile">
                            <CheckList items={PLATFORM} inherit />
                        </div>
                    </Reveal>
                </div>
            </section>

            <section
                className="lp-in lp-pb lp-stack"
                style={{ gap: 24 }}
                aria-labelledby="privacy-h"
            >
                <Reveal className="lp-detail" distance={40}>
                    <div className="lp-panel-copy">
                        <h2 id="privacy-h" className="lp-h3 lp-panel-title">
                            Privacy and your data
                        </h2>
                        <p className="t-body t-muted lp-body-lg">
                            Your workspace’s data belongs to you. We process it
                            only to run Coffer for you. Read the{' '}
                            <a href="/privacy" className="cf-link">
                                Privacy Policy
                            </a>{' '}
                            and the{' '}
                            <a href="/dpa" className="cf-link">
                                Data Processing Agreement
                            </a>
                            .
                        </p>
                    </div>
                    <CheckList items={PRIVACY} />
                </Reveal>
                <Reveal className="lp-detail lp-detail--flip" distance={40}>
                    <div className="lp-panel-copy">
                        <h2 id="business-h" className="lp-h3 lp-panel-title">
                            Your books, encrypted too
                        </h2>
                        <p className="t-body t-muted lp-body-lg">
                            The same per-company keys that protect the vault
                            protect the sensitive parts of your books and files.
                        </p>
                    </div>
                    <CheckList items={BUSINESS_DATA} />
                </Reveal>
                <p className="t-caption t-muted">
                    Found a vulnerability?{' '}
                    <a href="/contact?topic=security" className="cf-link">
                        Report it
                    </a>{' '}
                    and we’ll get back to you quickly. Our{' '}
                    <a href="/.well-known/security.txt" className="cf-link">
                        security.txt
                    </a>{' '}
                    has the details.
                </p>
            </section>

            <CtaBand />
        </>
    );
}
