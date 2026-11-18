import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    Clock,
    KeyRound,
    ListChecks,
    Lock,
    PieChart,
    ShieldCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    motion,
    useMotionValue,
    useScroll,
    useSpring,
    useTransform,
} from 'motion/react';
import type { MotionValue } from 'motion/react';
import { createContext, useContext, useState } from 'react';
import type { PointerEvent, ReactNode } from 'react';
import {
    CheckList,
    CountUp,
    FaqList,
    SectionHead,
} from '@/components/marketing/marketing-parts';
import type { Faq } from '@/components/marketing/marketing-parts';
import {
    Reveal,
    RevealGroup,
    RevealItem,
    useFinePointer,
} from '@/components/marketing/motion';
import { CtaBand } from '@/components/marketing/page-sections';
import { SeoHead } from '@/components/marketing/seo-head';
import { buttonClass, ButtonLink } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Tab, TabPanel, Tabs, TabsList } from '@/components/ui/tabs';
import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { formatIndianNumber, formatInr } from '@/lib/format';
import { planPrice } from '@/lib/plans';
import { cn } from '@/lib/utils';
import type { PlanOption, SeoProps } from '@/types';

type Props = { plans: PlanOption[]; seo: SeoProps };

const rupees = (paise: number) => formatInr(paise, { decimals: 'never' });
const tenthsOfHours = (tenths: number) =>
    `${formatIndianNumber(tenths / 10, 1)} h`;

/** The preview's KPIs (Saarthi Portal JV, Apr–Oct, as in the demo data). */
const KPIS = [
    { label: 'Revenue', value: 62_10_000_00, tone: '' },
    { label: 'Expenses', value: 43_67_650_00, tone: '' },
    { label: 'Net profit', value: 18_42_350_00, tone: 'lp-kpi--lime' },
    { label: 'Hours logged', value: 21_575, tone: 'lp-kpi--graphite' },
] as const;

/** Profit by month: up bars to the best month, losses below the line. */
const PROFIT = [-65000, 150000, 170000, 365000, -32650, 655000, 600000];
const BARS = PROFIT.map((p, i) => ({
    up: `${p > 0 ? Math.round((p / 655000) * 100) : 0}%`,
    down: `${p < 0 ? Math.round((-p / 65000) * 20) : 0}px`,
    neg: p < 0,
    on: i === PROFIT.length - 1,
}));

const PARTNERS = [
    ['AM', 'Aarav', 'var(--partner-1)', '₹5,52,705'],
    ['IR', 'Ishita', 'var(--partner-2)', '₹3,68,470'],
    ['KS', 'Kabir', 'var(--partner-3)', '₹4,60,588'],
    ['MI', 'Meera', 'var(--partner-4)', '₹4,60,587'],
] as const;

const SEGMENTS = [
    'Agencies',
    'Studios',
    'Joint ventures',
    'Consultancies',
    'Holding groups',
    'Product teams',
];

const PILLARS: {
    icon: LucideIcon;
    title: string;
    body: string;
    points: string[];
    href: string;
}[] = [
    {
        icon: Building2,
        title: 'Multi-company and JV accounting',
        body: 'Run single companies and joint ventures side by side, each with its own books.',
        points: [
            'One-click company switcher',
            'Sides and partners for JVs',
            'Trial balance and balance sheet',
        ],
        href: '/features/finance',
    },
    {
        icon: Clock,
        title: 'Timesheets to payroll',
        body: 'Hours become salary cost per partner, whether people are hourly or on a fixed salary.',
        points: [
            'Day or month entry',
            'Bulk import from spreadsheets',
            'Approvals for team expenses',
        ],
        href: '/features/timesheets',
    },
    {
        icon: PieChart,
        title: 'Equity and settlements',
        body: 'Profit follows equity. Capital accounts, partner loans and settlements stay in step.',
        points: [
            'Profit due per partner',
            'Who owes whom, per side',
            'Close the year in one step',
        ],
        href: '/features/reports',
    },
    {
        icon: Lock,
        title: 'Credentials vault',
        body: 'The logins your team shares, kept next to the company they belong to.',
        points: [
            'TOTP codes built in',
            'Reveal needs a fresh 2FA code',
            'Health score and audit log',
        ],
        href: '/features/vault',
    },
];

type ModuleKey = 'Dashboard' | 'Timesheets' | 'Settlements' | 'Vault';

const MODULES: Record<
    ModuleKey,
    { body: string; points: string[]; href: string }
