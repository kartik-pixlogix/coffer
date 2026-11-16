import type { StrengthMeter } from '@/lib/password-strength';

/**
 * "Continue with Google" (secondary pill with a G monogram). A plain link: Socialite
 * leaves the app for Google's consent screen, so it can't be an Inertia visit.
 */
export function GoogleButton({
    href,
    children,
}: {
    href: string;
    children: string;
}) {
    return (
        <a href={href} className="cf-btn cf-btn--secondary cf-btn--lg w-full">
            <span
                className="cf-avatar cf-avatar--sm auth-google"
                aria-hidden="true"
            >
                G
            </span>
            {children}
        </a>
    );
}

/** "— or with email —" between the Google button and the form. */
export function OrDivider({ label = 'or with email' }: { label?: string }) {
    return (
        <div className="flex items-center gap-3" aria-hidden="true">
            <span className="cf-divider flex-1" />
            <span className="t-caption t-muted">{label}</span>
            <span className="cf-divider flex-1" />
        </div>
    );
}

/** The four `cf-meter-dots` under a new-password field. */
export function MeterDots({ meter }: { meter: StrengthMeter }) {
    return (
        <div className="cf-meter-dots" aria-hidden="true">
            {meter.dots.map((colour, index) => (
                <span key={index} style={{ background: colour }} />
            ))}
        </div>
    );
}

/** The strength word in the label row, coloured like the design. */
export function StrengthLabel({ meter }: { meter: StrengthMeter }) {
    if (meter.label === '') {
        return null;
    }

    return (
        <span
            className="t-caption"
            style={{ color: meter.ink, fontWeight: 600 }}
            aria-live="polite"
        >
            {meter.label}
        </span>
    );
}
