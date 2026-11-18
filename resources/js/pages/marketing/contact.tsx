import { Form, Link } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarClock,
    Check,
    Mail,
    ShieldCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { CheckList } from '@/components/marketing/marketing-parts';
import { Reveal, RevealGroup, RevealItem } from '@/components/marketing/motion';
import { PageHero } from '@/components/marketing/page-sections';
import { SeoHead } from '@/components/marketing/seo-head';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Button, ButtonLink } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Select } from '@/components/ui/select';
import { TextInput } from '@/components/ui/text-input';
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/marketing/contact';
import type { ContactTopicKey, SeoProps } from '@/types';

type Props = {
    topic: ContactTopicKey;
    topics: { value: ContactTopicKey; label: string }[];
    teamSizes: string[];
    /** Set after a successful send (the success state). */
    sent: { topic: ContactTopicKey; name: string } | null;
    seo: SeoProps;
};

const NEXT: { icon: LucideIcon; title: string; body: string }[] = [
    {
        icon: Mail,
        title: 'We reply by email',
        body: 'Usually within one working day (IST).',
    },
    {
        icon: CalendarClock,
        title: 'A 30-minute walkthrough',
        body: 'Your companies, your JV, your spreadsheets: we set it up with you.',
    },
    {
        icon: ShieldCheck,
        title: 'No spam',
        body: 'We use your details only to answer this request.',
    },
];

function Sent({ name, topic }: { name: string; topic: ContactTopicKey }) {
    return (
        <div className="lp-form-card lp-done" role="status">
            <span className="lp-ic">
                <Icon icon={Check} size={22} strokeWidth={2} />
            </span>
            <h2 className="lp-h3">Thanks, {name}. Your request is in.</h2>
            <p className="t-body t-muted lp-body-lg">
                {topic === 'demo'
                    ? 'We’ll email you to pick a time for the demo.'
                    : 'We’ll reply by email, usually within one working day.'}
            </p>
            <div className="cf-row" style={{ gap: 10, flexWrap: 'wrap' }}>
                <ButtonLink
                    href="/register"
                    variant="primary"
                    disc={ArrowRight}
                >
                    Start free trial
                </ButtonLink>
                <ButtonLink href="/" variant="secondary">
                    Back to the home page
                </ButtonLink>
            </div>
        </div>
    );
}

/**
 * Contact / Book a demo (spec §21, D84). Stores the lead and emails the team; a hidden
 * honeypot field catches bots and the form is rate-limited. `?topic=` preselects the reason.
 */
export default function Contact({
    topic,
    topics,
    teamSizes,
    sent,
    seo,
}: Props) {
    const isDemo = topic === 'demo';

    return (
        <>
            <SeoHead
                seo={seo}
                title={isDemo ? 'Book a demo' : 'Contact'}
                description="Book a demo of Coffer or ask us anything about plans, joint ventures, the credentials vault or moving your spreadsheets in."
            />

            <PageHero
                eyebrow={isDemo ? 'Book a demo' : 'Contact'}
                title={isDemo ? 'See Coffer with' : 'Talk to the'}
                titleFaint={isDemo ? 'your own setup.' : 'people who build it.'}
                lead="Tell us a little about your companies and we’ll get back to you. Prefer to look around first? The free trial needs no card."
            />

            <section
                className="lp-in lp-pb lp-contact"
                aria-label="Contact form"
            >
                <RevealGroup className="lp-stack">
                    {NEXT.map((item) => (
                        <RevealItem
                            key={item.title}
                            className="cf-row"
                            style={{ gap: 14, alignItems: 'flex-start' }}
                        >
                            <span className="lp-ic lp-ic--sm">
                                <Icon icon={item.icon} size={18} />
                            </span>
                            <span className="cf-stack" style={{ gap: 2 }}>
                                <span className="t-label-strong">
                                    {item.title}
                                </span>
                                <span className="t-body t-muted">
                                    {item.body}
                                </span>
                            </span>
                        </RevealItem>
                    ))}
                    <RevealItem className="lp-pillar" style={{ marginTop: 8 }}>
                        <span className="t-label-strong">
                            In a demo we cover
                        </span>
                        <CheckList
                            items={[
                                'Setting up a single company or a JV with sides',
                                'Hours, salary cost and expense approvals',
                                'Settlements, capital accounts and reports',
                                'Moving shared logins into the vault',
                            ]}
                        />
                    </RevealItem>
                </RevealGroup>

                <Reveal delay={0.1} distance={36}>
                    {sent ? (
                        <Sent name={sent.name} topic={sent.topic} />
                    ) : (
                        <Form
                            {...store.form()}
                            options={{ preserveScroll: true }}
                            className="lp-form-card"
                        >
                            {({ processing, errors }) => (
                                <>
                                    {errors.form && (
                                        <AlertBanner variant="neg" role="alert">
                                            {errors.form}
                                        </AlertBanner>
                                    )}
                                    <Select
                                        label="What can we help with?"
                                        name="topic"
                                        defaultValue={topic}
                                        options={topics}
                                        error={errors.topic}
                                    />
                                    <div className="lp-form-grid">
                                        <TextInput
                                            label="Your name"
                                            name="name"
                                            autoComplete="name"
                                            required
                                            maxLength={120}
                                            error={errors.name}
                                        />
                                        <TextInput
                                            label="Work email"
                                            name="email"
                                            type="email"
                                            autoComplete="email"
                                            required
                                            maxLength={190}
                                            error={errors.email}
                                        />
                                        <TextInput
                                            label="Company"
                                            name="company"
                                            autoComplete="organization"
                                            maxLength={120}
                                            error={errors.company}
                                        />
                                        <TextInput
                                            label="Phone (optional)"
                                            name="phone"
                                            type="tel"
                                            autoComplete="tel"
                                            maxLength={40}
                                            error={errors.phone}
                                        />
                                    </div>
                                    <Select
                                        label="Team size"
                                        name="team_size"
                                        placeholder="Choose one"
                                        options={teamSizes.map((size) => ({
                                            value: size,
                                            label: `${size} people`,
                                        }))}
                                        error={errors.team_size}
                                    />
                                    <Textarea
                                        label={
                                            isDemo
                                                ? 'Anything we should know? (optional)'
                                                : 'Your message'
                                        }
                                        name="message"
                                        rows={5}
                                        limit={5000}
                                        error={errors.message}
                                    />
                                    {/* Honeypot: hidden from people and assistive tech; bots fill it. */}
                                    <div className="lp-hp" aria-hidden="true">
                                        <label htmlFor="contact-website">
                                            Website
                                        </label>
                                        <input
                                            id="contact-website"
                                            type="text"
                                            name="website"
                                            tabIndex={-1}
                                            autoComplete="off"
                                        />
                                    </div>
                                    <div
                                        className="cf-row"
                                        style={{
                                            gap: 12,
                                            flexWrap: 'wrap',
                                            justifyContent: 'space-between',
                                        }}
                                    >
                                        <span className="t-caption t-muted">
                                            By sending this you agree to our{' '}
                                            <Link href="/privacy">
                                                Privacy Policy
                                            </Link>
                                            .
                                        </span>
                                        <Button
                                            type="submit"
                                            variant="primary"
                                            size="lg"
                                            disc={ArrowRight}
                                            loading={processing}
                                        >
                                            {isDemo
                                                ? 'Request a demo'
                                                : 'Send message'}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </Reveal>
            </section>
        </>
    );
}