> = {
    Dashboard: {
        body: 'Revenue, expenses, profit and hours for the company you’re in, with each partner’s share worked out.',
        points: [
            'Switch companies from the top bar',
            'Profit by month with the losses shown below the line',
            'Profit due per partner, by equity',
        ],
        href: '/features/finance',
    },
    Timesheets: {
        body: 'Log hours by day, or enter a month’s total and let Coffer spread it over the working days.',
        points: [
            'Day or month entry',
            'Saturdays on or off per company',
            'Hours feed salary cost per partner',
        ],
        href: '/features/timesheets',
    },
    Settlements: {
        body: 'In a joint venture, Coffer shows which side holds more than it’s owed and records the transfer that evens it out.',
        points: [
            'Entitled versus held, per side',
            'Part payments tracked',
            'Every transfer with its bank reference',
        ],
        href: '/features/finance',
    },
    Vault: {
        body: 'Shared logins, API keys and SSH keys for each company, with 2FA codes and a log of every reveal.',
        points: [
            'TOTP codes next to the password',
            'Share by person or team, with an end date',
            'Health score and fix-it lists',
        ],
        href: '/features/vault',
    },
};

const TIME_ROWS = [
    ['RD', 'Rohan Desai', '160.0 h'],
    ['SQ', 'Sana Qureshi', '148.5 h'],
    ['DP', 'Dev Patel', '151.0 h'],
] as const;

const VAULT_ROWS = [
    [
        'GH',
        'GitHub — vertexlabs org',
        'Strong · 2FA on',
        'cf-pill--pos',
        'Healthy',
    ],
    [
        'MY',
        'MySQL — production database',
        'Changed 220 days ago',
        'cf-pill--warn',
        'Rotate',
    ],
    [
        'GM',
        'Gmail — support inbox',
        'Seen in a breach list',
        'cf-pill--neg',
        'Breached',
    ],
    [
        'VC',
        'Vercel — team API token',
        'Expires in 9 days',
        'cf-pill--warn',
        'Renew',
    ],
] as const;

const STEPS = [
    [
        'Create your company',
        'Single company or joint venture. Add partners, sides and equity, and Coffer checks it adds up to 100%.',
    ],
    [
        'Bring in the team',
        'Invite people with the right role, log hours and expenses, and move shared logins into the vault.',
    ],
    [
        'Close the month',
        'Reports, settlements and statements are ready when the last entry is in. Export them for your accountant.',
    ],
] as const;

const SECURITY: { icon: LucideIcon; title: string; body: string }[] = [
    {
        icon: KeyRound,
        title: 'Encryption',
        body: 'Vault items and attachments are encrypted at rest and in transit.',
    },
    {
        icon: ShieldCheck,
        title: 'Two-factor everywhere',
        body: 'Sign-in, unlocking the vault and revealing a password each ask for a code.',
    },
    {
        icon: ListChecks,
        title: 'Audit log',
        body: 'Every view, reveal, copy, edit and share is recorded with time, person, IP and device.',
    },
];

/** Landing copy per plan (Landing.dc.html); prices come from the plans table. */
const PLAN_BODY: Record<string, string> = {
    starter: 'One company getting its books in order.',
    growth: 'A few companies, or a joint venture.',
    business: 'Groups that need roles, policies and audit.',
    enterprise: 'Custom setup, SSO and invoicing.',
};

const FAQS: Faq[] = [
    [
        'Can I run more than one company?',
        'Yes. Each company has its own books, team and vault. Switch from the top bar, and reports never mix companies.',
    ],
    [
        'How do joint ventures work?',
        'A JV has sides, one per partner company, and each side’s partners split its share. Coffer works out what each side is owed and records the transfers between them.',
    ],
    [
        'Does Coffer run payroll?',
        'It turns hours and fixed salaries into salary cost per partner. You still pay people through your bank or payroll provider.',
    ],
    [
        'How are vault passwords protected?',
        'Vault items are encrypted at rest. Revealing one asks for a fresh 2FA code, and every view, copy and share goes in the audit log.',
    ],
    [
        'Can my accountant get access?',
        'Invite them as a Viewer. They see reports and statements, and nothing in the vault.',
    ],
    [
        'Can I bring in my spreadsheets?',
        'Yes. Bulk import maps your columns, flags rows with problems and lets you fix them before anything is saved.',
    ],
];

type HeroMotion = {
    /** Pointer position over the hero, -1…1 on each axis, spring-smoothed. */
    px: MotionValue<number>;
    py: MotionValue<number>;
    scrollY: MotionValue<number>;
    /** Parallax and tilt on (off under reduced motion, and in the server render). */
    moving: boolean;
    /** The pointer drives the tilt (a fine pointer, and motion allowed). */
    pointerOn: boolean;
};

const HeroMotionContext = createContext<HeroMotion | null>(null);

function useHero(): HeroMotion {
    const hero = useContext(HeroMotionContext);

    if (hero === null) {
        throw new Error('HeroStage must sit inside the hero.');
    }

    return hero;
}

