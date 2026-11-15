import { Check, Copy, Eye, EyeOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { CSSProperties, InputHTMLAttributes, ReactNode, Ref } from 'react';
import { Button, buttonClass } from '@/components/ui/button';
import { Field, useFieldIds } from '@/components/ui/field';
import type { FieldMessageProps } from '@/components/ui/field';
import { Icon, Spinner } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { cn } from '@/lib/utils';

/* -------------------------------------------------------- sign-in variant */

export type PasswordFieldProps = FieldMessageProps & {
    label?: ReactNode;
    /** Show the eye button that toggles the password between hidden and shown (default true). */
    revealable?: boolean;
    wrapClassName?: string;
    fieldClassName?: string;
    ref?: Ref<HTMLInputElement>;
} & Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'size'>;

/** Your own password (sign-in, set password): `cf-inwrap` with a show/hide eye. */
export function PasswordField({
    label,
    help,
    error,
    revealable = true,
    wrapClassName,
    fieldClassName,
    id: idProp,
    disabled,
    autoComplete = 'current-password',
    'aria-describedby': describedByProp,
    ref,
    ...rest
}: PasswordFieldProps) {
    const ids = useFieldIds(idProp, { help, error }, describedByProp);
    const [shown, setShown] = useState(false);

    return (
        <Field
            ids={ids}
            label={label}
            help={help}
            error={error}
            className={fieldClassName}
        >
            <span
                className={cn(
                    'cf-inwrap',
                    ids.invalid && 'is-error',
                    disabled && 'is-disabled',
                    wrapClassName,
                )}
            >
                <input
                    ref={ref}
                    id={ids.id}
                    type={shown ? 'text' : 'password'}
                    autoComplete={autoComplete}
                    disabled={disabled}
                    aria-invalid={ids.invalid || undefined}
                    aria-describedby={ids.describedBy}
                    {...rest}
                />
                {revealable && (
                    <IconButton
                        icon={shown ? EyeOff : Eye}
                        size="sm"
                        variant="bare"
                        iconSize={16}
                        aria-label={shown ? 'Hide password' : 'Show password'}
                        disabled={disabled}
                        onClick={() => setShown((value) => !value)}
                    />
                )}
            </span>
        </Field>
    );
}

/* ----------------------------------------------------------- vault variant */

/** Seconds a revealed secret stays on screen, and a copied one in the clipboard. */
export const VAULT_REVEAL_SECONDS = 30;
const COPIED_MS = 2000;
const MASK = '••••••••••••••';

const SECRET_STYLE: CSSProperties = {
    flex: 1,
    minWidth: 0,
    fontFamily: 'var(--font-mono)',
    fontSize: 14,
    overflowWrap: 'anywhere',
};

export type VaultState = 'masked' | 'verifying' | 'shown';

export type VaultSecretFieldProps = FieldMessageProps & {
    /** "Password", "API key"… */
    label: ReactNode;
    /** Right of the label while masked, e.g. "Last changed 28 Sep 2026". */
    meta?: ReactNode;
    /**
     * Fetch the plaintext after the 2FA step-up (the server audit-logs it). Resolve with
     * the secret; reject to stay masked (cancelled or wrong code).
     */
    onReveal: () => Promise<string>;
    /**
     * Fetch the secret for Copy when it isn't revealed yet (defaults to onReveal), so a
     * masked secret can be copied without showing it.
     */
    onRequestCopy?: () => Promise<string>;
    /** Called after the secret was written to the clipboard (for the audit log). */
    onCopy?: () => void;
    /** What the copy button names: "Copy password". */
    copyLabel?: string;
    revealLabel?: string;
    disabled?: boolean;
    id?: string;
    className?: string;
};

/**
 * A stored vault secret, masked, with Reveal (after a step-up) and Copy. A revealed
 * secret hides again after 30 s; a copied one is cleared from the clipboard after 30 s
 * when the browser lets us check it is still ours. Never put the secret in props.
 */
export function VaultSecretField({
    label,
    meta,
    onReveal,
    onRequestCopy,
    onCopy,
    copyLabel = 'Copy password',
    revealLabel = 'Reveal',
    help,
    error,
    disabled = false,
    id: idProp,
    className,
}: VaultSecretFieldProps) {
    const [state, setState] = useState<VaultState>('masked');
    const [secret, setSecret] = useState<string | null>(null);
    const [secondsLeft, setSecondsLeft] = useState(VAULT_REVEAL_SECONDS);
    const [copied, setCopied] = useState(false);
    const [announcement, setAnnouncement] = useState('');

    // Count down while shown, then mask again and forget the plaintext.
    useEffect(() => {
        if (state !== 'shown') {
            return;
        }

        const started = Date.now();
        const timer = window.setInterval(() => {
            const left =
                VAULT_REVEAL_SECONDS -
                Math.floor((Date.now() - started) / 1000);

            if (left <= 0) {
                setState('masked');
                setSecret(null);
                setSecondsLeft(VAULT_REVEAL_SECONDS);
                setAnnouncement('Hidden again.');
            } else {
                setSecondsLeft(left);
            }
        }, 1000);

        return () => window.clearInterval(timer);
    }, [state]);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timer = window.setTimeout(() => setCopied(false), COPIED_MS);

        return () => window.clearTimeout(timer);
    }, [copied]);

    const hide = () => {
        setState('masked');
        setSecret(null);
        setSecondsLeft(VAULT_REVEAL_SECONDS);
    };

    const reveal = async () => {
        setState('verifying');

        try {
            const value = await onReveal();
            setSecret(value);
            setSecondsLeft(VAULT_REVEAL_SECONDS);
            setState('shown');
            setAnnouncement(`Shown. Hides in ${VAULT_REVEAL_SECONDS} seconds.`);
        } catch {
            setState('masked');
        }
    };

    const copy = async () => {
        try {
            const value = secret ?? (await (onRequestCopy ?? onReveal)());
            await navigator.clipboard.writeText(value);
            setCopied(true);
            setAnnouncement(
                'Copied. Cleared from the clipboard in 30 seconds.',
            );
            onCopy?.();
            scheduleClipboardClear(value);
        } catch {
            setAnnouncement('Couldn’t copy. Try again.');
        }
    };

    return (
        <VaultSecretView
            id={idProp}
            label={label}
            meta={meta}
            state={state}
            secret={secret}
            secondsLeft={secondsLeft}
            copied={copied}
            announcement={announcement}
            copyLabel={copyLabel}
            revealLabel={revealLabel}
            help={help}
            error={error}
            disabled={disabled}
            className={className}
            onReveal={reveal}
            onHide={hide}
            onCopy={copy}
        />
    );
}

