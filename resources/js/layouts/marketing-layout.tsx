import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, Menu, Moon, Sun, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { buttonClass, ButtonLink } from '@/components/ui/button';
import { IconButton } from '@/components/ui/icon-button';
import { useTheme } from '@/hooks/use-theme';

type NavLink = { label: string; href: string; page?: string };

/** The site nav of Landing.dc.html / Pricing.dc.html. Hash links scroll the landing page. */
const NAV: NavLink[] = [
    { label: 'Product', href: '/#product' },
    { label: 'Modules', href: '/#modules' },
    { label: 'Security', href: '/security', page: '/security' },
    { label: 'Pricing', href: '/pricing', page: '/pricing' },
    { label: 'FAQ', href: '/#faq' },
];

type FootColumn = { title: string; links: { label: string; href: string }[] };

/** Footer columns; only links with a page behind them (D83). */
const FOOTER: FootColumn[] = [
    {
        title: 'Product',
        links: [
            { label: 'Features', href: '/#product' },
            { label: 'Modules', href: '/#modules' },
            { label: 'Pricing', href: '/pricing' },
            { label: 'Security', href: '/security' },
        ],
    },
    {
        title: 'Features',
        links: [
            { label: 'Finance', href: '/features/finance' },
            { label: 'Timesheets', href: '/features/timesheets' },
            { label: 'Vault', href: '/features/vault' },
            { label: 'Reports', href: '/features/reports' },
        ],
    },
    {
        title: 'Company',
        links: [
            { label: 'Contact', href: '/contact?topic=sales' },
            { label: 'Book a demo', href: '/contact?topic=demo' },
            { label: 'Sign in', href: '/login' },
        ],
    },
    {
        title: 'Legal',
        links: [
            { label: 'Privacy', href: '/privacy' },
            { label: 'Terms', href: '/terms' },
            { label: 'Cookies', href: '/cookies' },
            { label: 'Data processing', href: '/dpa' },
        ],
    },
];

const isHash = (href: string) => href.includes('#');

/** An in-site link: Inertia for pages, a plain anchor for landing-page sections. */
function SiteLink({
    href,
    className,
    current,
    onClick,
    children,
}: {
    href: string;
    className?: string;
    current?: boolean;
    onClick?: () => void;
    children: ReactNode;
}) {
    const props = {
        className,
        'aria-current': current ? ('page' as const) : undefined,
        onClick,
    };

    return isHash(href) ? (
        <a href={href} {...props}>
            {children}
        </a>
    ) : (
        <Link href={href} {...props}>
            {children}
        </Link>
    );
}

export function Wordmark() {
    return (
        <Link href="/" className="lp-brand" aria-label="Coffer home">
            <span className="cf-rail-logo" aria-hidden="true">
                C
            </span>
            <span className="cf-wordmark">Coffer</span>
        </Link>
    );
}

function ThemeToggle({ signedIn }: { signedIn: boolean }) {
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
                    persist: signedIn,
                })
            }
        />
    );
}