const SPRING = { stiffness: 140, damping: 22, mass: 0.6 };

/**
 * Pointer and scroll motion for the hero (Landing.dc.html `shift(depth)`, D103): the
 * preview tilts toward the cursor in 3D, the floating cards sit on their own depth
 * layers and drift with the pointer and the scroll, and a lime spotlight follows the
 * cursor. Pointer effects need a fine pointer; everything is off under reduced motion.
 * Only transforms and opacity change, through motion values (no re-renders).
 *
 * The moving elements are motion elements in every state, and the motion values are
 * bound once motion is allowed, so hydration never swaps the DOM under the preview.
 */
function useHeroMotion() {
    const reduced = useReducedMotion();
    const fine = useFinePointer();
    const pointerOn = !reduced && fine;

    const rawX = useMotionValue(0);
    const rawY = useMotionValue(0);
    const px = useSpring(rawX, SPRING);
    const py = useSpring(rawY, SPRING);

    const spotX = useMotionValue(0);
    const spotY = useMotionValue(0);
    const spotOpacity = useMotionValue(0);
    const spotXS = useSpring(spotX, { stiffness: 260, damping: 30 });
    const spotYS = useSpring(spotY, { stiffness: 260, damping: 30 });
    const spotOpacityS = useSpring(spotOpacity, {
        stiffness: 120,
        damping: 24,
    });

    const { scrollY } = useScroll();

    const onPointerMove = (event: PointerEvent<HTMLElement>) => {
        if (!pointerOn || event.pointerType !== 'mouse') {
            return;
        }

        const rect = event.currentTarget.getBoundingClientRect();
        const x = event.clientX - rect.left;
        const y = event.clientY - rect.top;

        rawX.set((x / rect.width - 0.5) * 2);
        rawY.set((y / rect.height - 0.5) * 2);
        spotX.set(x);
        spotY.set(y);
        spotOpacity.set(1);
    };

    const onPointerLeave = () => {
        rawX.set(0);
        rawY.set(0);
        spotOpacity.set(0);
    };

    const hero: HeroMotion = {
        px,
        py,
        scrollY,
        moving: !reduced,
        pointerOn,
    };

    return {
        reduced,
        pointerOn,
        hero,
        handlers: pointerOn ? { onPointerMove, onPointerLeave } : {},
        spot: { x: spotXS, y: spotYS, opacity: spotOpacityS },
    };
}

/** The spotlight: a soft lime disc moved by transform only. */
function HeroSpot({
    x,
    y,
    opacity,
}: {
    x: MotionValue<number>;
    y: MotionValue<number>;
    opacity: MotionValue<number>;
}) {
    return (
        <motion.div
            className="lp-spot"
            style={{ x, y, opacity }}
            aria-hidden="true"
        />
    );
}

/** The glow behind the hero: drifts on a loop (CSS) and slides with the scroll. */
function HeroGlow({ reduced }: { reduced: boolean }) {
    const { scrollY } = useScroll();
    const y = useTransform(scrollY, [0, 900], [0, 160], { clamp: true });

    return (
        <motion.div
            className="lp-glow-wrap"
            style={reduced ? undefined : { y }}
            aria-hidden="true"
        >
            <div className="lp-glow" />
        </motion.div>
    );
}

/**
 * One layer of the stage. `depth` scales the pointer and scroll parallax (design:
 * 14 px × depth sideways, 10 px × depth up and down, 6 % of the scroll × depth) and
 * lifts the layer off the window in 3D so the tilt separates the layers.
 */
function DepthLayer({
    depth,
    className,
    children,
}: {
    depth: number;
    className: string;
    children: ReactNode;
}) {
    const hero = useHero();
    const x = useTransform(hero.px, (v) => -v * depth * 14);
    const y = useTransform(
        [hero.py, hero.scrollY],
        ([v, s]: number[]) =>
            -(v ?? 0) * depth * 10 - Math.min(s ?? 0, 900) * depth * 0.06,
    );

    return (
        <motion.div
            className={cn('lp-layer', className)}
            style={hero.moving ? { x, y, z: depth * 36 } : undefined}
        >
            {children}
        </motion.div>
    );
}

/**
 * The 3D frame around the stage: tilts toward the pointer (rotateX / rotateY on
 * springs) and, as the page scrolls past the hero, lifts and leans back a little.
 * Flat at the top of the page, so the preview first reads like the design.
 */