export type VaultSecretViewProps = FieldMessageProps & {
    label: ReactNode;
    meta?: ReactNode;
    state: VaultState;
    /** Plaintext, only while `state` is 'shown'. */
    secret?: string | null;
    secondsLeft?: number;
    copied?: boolean;
    /** Polite live-region text ("Copied…", "Hidden again."). */
    announcement?: string;
    copyLabel?: string;
    revealLabel?: string;
    disabled?: boolean;
    id?: string;
    className?: string;
    /** Class for the `cf-inwrap` (doc states: is-focus). */
    wrapClassName?: string;
    onReveal?: () => void;
    onHide?: () => void;
    onCopy?: () => void;
};

/**
 * The markup of VaultSecretField for a given state, without timers or fetching.
 * Use VaultSecretField in pages; this exists for previews and custom flows.
 */
export function VaultSecretView({
    label,
    meta,
    state,
    secret = null,
    secondsLeft = VAULT_REVEAL_SECONDS,
    copied = false,
    announcement = '',
    copyLabel = 'Copy password',
    revealLabel = 'Reveal',
    help,
    error,
    disabled = false,
    id: idProp,
    className,
    wrapClassName,
    onReveal,
    onHide,
    onCopy,
}: VaultSecretViewProps) {
    const ids = useFieldIds(idProp, { help, error });
    const shown = state === 'shown' && secret !== null;

    return (
        <Field
            ids={ids}
            label={label}
            labelAs="span"
            aside={shown ? `Hides in ${secondsLeft} s` : meta}
            help={help}
            error={error}
            className={className}
        >
            <div
                className={cn(
                    'cf-inwrap',
                    state === 'verifying' && 'is-readonly',
                    ids.invalid && 'is-error',
                    disabled && 'is-disabled',
                    wrapClassName,
                )}
                role="group"
                aria-labelledby={`${ids.id}-label`}
                aria-describedby={ids.describedBy}
                aria-busy={state === 'verifying' || undefined}
            >
                <span
                    style={{
                        ...SECRET_STYLE,
                        letterSpacing: shown ? '.02em' : '.12em',
                    }}
                >
                    {shown ? (
                        secret
                    ) : (
                        <>
                            <span aria-hidden="true">{MASK}</span>
                            <span className="cf-sr">Hidden</span>
                        </>
                    )}
                </span>
                {state === 'verifying' ? (
                    <span
                        className={buttonClass({
                            variant: 'secondary',
                            size: 'sm',
                            loading: true,
                        })}
                    >
                        <Spinner />
                        Verifying
                    </span>
                ) : (
                    <>
                        <Button
                            size="sm"
                            disabled={disabled}
                            onClick={shown ? onHide : onReveal}
                        >
                            <Icon icon={shown ? EyeOff : Eye} size={15} />
                            {shown ? 'Hide' : revealLabel}
                        </Button>
                        <IconButton
                            icon={copied ? Check : Copy}
                            size="sm"
                            variant="bare"
                            iconSize={16}
                            className={copied ? 'is-selected' : undefined}
                            aria-label={copied ? 'Copied' : copyLabel}
                            disabled={disabled}
                            onClick={onCopy}
                        />
                    </>
                )}
            </div>
            <span className="cf-sr" role="status">
                {announcement}
            </span>
        </Field>
    );
}

/**
 * Best effort: after 30 s, empty the clipboard if it still holds this secret. Browsers
 * that don't let us read the clipboard are left alone, so we never wipe something the
 * person copied since.
 */
function scheduleClipboardClear(value: string): void {
    window.setTimeout(async () => {
        try {
            if ((await navigator.clipboard.readText()) === value) {
                await navigator.clipboard.writeText('');
            }
        } catch {
            // Not allowed to read the clipboard: leave it.
        }
    }, VAULT_REVEAL_SECONDS * 1000);
}