function Header() {
    const { url, props } = usePage();
    const signedIn = props.auth.user !== null;
    const path = url.split('?')[0]?.split('#')[0] ?? '/';
    const [menuOpen, setMenuOpen] = useState(false);

    // A visit (or Escape) closes the phone menu.
    useEffect(() => setMenuOpen(false), [url]);
    useEffect(() => {
        if (!menuOpen) {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setMenuOpen(false);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [menuOpen]);

    return (
        <div className="lp-in">
            <header className="lp-nav">
                <Wordmark />
                <nav className="lp-links" aria-label="Site">
                    {NAV.map((link) => (
                        <SiteLink
                            key={link.label}
                            href={link.href}
                            current={link.page === path}
                        >
                            {link.label}
                        </SiteLink>
                    ))}
                </nav>
                <div className="lp-actions">
                    <ThemeToggle signedIn={signedIn} />
                    {signedIn ? (
                        <ButtonLink
                            href="/home"
                            variant="primary"
                            className="lp-cta-sm"
                        >
                            Open Coffer
                        </ButtonLink>
                    ) : (
                        <>
                            <ButtonLink
                                href="/login"
                                variant="ghost"
                                className="lp-cta-wide"
                            >
                                Sign in
                            </ButtonLink>
                            <ButtonLink
                                href="/register"
                                variant="primary"
                                className="lp-cta-sm"
                            >
                                Start free trial
                            </ButtonLink>
                        </>
                    )}
                    <IconButton
                        icon={menuOpen ? X : Menu}
                        className="lp-menu-btn"
                        aria-label={menuOpen ? 'Close menu' : 'Open menu'}
                        aria-expanded={menuOpen}
                        aria-controls="lp-menu"
                        onClick={() => setMenuOpen(!menuOpen)}
                    />
                </div>
                {menuOpen && (
                    <nav id="lp-menu" className="lp-menu" aria-label="Menu">
                        {NAV.map((link) => (
                            <SiteLink
                                key={link.label}
                                href={link.href}
                                current={link.page === path}
                                onClick={() => setMenuOpen(false)}
                            >
                                {link.label}
                            </SiteLink>
                        ))}
                        <div className="lp-menu-ctas">
                            {signedIn ? (
                                <Link
                                    href="/home"
                                    className={buttonClass({
                                        variant: 'primary',
                                        className: 'col-span-2',
                                    })}
                                >
                                    Open Coffer
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href="/login"
                                        className={buttonClass({
                                            variant: 'secondary',
                                        })}
                                    >
                                        Sign in
                                    </Link>
                                    <Link
                                        href="/register"
                                        className={buttonClass({
                                            variant: 'primary',
                                        })}
                                    >
                                        Start free trial
                                    </Link>
                                </>
                            )}
                        </div>
                    </nav>
                )}
            </header>
        </div>
    );
}

function Footer() {
    return (
        <footer className="lp-in lp-foot">
            <div className="lp-foot-grid">
                <div className="cf-stack" style={{ gap: 12 }}>
                    <span className="cf-row" style={{ gap: 10 }}>
                        <span className="cf-rail-logo" aria-hidden="true">
                            C
                        </span>
                        <span className="cf-wordmark" style={{ margin: 0 }}>
                            Coffer
                        </span>
                    </span>
                    <span
                        className="t-caption t-muted"
                        style={{ maxWidth: 260 }}
                    >
                        Books, hours, equity and shared logins for every company
                        you run.
                    </span>
                    <ButtonLink
                        href="/register"
                        variant="secondary"
                        size="sm"
                        disc={ArrowRight}
                        className="self-start"
                    >
                        Start free trial
                    </ButtonLink>
                </div>
                {FOOTER.map((column) => (
                    <nav
                        key={column.title}
                        aria-label={column.title}
                        className="cf-stack"
                        style={{ gap: 10 }}
                    >
                        <span className="t-label-strong">{column.title}</span>
                        {column.links.map((link) => (
                            <SiteLink
                                key={link.label}
                                href={link.href}
                                className="t-body"
                            >
                                {link.label}
                            </SiteLink>
                        ))}
                    </nav>
                ))}
            </div>
            <div className="lp-foot-base">
                <span className="t-caption t-muted">© 2026 Coffer</span>
                <span className="cf-row" style={{ gap: 16 }}>
                    <Link href="/privacy" className="t-caption">
                        Privacy
                    </Link>
                    <Link href="/terms" className="t-caption">
                        Terms
                    </Link>
                    <Link href="/cookies" className="t-caption">
                        Cookies
                    </Link>
                </span>
            </div>
        </footer>
    );
}

/**
 * The marketing frame (Landing.dc.html): site header with nav, Sign in and Start free
 * trial (a menu at phone width), the page, and the footer. Every page sets its own
 * <SeoHead>.
 */
export default function MarketingLayout({ children }: { children: ReactNode }) {
    return (
        <div className="lp">
            <Header />
            <main id="main">{children}</main>
            <Footer />
        </div>
    );
}