function StageTilt({ children }: { children: ReactNode }) {
    const hero = useHero();
    const lift = useTransform(hero.scrollY, [0, 700], [0, -36], {
        clamp: true,
    });
    const lean = useTransform(hero.scrollY, [0, 700], [0, 7], { clamp: true });
    const scale = useTransform(hero.scrollY, [0, 700], [1, 0.97], {
        clamp: true,
    });
    const tiltX = useTransform(hero.py, (v) => -v * 5);
    const rotateY = useTransform(hero.px, (v) => v * 7);
    const rotateX = useTransform(
        [lean, tiltX],
        ([l, t]: number[]) => (l ?? 0) + (t ?? 0),
    );

    return (
        <motion.div
            className="lp-stage-3d"
            style={
                hero.moving
                    ? {
                          rotateX: hero.pointerOn ? rotateX : lean,
                          rotateY: hero.pointerOn ? rotateY : 0,
                          y: lift,
                          scale,
                      }
                    : undefined
            }
        >
            {children}
        </motion.div>
    );
}

function HeroStage() {
    const layers = (
        <>
            <DepthLayer depth={0.4} className="lp-win">
                <div className="lp-win-body">
                    <div className="lp-win-rail">
                        <span className="cf-rail-logo">C</span>
                        {[0, 1, 2, 3, 4, 5, 6].map((i) => (
                            <span
                                key={i}
                                className={cn('lp-win-dot', i === 0 && 'is-on')}
                            />
                        ))}
                    </div>
                    <div className="lp-win-main">
                        <div className="cf-kv" style={{ alignItems: 'center' }}>
                            <span className="cf-stack">
                                <span className="t-caption t-muted">
                                    Saarthi Portal JV · FY 2026-27
                                </span>
                                <span className="t-heading">Dashboard</span>
                            </span>
                            <span className="cf-row" style={{ gap: 8 }}>
                                <span className="cf-pill">Apr – Oct</span>
                                <span className="cf-avatar cf-avatar--sm">
                                    AM
                                </span>
                            </span>
                        </div>
                        <div className="lp-kpis">
                            {KPIS.map((kpi) => (
                                <div
                                    key={kpi.label}
                                    className={cn('cf-card lp-kpi', kpi.tone)}
                                >
                                    <span className="t-caption lp-kpi-label">
                                        {kpi.label}
                                    </span>
                                    <CountUp
                                        value={kpi.value}
                                        format={
                                            kpi.label === 'Hours logged'
                                                ? tenthsOfHours
                                                : rupees
                                        }
                                        className="t-money lp-kpi-value"
                                    />
                                </div>
                            ))}
                        </div>
                        <div className="lp-win-grid">
                            <div
                                className="cf-card"
                                style={{
                                    display: 'flex',
                                    flexDirection: 'column',
                                    gap: 10,
                                }}
                            >
                                <span className="cf-dot-title t-label-strong">
                                    Profit by month
                                </span>
                                <div className="lp-bars">
                                    <div className="lp-bars-up">
                                        {BARS.map((bar, i) => (
                                            <span
                                                key={i}
                                                className={cn(
                                                    'mini-bar',
                                                    bar.on && 'on',
                                                )}
                                                style={{ height: bar.up }}
                                            />
                                        ))}
                                    </div>
                                    <div className="lp-bars-down">
                                        {BARS.map((bar, i) => (
                                            <span
                                                key={i}
                                                className="mini-bar neg"
                                                style={{
                                                    height: bar.down,
                                                    opacity: bar.neg ? 1 : 0,
                                                }}
                                            />
                                        ))}
                                    </div>
                                </div>
                            </div>
                            <div
                                className="cf-card lp-win-side"
                                style={{
                                    display: 'flex',
                                    flexDirection: 'column',
                                    gap: 8,
                                }}
                            >
                                <span className="cf-dot-title t-label-strong">
                                    Profit due
                                </span>
                                {PARTNERS.map(([ini, name, dot, due]) => (
                                    <div
                                        key={name}
                                        className="cf-kv"
                                        style={{ alignItems: 'center' }}
                                    >
                                        <span
                                            className="cf-row"
                                            style={{ gap: 8 }}
                                        >
                                            <span className="cf-avatar cf-avatar--sm">
                                                {ini}
                                                <span
                                                    className="cf-avatar-dot"
                                                    style={{ background: dot }}
                                                />
                                            </span>
                                            <span className="t-body">
                                                {name}
                                            </span>
                                        </span>
                                        <span className="t-money-sm">
                                            {due}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </DepthLayer>
            <DepthLayer
                depth={1.6}
                className="lp-float lp-float--settle cf-card cf-card--graphite"
            >
                <span
                    className="t-caption"
                    style={{ color: 'var(--on-graphite-muted)' }}
                >
                    Settlement due
                </span>
                <div className="t-label-strong" style={{ margin: '6px 0 2px' }}>
                    Vertex Labs owes Bluefin Infotech
                </div>
                <CountUp
                    value={3_24_600_00}
                    format={rupees}
                    className="t-money lp-figure-lime lp-nowrap"
                    style={{ display: 'block' }}
                />
                <span
                    className="cf-btn cf-btn--lime cf-btn--sm"
                    style={{ marginTop: 12 }}
                >
                    Record transfer
                </span>
            </DepthLayer>
            <DepthLayer depth={2.2} className="lp-float lp-float--totp cf-card">
                <div className="lp-totp-head">
                    <span
                        className="cf-avatar cf-avatar--tile"
                        style={{ fontFamily: 'var(--font-display)' }}
                    >
                        GH
                    </span>
                    <span className="cf-stack" style={{ minWidth: 0 }}>
                        <span className="t-label-strong lp-nowrap">
                            GitHub — vertexlabs org
                        </span>
                        <span className="t-caption t-muted">
                            Internal vault
                        </span>
                    </span>
                </div>
                <div
                    className="cf-kv"
                    style={{ marginTop: 12, alignItems: 'center' }}
                >
                    <span className="t-money lp-totp lp-nowrap">492 039</span>
                    <span className="cf-ring cf-ring--onlight">
                        <svg
                            width="32"
                            height="32"
                            viewBox="0 0 32 32"
                            aria-hidden="true"
                        >
                            <circle
                                className="cf-ring-track"
                                cx="16"
                                cy="16"
                                r="13"
                                strokeWidth="3"
                            />
                            <circle
                                className="cf-ring-bar"
                                cx="16"
                                cy="16"
                                r="13"
                                strokeWidth="3"
                                strokeDasharray="81.68"
                                strokeDashoffset="32"
                                transform="rotate(-90 16 16)"
                            />
                        </svg>
                    </span>
                </div>
            </DepthLayer>
            <DepthLayer depth={1.2} className="lp-float lp-float--hours">
                <div className="lp-hours-head">
                    <span className="t-label-strong">Log hours · Nov 2026</span>
                    <span className="t-caption">Month mode</span>
                </div>
                <div className="lp-hours-body">
                    <span className="t-money lp-hours-figure">57.33 h</span>
                    <span className="t-caption lp-nowrap">
                        21 working days → 2.73 hrs/day
                    </span>
                </div>
            </DepthLayer>
        </>
    );

    return (
        <div
            className="lp-stage"
            role="img"
            aria-label="Product preview: the Saarthi Portal JV dashboard with revenue, expenses, profit and hours, a settlement due card, a GitHub one-time code and a month of logged hours."
        >
            <StageTilt>{layers}</StageTilt>
        </div>
    );
}

function ModuleDemo({ module }: { module: ModuleKey }) {
    if (module === 'Dashboard') {
        return (
            <>
                <div className="lp-kpis lp-kpis--2">
                    {KPIS.map((kpi) => (
                        <div
                            key={kpi.label}
                            className={cn('cf-card lp-kpi', kpi.tone)}
                        >
                            <span className="t-caption lp-kpi-label">
                                {kpi.label}
                            </span>
                            <span className="t-money lp-kpi-value lp-kpi-value--lg">
                                {kpi.label === 'Hours logged'
                                    ? tenthsOfHours(kpi.value)
                                    : rupees(kpi.value)}
                            </span>
                        </div>
                    ))}
                </div>
                <div className="cf-card lp-bars-card">
                    {BARS.map((bar, i) => (
                        <span
                            key={i}
                            className={cn('mini-bar', bar.on && 'on')}
                            style={{
                                height: bar.up,
                                opacity: bar.neg ? 0 : 1,
                            }}
                        />
                    ))}
                </div>
            </>
        );
    }

    if (module === 'Timesheets') {
        return (
            <>
                <div className="cf-card lp-stack lp-stack--sm">
                    <div className="cf-kv">
                        <span className="t-label-strong">
                            Rohan Desai · November 2026
                        </span>
                        <span className="cf-pill cf-pill--lime">
                            Month mode
                        </span>
                    </div>
                    <div className="cf-kv">
                        <span className="t-caption t-muted">Total hours</span>
                        <span className="t-money" style={{ fontSize: 22 }}>
                            57.33 h
                        </span>
                    </div>
                    <div className="cf-progress cf-progress--lime">
                        <span style={{ width: '68%' }} />
                    </div>
                    <span className="t-caption t-muted">
                        21 working days → 2.73 hrs/day · 25 days with Saturdays
                    </span>
                </div>
                {TIME_ROWS.map(([ini, name, hours]) => (
                    <div
                        key={name}
                        className="cf-card cf-kv"
                        style={{ alignItems: 'center' }}
                    >
                        <span className="cf-row" style={{ gap: 10 }}>
                            <span className="cf-avatar cf-avatar--sm cf-avatar--graphite">
                                {ini}
                            </span>
                            <span className="t-body">{name}</span>
                        </span>
                        <span className="t-money-sm">{hours}</span>
                    </div>
                ))}
            </>
        );
    }

    if (module === 'Settlements') {
        return (
            <>
                <div className="cf-card cf-card--graphite">
                    <span
                        className="t-caption"
                        style={{ color: 'var(--on-graphite-muted)' }}
                    >
                        To settle the sides
                    </span>
                    <div className="t-label-strong" style={{ marginTop: 4 }}>
                        Vertex Labs owes Bluefin Infotech
                    </div>
                    <div
                        className="t-money lp-figure-lime lp-figure-lime--lg"
                        style={{ marginTop: 4 }}
                    >
                        ₹3,24,600
                    </div>
                </div>
                <div className="lp-grid-2 lp-grid-tight">
                    {(
                        [
                            ['Vertex Labs holds', '₹12,45,775'],
                            ['Bluefin Infotech holds', '₹5,96,575'],
                        ] as const
                    ).map(([label, held]) => (
                        <div key={label} className="cf-card">
                            <span className="t-caption t-muted">{label}</span>
                            <div className="t-money" style={{ marginTop: 4 }}>
                                {held}
                            </div>
                            <span className="t-caption t-muted">
                                entitled ₹9,21,175
                            </span>
                        </div>
                    ))}
                </div>
            </>
        );
    }

    return (
        <>
            {VAULT_ROWS.map(([mono, name, sub, pill, state]) => (
                <div
                    key={name}
                    className="cf-card cf-kv"
                    style={{ alignItems: 'center' }}
                >
                    <span className="cf-row" style={{ gap: 10 }}>
                        <span
                            className="cf-avatar cf-avatar--tile"
                            style={{ fontFamily: 'var(--font-display)' }}
                        >
                            {mono}
                        </span>
                        <span className="cf-stack">
                            <span className="t-label-strong">{name}</span>
                            <span className="t-caption t-muted">{sub}</span>
                        </span>
                    </span>
                    <span className={cn('cf-pill cf-pill--sm', pill)}>
                        {state}
                    </span>
                </div>
            ))}
        </>
    );
}

/**
 * The public landing page (design/screens/Landing.dc.html, spec §21): hero with the 3D
 * tilting preview, depth-layered floating cards and cursor spotlight (D103), the client
 * strip (placeholder names, D104), the four pillars, module tabs, how it works,
 * security, plans with what each includes, FAQ and the closing call to action. Sections
 * reveal on scroll; all motion is off under reduced motion. Signed-in visitors go to
 * /home instead (D83).
 */
export default function Landing({ plans, seo }: Props) {
    const { reduced, pointerOn, hero, handlers, spot } = useHeroMotion();
    const [module, setModule] = useState<ModuleKey>('Dashboard');

    return (
        <>
            <SeoHead
                seo={seo}
                title="Run every company from one place"
                description="Coffer keeps the books, timesheets, partner equity, JV settlements and shared logins for all your companies together. Indian number formatting and April–March fiscal years."
            />

            <HeroMotionContext.Provider value={hero}>
                <section
                    className="lp-hero"
                    aria-labelledby="hero-h"
                    {...handlers}
                >
                    <HeroGlow reduced={reduced} />
                    {pointerOn && <HeroSpot {...spot} />}
                    <div className="lp-in lp-hero-copy">
                        <div className="lp-rise-group">
                            <div className="lp-rise">
                                <span className="lp-eyebrow">
                                    <span className="cf-dot-title" />
                                    For agencies, studios and joint ventures
                                </span>
                            </div>
                            <div className="lp-rise">
                                <h1 id="hero-h" className="lp-h1 lp-hero-title">
                                    Every company you run,{' '}
                                    <span className="lp-faint">
                                        in one quiet place.
                                    </span>
                                </h1>
                            </div>
                            <div className="lp-rise">
                                <p className="lp-lead lp-hero-lead">
                                    Coffer keeps the books, timesheets, partner
                                    equity and shared logins for all your
                                    companies together. Month-end stops being a
                                    week of spreadsheets.
                                </p>
                            </div>
                            <div className="lp-rise">
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
                                <p
                                    className="t-caption t-muted"
                                    style={{ margin: '16px 0 0' }}
                                >
                                    Indian number formatting · April to March
                                    fiscal years · single companies and JVs
                                </p>
                            </div>
                        </div>

                        <Reveal
                            distance={64}
                            fromScale={0.96}
                            delay={0.25}
                            immediate
                        >
                            <HeroStage />
                        </Reveal>
                    </div>
                </section>
            </HeroMotionContext.Provider>

            <section
                className="lp-in"
                style={{ paddingTop: 40, paddingBottom: 24 }}
                aria-labelledby="built-h"
            >
                <p
                    id="built-h"
                    className="t-caption t-muted"
                    style={{ textAlign: 'center', margin: '0 0 18px' }}
                >
                    Built for teams that run more than one set of books
                </p>
                <ul className="lp-grid-6" style={{ margin: 0, padding: 0 }}>
                    {SEGMENTS.map((segment) => (
                        <li key={segment} className="lp-logo">
                            {segment}
                        </li>
                    ))}
                </ul>
            </section>

            <section id="product" className="lp-sec" aria-labelledby="pl-h">
                <div className="lp-in">
                    <Reveal style={{ maxWidth: 720, marginBottom: 40 }}>
                        <span className="lp-eyebrow">What’s inside</span>
                        <h2
                            id="pl-h"
                            className="lp-h2"
                            style={{ marginTop: 16 }}
                        >
                            Four jobs that used to live in four tools.
                        </h2>
                    </Reveal>
                    <RevealGroup className="lp-grid-4">
                        {PILLARS.map((pillar) => (
                            <RevealItem
                                key={pillar.title}
                                className="lp-cell"
                                lift
                            >
                                <Link href={pillar.href} className="lp-pillar">
                                    <span className="lp-ic">
                                        <Icon icon={pillar.icon} size={22} />
                                    </span>
                                    <h3 className="lp-h3">{pillar.title}</h3>
                                    <p
                                        className="t-body t-muted"
                                        style={{ margin: 0 }}
                                    >
                                        {pillar.body}
                                    </p>
                                    <CheckList items={pillar.points} />
                                </Link>
                            </RevealItem>
                        ))}
                    </RevealGroup>
                </div>
            </section>

            <section
                id="modules"
                className="lp-sec lp-sec--flush"
                aria-labelledby="md-h"
            >
                <Reveal className="lp-in">
                    <Tabs
                        value={module}
                        onValueChange={(value) => setModule(value as ModuleKey)}
                    >
                        <SectionHead
                            id="md-h"
                            title="See each part the way your team will."
                            action={
                                <TabsList aria-label="Modules">
                                    {(Object.keys(MODULES) as ModuleKey[]).map(
                                        (key) => (
                                            <Tab key={key} value={key}>
                                                {key}
                                            </Tab>
                                        ),
                                    )}
                                </TabsList>
                            }
                        />
                        {(Object.keys(MODULES) as ModuleKey[]).map((key) => (
                            <TabPanel
                                key={key}
                                value={key}
                                className="lp-panel"
                                aria-label={key}
                            >
                                <div className="lp-panel-copy">
                                    <h3 className="lp-h3 lp-panel-title">
                                        {key}
                                    </h3>
                                    <p className="t-body t-muted lp-body-lg">
                                        {MODULES[key].body}
                                    </p>
                                    <CheckList items={MODULES[key].points} />
                                    <Link
                                        href={MODULES[key].href}
                                        className="cf-link"
                                        style={{ alignSelf: 'flex-start' }}
                                    >
                                        More about {key}
                                    </Link>
                                </div>
                                <Reveal
                                    key={module}
                                    className="lp-panel-demo"
                                    distance={12}
                                >
                                    <ModuleDemo module={key} />
                                </Reveal>
                            </TabPanel>
                        ))}
                    </Tabs>
                </Reveal>
            </section>

            <section className="lp-sec lp-sec--flush" aria-labelledby="hw-h">
                <div className="lp-in">
                    <Reveal>
                        <h2
                            id="hw-h"
                            className="lp-h2"
                            style={{ maxWidth: 640, marginBottom: 36 }}
                        >
                            Up and running in an afternoon.
                        </h2>
                    </Reveal>
                    <RevealGroup
                        as="ol"
                        className="lp-grid-3"
                        style={{ margin: 0, padding: 0, listStyle: 'none' }}
                        stagger={0.12}
                    >
                        {STEPS.map(([title, body], index) => (
                            <RevealItem
                                as="li"
                                key={title}
                                className="lp-pillar lp-card-lift"
                                style={{ gap: 12 }}
                                lift
                            >
                                <span className="lp-step-n">{index + 1}</span>
                                <h3 className="lp-h3">{title}</h3>
                                <p
                                    className="t-body t-muted"
                                    style={{ margin: 0 }}
                                >
                                    {body}
                                </p>
                            </RevealItem>
                        ))}
                    </RevealGroup>
                </div>
            </section>

            <section
                id="security"
                className="lp-in lp-pb"
                aria-labelledby="sec-h"
            >
                <Reveal
                    className="lp-band lp-band--sec cf-card--graphite"
                    distance={40}
                    fromScale={0.98}
                >
                    <div>
                        <span className="lp-eyebrow lp-eyebrow--dark">
                            Security
                        </span>
                        <h2
                            id="sec-h"
                            className="lp-h2 lp-on-dark"
                            style={{ marginTop: 16 }}
                        >
                            Your passwords and books, locked down.
                        </h2>
                        <p
                            className="t-body lp-on-dark-muted lp-body-lg"
                            style={{ marginTop: 16 }}
                        >
                            The same rules protect every company: encryption, a
                            second factor and a record of who did what.
                        </p>
                        <ButtonLink
                            href="/security"
                            variant="onDark"
                            style={{ marginTop: 20 }}
                        >
                            How Coffer protects your data
                        </ButtonLink>
                    </div>
                    <RevealGroup className="lp-grid-3 lp-grid-tight">
                        {SECURITY.map((item) => (
                            <RevealItem
                                key={item.title}
                                className="lp-sec-tile"
                                lift
                            >
                                <span className="lp-ic lp-ic--sm">
                                    <Icon icon={item.icon} size={18} />
                                </span>
                                <span className="t-label-strong lp-on-dark">
                                    {item.title}
                                </span>
                                <span className="t-caption lp-on-dark-muted">
                                    {item.body}
                                </span>
                            </RevealItem>
                        ))}
                    </RevealGroup>
                </Reveal>
            </section>

            <section className="lp-in lp-pb" aria-labelledby="pr-h">
                <Reveal>
                    <SectionHead
                        id="pr-h"
                        width={600}
                        title="A plan for one company or a whole group."
                        action={
                            <ButtonLink
                                href="/pricing"
                                variant="secondary"
                                size="lg"
                            >
                                Compare every plan
                            </ButtonLink>
                        }
                    />
                </Reveal>
                <RevealGroup className="lp-grid-4">
                    {plans.map((plan) => {
                        return (
                            <RevealItem
                                as="article"
                                key={plan.key}
                                className={cn(
                                    'lp-pillar lp-card-lift',
                                    plan.popular && 'lp-pillar--pop',
                                )}
                                aria-labelledby={`lp-plan-${plan.key}`}
                                lift
                            >
                                <div
                                    className="cf-kv"
                                    style={{ alignItems: 'center' }}
                                >
                                    <h3
                                        id={`lp-plan-${plan.key}`}
                                        className="lp-h3"
                                    >
                                        {plan.name}
                                    </h3>
                                    {plan.popular && (
                                        <span className="cf-pill cf-pill--graphite">
                                            Popular
                                        </span>
                                    )}
                                </div>
                                <p
                                    className="t-body plan-dim"
                                    style={{ margin: 0 }}
                                >
                                    {PLAN_BODY[plan.key] ?? plan.tagline}
                                </p>
                                <div
                                    className="cf-row"
                                    style={{ gap: 6, alignItems: 'baseline' }}
                                >
                                    <span className="t-money lp-price">
                                        {plan.customPricing
                                            ? 'Custom'
                                            : planPrice(
                                                  plan.prices.monthly.inr,
                                                  'inr',
                                              )}
                                    </span>
                                    {!plan.customPricing && (
                                        <span className="t-caption plan-dim">
                                            / month
                                        </span>
                                    )}
                                </div>
                                <Link
                                    href={
                                        plan.customPricing
                                            ? '/contact?topic=sales'
                                            : `/register?plan=${plan.key}`
                                    }
                                    className={buttonClass({
                                        variant: plan.popular
                                            ? 'primary'
                                            : 'secondary',
                                        className: 'mt-auto',
                                    })}
                                >
                                    {plan.customPricing
                                        ? 'Talk to us'
                                        : 'Start free trial'}
                                </Link>
                            </RevealItem>
                        );
                    })}
                </RevealGroup>
            </section>

            <section
                id="faq"
                className="lp-in lp-pb lp-faq-wrap"
                aria-labelledby="fq-h"
            >
                <Reveal>
                    <h2 id="fq-h" className="lp-h2">
                        Questions, answered.
                    </h2>
                    <p
                        className="t-body t-muted lp-body-lg"
                        style={{ marginTop: 16 }}
                    >
                        Still unsure?{' '}
                        <Link href="/contact?topic=demo" className="cf-link">
                            Book a demo
                        </Link>{' '}
                        and we’ll walk through your setup.
                    </p>
                </Reveal>
                <Reveal delay={0.1}>
                    <FaqList faqs={FAQS} />
                </Reveal>
            </section>

            <CtaBand id="demo" />
        </>
    );
}
