import { useEffect, useState } from 'react';
import type { HTMLAttributes } from 'react';
import { TOTP_RING_LENGTH, ringOffset } from '@/components/ui/chart-utils';
import { cn } from '@/lib/utils';

/** The last seconds of a code turn the ring warning. */
export const TOTP_WARN_SECONDS = 5;

/**
 * Seconds left in the current TOTP window: until `expiresAt` (epoch ms or Date) when
 * given, else in the wall-clock window of `period` seconds (RFC 6238 counts from the
 * Unix epoch). Always 1…period.
 */
export function totpSecondsLeft(
    now: number,
    period = 30,
    expiresAt?: number | Date,
): number {
    if (expiresAt !== undefined) {
        const end = expiresAt instanceof Date ? expiresAt.getTime() : expiresAt;

        return Math.min(period, Math.max(0, Math.ceil((end - now) / 1000)));
    }

    return period - (Math.floor(now / 1000) % period);
}

/** Live countdown for a TOTP code, ticking every 250 ms so it never lags a second. */
export function useTotpCountdown(
    period = 30,
    expiresAt?: number | Date,
): number {
    const expiry = expiresAt instanceof Date ? expiresAt.getTime() : expiresAt;
    const [left, setLeft] = useState(() =>
        totpSecondsLeft(Date.now(), period, expiry),
    );

    useEffect(() => {
        const tick = () => setLeft(totpSecondsLeft(Date.now(), period, expiry));
        tick();
        const timer = window.setInterval(tick, 250);

        return () => window.clearInterval(timer);
    }, [period, expiry]);

    return left;
}

export type TotpRingProps = {
    /** Controlled: seconds left. Leave out to run the internal timer. */
    secondsLeft?: number;
    /** Code lifetime in seconds. */
    period?: number;
    /** When the current code expires (internal timer only). */
    expiresAt?: number | Date;
    /** `dark` = lime bar on graphite; `light` = ink bar on surface. */
    tone?: 'dark' | 'light';
    /** Rendered size in px (48 in panels, 40 inline). */
    size?: number;
    className?: string;
} & Omit<HTMLAttributes<HTMLSpanElement>, 'role' | 'children'>;

function RingView({
    left,
    period,
    tone,
    size,
    className,
    ...rest
}: {
    left: number;
    period: number;
    tone: 'dark' | 'light';
    size: number;
    className?: string;
} & Omit<HTMLAttributes<HTMLSpanElement>, 'role' | 'children'>) {
    const offset = ringOffset(TOTP_RING_LENGTH, left / period);
    // A fresh code snaps back to full instead of sweeping backwards.
    const resetting = left >= period;

    return (
        <span
            className={cn(
                'cf-ring',
                tone === 'dark' ? 'cf-ring--dark' : 'cf-ring--onlight',
                left <= TOTP_WARN_SECONDS && 'is-warn',
                className,
            )}
            role="timer"
            aria-label={`${left} ${left === 1 ? 'second' : 'seconds'} left`}
            {...rest}
        >
            <svg
                width={size}
                height={size}
                viewBox="0 0 48 48"
                aria-hidden="true"
            >
                <circle
                    className="cf-ring-track"
                    cx="24"
                    cy="24"
                    r="20"
                    strokeWidth="4"
                />
                <circle
                    className="cf-ring-bar"
                    cx="24"
                    cy="24"
                    r="20"
                    strokeWidth="4"
                    strokeDasharray={TOTP_RING_LENGTH}
                    strokeDashoffset={offset}
                    transform="rotate(-90 24 24)"
                    style={resetting ? { transition: 'none' } : undefined}
                />
            </svg>
            <span
                className="cf-ring-label t-money-sm"
                style={tone === 'dark' ? { fontSize: 13 } : undefined}
            >
                {left}
            </span>
        </span>
    );
}

function LiveRing({
    period,
    expiresAt,
    ...rest
}: Omit<TotpRingProps, 'secondsLeft' | 'tone' | 'size'> & {
    period: number;
    tone: 'dark' | 'light';
    size: number;
}) {
    const left = useTotpCountdown(period, expiresAt);

    return <RingView left={left} period={period} {...rest} />;
}

/**
 * TOTP countdown ring (`cf-ring`, `role="timer"`): how long the code beside it stays
 * valid. Under reduced motion the ring steps once a second without easing.
 */
export function TotpRing({
    secondsLeft,
    period = 30,
    expiresAt,
    tone = 'light',
    size = 40,
    ...rest
}: TotpRingProps) {
    if (secondsLeft !== undefined) {
        return (
            <RingView
                left={Math.max(0, Math.min(period, Math.round(secondsLeft)))}
                period={period}
                tone={tone}
                size={size}
                {...rest}
            />
        );
    }

    return (
        <LiveRing
            period={period}
            expiresAt={expiresAt}
            tone={tone}
            size={size}
            {...rest}
        />
    );
}

/** "482613" → "482 613"; 8-digit codes split 4 + 4. */
export function splitTotpCode(code: string): string {
    const digits = code.replace(/\s+/g, '');
    const half = Math.ceil(digits.length / 2);

    return digits.length < 4
        ? digits
        : `${digits.slice(0, half)} ${digits.slice(half)}`;
}

export type TotpCodeProps = {
    code: string;
    className?: string;
};

/** A one-time code in `t-code` (IBM Plex Mono), split 3 + 3. */
export function TotpCode({ code, className }: TotpCodeProps) {
    return (
        <span className={cn('t-code', className)}>{splitTotpCode(code)}</span>
    );
}
