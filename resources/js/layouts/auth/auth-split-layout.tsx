import { Link } from '@inertiajs/react';
import { ArrowLeftRight, Moon, Sun } from 'lucide-react';
import type { ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { useTheme } from '@/hooks/use-theme';
import { welcome } from '@/routes';

type Prompt = { text: string; label: string; href: string };

/** What an auth page passes as `Page.layout = {…}`. */
export type AuthLayoutProps = {
    /** Which lime panel: the ledger preview (sign in) or the three set-up steps (sign up). */
    aside?: 'signin' | 'signup';
    /** Top-right line, e.g. "New to Coffer? Start free trial". */
    prompt?: Prompt;
    /** Width of the form column: 420 (sign in) or 440 (sign up). */
    width?: 420 | 440;
};

type Props = AuthLayoutProps & { children: ReactNode };

/** Light/dark toggle for guest pages: applies and remembers locally (no account yet). */
export function GuestThemeToggle() {
    const { theme, setTheme } = useTheme();
    const label =
        theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme';

    return (
        <IconButton
            icon={theme === 'dark' ? Sun : Moon}
            aria-label={label}
            title={label}
            onClick={() =>
                setTheme(theme === 'dark' ? 'light' : 'dark', {
                    persist: false,
                })
            }
        />
    );
}

/** The Coffer wordmark with the C tile, linking home. */
export function AuthWordmark() {
    return (
        <Link href={welcome()} className="cf-wordmark auth-wordmark">
            <span className="cf-rail-logo" aria-hidden="true">
                C
            </span>
            Coffer
        </Link>
    );
}

function SignInAside() {
    return (
        <aside aria-label="About Coffer" className="cf-card--lime auth-aside">
            <div aria-hidden="true" className="auth-blob auth-blob--tr" />
            <p className="t-display auth-hero">
                Every company, partner and password in one ledger.
            </p>
            <div data-theme="light" className="cf auth-preview">
                <div className="cf-card cf-card--white flex flex-col gap-2.5">
                    <div className="cf-kv">
                        <span className="t-label-strong">Current balance</span>
                        <span className="t-caption t-muted">
                            Saarthi Portal JV · FY 2026-27
                        </span>
                    </div>
                    <div className="flex items-baseline gap-1.5">
                        <span className="t-money-unit auth-rupee">₹</span>
                        <span className="t-figure-hero">18,42,350</span>
                    </div>
                    <div className="t-body t-muted">
                        <span className="t-money">₹62,10,000</span> income −{' '}
                        <span className="t-money">₹43,67,650</span> expenses
                    </div>
                </div>
                <div className="cf-alert cf-alert--graphite cf-alert--slim">
                    <div className="cf-alert-main" style={{ flexBasis: 200 }}>
                        <span className="cf-alert-ic">
                            <Icon icon={ArrowLeftRight} size={16} />
                        </span>
                        <span className="t-body">
                            Vertex Labs owes Bluefin Infotech{' '}
                            <span className="t-money">₹3,24,600</span>
                        </span>
                    </div>
                </div>
            </div>
            <ul className="auth-pills">
                <li className="cf-pill auth-pill">
                    Multi-company and JV books
                </li>
                <li className="cf-pill auth-pill">
                    Encrypted credentials vault
                </li>
                <li className="cf-pill auth-pill">Audit log on every reveal</li>
            </ul>
        </aside>
    );
}

const STEPS = [
    [
        'Create your company',
        'Single company or a joint venture, with your opening balance.',
    ],
    [
        'Add partners and equity',
        'Coffer checks the split adds up to 100% before you go on.',
    ],
    ['Invite your team', 'Members log hours and expenses; partners approve.'],
] as const;

function SignUpAside() {
    return (
        <aside
            aria-label="What happens next"
            className="cf-card--lime auth-aside"
        >
            <div aria-hidden="true" className="auth-blob auth-blob--bl" />
            <p className="t-display auth-hero">
                Your books, partners and passwords, set up in three steps.
            </p>
            <ol className="auth-steps">
                {STEPS.map(([title, body], index) => (
                    <li key={title} className="auth-step">
                        <span className="cf-step-n auth-step-n">
                            {index + 1}
                        </span>
                        <span>
                            <span className="t-label-strong block">
                                {title}
                            </span>
                            <span className="t-body">{body}</span>
                        </span>
                    </li>
                ))}
            </ol>
        </aside>
    );
}

/**
 * Sign-in / sign-up frame (design/screens/SignIn.dc.html, SignUp.dc.html): the form
 * column on the left with the wordmark and footer, the lime panel on the right.
 * Forgot / reset password, email verification and password confirmation reuse it.
 */
export default function AuthSplitLayout({
    aside = 'signin',
    prompt,
    width = 420,
    children,
}: Props) {
    return (
        <div className="cf-page">
            <div className="auth-frame">
                <main className="auth-main">
                    <div className="auth-head">
                        <AuthWordmark />
                        <div className="flex items-center gap-3">
                            {prompt && (
                                <span className="t-body t-muted">
                                    {prompt.text}{' '}
                                    <Link
                                        href={prompt.href}
                                        className="font-medium"
                                    >
                                        {prompt.label}
                                    </Link>
                                </span>
                            )}
                            <GuestThemeToggle />
                        </div>
                    </div>

                    <div className="auth-col" style={{ maxWidth: width }}>
                        {children}
                    </div>

                    <p className="t-caption t-faint m-0">
                        © 2026 Coffer ·{' '}
                        <a href="/terms" className="t-faint">
                            Terms
                        </a>{' '}
                        ·{' '}
                        <a href="/privacy" className="t-faint">
                            Privacy
                        </a>
                    </p>
                </main>

                {aside === 'signin' ? <SignInAside /> : <SignUpAside />}
            </div>
        </div>
    );
}
