import { router } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    Download,
    KeyRound,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Button, TextButton } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { CodeInput } from '@/components/ui/code-input';
import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import { PasswordField } from '@/components/ui/password-field';
import { useToast } from '@/components/ui/toast';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    replace as replaceTwoFactor,
    start as startTwoFactor,
} from '@/routes/profile/two-factor';
import { destroy as forgetDevices } from '@/routes/profile/trusted-devices';
import {
    confirm as confirmTwoFactor,
    disable as disableTwoFactor,
    regenerateRecoveryCodes,
} from '@/routes/two-factor';

export type TwoFactorProps = {
    enabled: boolean;
    /** A secret exists but hasn't been confirmed with a code yet. */
    pending: boolean;
    confirmedAt: string | null;
    qrSvg: string | null;
    secret: string | null;
    /** Shown while setting up, and once after "Make new recovery codes". */
    recoveryCodes: string[] | null;
    codesLeft: number;
    trustedDevices: number;
    /** Admins can't turn 2FA off (spec §23). */
    required: boolean;
};

/** "JBSWY3DPEHPK3PXP" → "JBSW Y3DP EHPK 3PXP" for reading off the screen. */
const spaced = (secret: string) => secret.replace(/(.{4})(?=.)/g, '$1 ');

function RecoveryCodes({
    codes,
    onCopy,
}: {
    codes: string[];
    onCopy: () => void;
}) {
    const download = () => {
        const blob = new Blob(
            [`Coffer recovery codes\n\n${codes.join('\n')}\n`],
            { type: 'text/plain' },
        );
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'coffer-recovery-codes.txt';
        link.click();
        URL.revokeObjectURL(link.href);
    };

    return (
        <>
            <div className="cf-inset recovery-grid">
                {codes.map((code) => (
                    <span key={code} className="rc">
                        {code}
                    </span>
                ))}
            </div>
            <div className="cf-row flex-wrap gap-2">
                <Button
                    variant="secondary"
                    size="sm"
                    icon={Download}
                    onClick={download}
                >
                    Download
                </Button>
                <Button
                    variant="secondary"
                    size="sm"
                    onClick={() =>
                        void navigator.clipboard
                            ?.writeText(codes.join('\n'))
                            .then(onCopy)
                    }
                >
                    Copy
                </Button>
                <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => window.print()}
                >
                    Print
                </Button>
            </div>
        </>
    );
}

function SetUp({
    twoFactor,
    email,
}: {
    twoFactor: TwoFactorProps;
    email: string;
}) {
    const [code, setCode] = useState('');
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const [checking, setChecking] = useState(false);
    const [copiedKey, setCopiedKey] = useState(false);
    const toast = useToast();

    const turnOn = () =>
        router.post(
            confirmTwoFactor().url,
            { code },
            {
                preserveScroll: true,
                errorBag: 'confirmTwoFactorAuthentication',
                onStart: () => setChecking(true),
                onFinish: () => setChecking(false),
                onSuccess: () =>
                    toast.success('Two-factor authentication is on'),
                onError: () =>
                    setError(
                        'That code didn’t match. Check the time on your phone and try the next one.',
                    ),
            },
        );

    return (
        <>
            <p className="t-body t-muted m-0">
                Coffer asks for a code when you sign in, unlock the vault or
                reveal a password. Use any authenticator app.
            </p>
            <div className="step">
                <span className="step-n">1</span>
                <div className="flex min-w-0 flex-1 flex-wrap items-start gap-4">
                    <div
                        className="qr"
                        role="img"
                        aria-label="QR code for your Coffer setup key"
                        // Fortify renders this SVG on the server from the user's own secret.
                        dangerouslySetInnerHTML={{
                            __html: twoFactor.qrSvg ?? '',
                        }}
                    />
                    <div className="cf-stack min-w-[200px] flex-1 gap-2">
                        <span className="t-label-strong">
                            Scan with your authenticator app
                        </span>
                        <span className="t-caption t-muted">
                            Can’t scan it? Type this key instead:
                        </span>
                        <span className="cf-inwrap is-readonly">
                            <span className="t-money-sm tracking-[.06em]">
                                {spaced(twoFactor.secret ?? '')}
                            </span>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="ml-auto"
                                onClick={() =>
                                    void navigator.clipboard
                                        ?.writeText(twoFactor.secret ?? '')
                                        .then(() => {
                                            setCopiedKey(true);
                                            window.setTimeout(
                                                () => setCopiedKey(false),
                                                1800,
                                            );
                                        })
                                }
                            >
                                {copiedKey ? 'Copied ✓' : 'Copy'}
                            </Button>
                        </span>
                        <span className="t-caption t-muted">
                            Account: {email} · Issuer: Coffer
                        </span>
                    </div>
                </div>
            </div>
            <div className="step">
                <span className="step-n">2</span>
                <CodeInput
                    className="flex-1"
                    legend="Enter the 6-digit code it shows"
                    value={code}
                    onChange={(value) => {
                        setCode(value);
                        setError(undefined);
                    }}
                    error={error}
                />
            </div>
            <div className="step">
                <span className="step-n">3</span>
                <div className="flex min-w-0 flex-1 flex-col gap-2.5">
                    <span className="t-label-strong">
                        Save your recovery codes
                    </span>
                    <span className="t-caption t-muted">
                        Each code works once if you lose your phone. Keep them
                        somewhere safe, away from this computer.
                    </span>
                    <RecoveryCodes
                        codes={twoFactor.recoveryCodes ?? []}
                        onCopy={() => toast.success('Recovery codes copied')}
                    />
                    <Checkbox checked={saved} onCheckedChange={setSaved}>
                        I’ve saved my recovery codes
                    </Checkbox>
                </div>
            </div>
            <div className="cf-row profile-2fa-foot justify-end gap-2">
                <Button
                    variant="ghost"
                    onClick={() =>
                        router.delete(disableTwoFactor().url, {
                            preserveScroll: true,
                            onSuccess: () =>
                                toast.info(
                                    'You can set up 2FA any time from here',
                                ),
                        })
                    }
                >
                    Not now
                </Button>
                <Button
                    variant="primary"
                    disc={ArrowRight}
                    loading={checking}
                    disabled={code.length !== 6 || !saved}
                    onClick={turnOn}
                >
                    {checking ? 'Checking' : 'Turn on 2FA'}
                </Button>
            </div>
        </>
    );
}

type Sensitive = 'codes' | 'replace' | 'off';

const SENSITIVE: Record<Sensitive, { title: string; confirm: string }> = {
    codes: { title: 'Make new recovery codes?', confirm: 'Make new codes' },
    replace: { title: 'Replace your authenticator?', confirm: 'Replace' },
    off: {
        title: 'Turn off two-factor authentication?',
        confirm: 'Turn off 2FA',
    },
};

/**
 * P9 (D110): turning 2FA off, new recovery codes and replacing the authenticator ask for
 * the password or a current code in the same request; the server checks it.
 */
function ConfirmIdentity({
    action,
    onClose,
}: {
    action: Sensitive | null;
    onClose: () => void;
}) {
    const [value, setValue] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);
    const toast = useToast();
    const copy = action ? SENSITIVE[action] : SENSITIVE.off;

    const close = () => {
        setValue('');
        setError(undefined);
        onClose();
    };

    const options = {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
        onError: (errors: Record<string, string>) =>
            setError(errors.confirmation ?? Object.values(errors)[0]),
        onSuccess: () => {
            if (action === 'codes') {
                toast.success(
                    'New recovery codes made. The old ones stopped working.',
                );
            }

            close();
        },
    };

    const submit = () => {
        if (value.trim() === '' || action === null) {
            setError('Enter your password or a 6-digit code to confirm.');

            return;
        }

        const data = { confirmation: value.trim() };

        if (action === 'off') {
            router.delete(disableTwoFactor().url, { ...options, data });
        } else if (action === 'codes') {
            router.post(regenerateRecoveryCodes().url, data, options);
        } else {
            router.post(replaceTwoFactor().url, data, options);
        }
    };

    return (
        <Modal
            open={action !== null}
            onOpenChange={(open) => !open && close()}
            title={copy.title}
            subtitle={
                action === 'off'
                    ? 'Sign-in will only ask for your password, and your recovery codes stop working.'
                    : 'Confirm it’s you first.'
            }
            icon={KeyRound}
            size="sm"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
            footer={
                <>
                    <Button variant="ghost" onClick={close}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" loading={busy}>
                        {copy.confirm}
                    </Button>
                </>
            }
        >
            <PasswordField
                label="Password or 6-digit code"
                value={value}
                onChange={(event) => {
                    setValue(event.currentTarget.value);
                    setError(undefined);
                }}
                error={error}
                autoComplete="current-password"
                autoFocus
            />
        </Modal>
    );
}

function On({ twoFactor }: { twoFactor: TwoFactorProps }) {
    const [offWarn, setOffWarn] = useState(false);
    const [sensitive, setSensitive] = useState<Sensitive | null>(null);
    const toast = useToast();

    return (
        <>
            <AlertBanner
                variant="pos"
                icon={ShieldCheck}
                title="Two-factor authentication is on"
            >
                Authenticator app added{' '}
                {twoFactor.confirmedAt
                    ? formatDate(twoFactor.confirmedAt)
                    : 'earlier'}
                . Sign-in, unlocking the vault and reveals ask for a code.
            </AlertBanner>
            {twoFactor.recoveryCodes && (
                <div className="flex flex-col gap-2.5">
                    <span className="t-label-strong">
                        Your new recovery codes
                    </span>
                    <span className="t-caption t-muted">
                        The old ones stopped working. Save these now; they won’t
                        be shown again.
                    </span>
                    <RecoveryCodes
                        codes={twoFactor.recoveryCodes}
                        onCopy={() => toast.success('Recovery codes copied')}
                    />
                </div>
            )}
            <div className="cf-kv t-body">
                <span>Recovery codes left</span>
                <span className="t-money">{twoFactor.codesLeft} of 10</span>
            </div>
            <div className="cf-kv t-body">
                <span>Trusted devices</span>
                {twoFactor.trustedDevices > 0 ? (
                    <span className="cf-row gap-2">
                        <span className="t-body t-muted">
                            {twoFactor.trustedDevices}{' '}
                            {twoFactor.trustedDevices === 1
                                ? 'device skips'
                                : 'devices skip'}{' '}
                            the code
                        </span>
                        <TextButton
                            onClick={() =>
                                router.delete(forgetDevices().url, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Forget them
                        </TextButton>
                    </span>
                ) : (
                    <span className="t-body t-muted">
                        Skip the code for 30 days on devices you trust
                    </span>
                )}
            </div>
            <div className="cf-row flex-wrap gap-2">
                <Button
                    variant="secondary"
                    onClick={() => setSensitive('codes')}
                >
                    Make new recovery codes
                </Button>
                <Button
                    variant="secondary"
                    onClick={() => setSensitive('replace')}
                >
                    Replace authenticator
                </Button>
                <Button
                    variant="ghost"
                    className="invite-revoke"
                    onClick={() =>
                        twoFactor.required
                            ? setOffWarn(true)
                            : setSensitive('off')
                    }
                >
                    Turn off
                </Button>
            </div>
            {offWarn && (
                <div
                    className="cf-alert cf-alert--neg cf-alert--slim"
                    role="alert"
                >
                    <div className="cf-alert-main">
                        <div>
                            <div className="cf-alert-title">
                                You’re an Admin, so 2FA stays on
                            </div>
                            <div className="cf-alert-sub">
                                Your company requires 2FA for Admins and anyone
                                who can open the vault.
                            </div>
                        </div>
                    </div>
                </div>
            )}
            <ConfirmIdentity
                action={sensitive}
                onClose={() => setSensitive(null)}
            />
        </>
    );
}

/**
 * Two-factor authentication on Profile (design/screens/Profile.dc.html, "Setting up"
 * and "On"): scan, confirm with a code, save the recovery codes; then codes left,
 * trusted devices, new codes, replace and turn off (Admins keep it on, spec §23).
 */
export function TwoFactorCard({
    twoFactor,
    email,
}: {
    twoFactor: TwoFactorProps;
    email: string;
}) {
    const state = twoFactor.enabled
        ? 'on'
        : twoFactor.pending
          ? 'setup'
          : 'off';

    return (
        <section
            className="cf-card profile-col profile-2fa"
            aria-labelledby="tf-h"
        >
            <div className="cf-kv">
                <h2 id="tf-h" className="cf-dot-title t-title">
                    Two-factor authentication
                </h2>
                <span
                    className={cn(
                        'cf-pill',
                        state === 'on' ? 'cf-pill--pos' : 'cf-pill--warn',
                    )}
                >
                    {state === 'on' && (
                        <Icon icon={Check} size={14} strokeWidth={2} />
                    )}
                    {state === 'on' ? 'On' : 'Not set up'}
                </span>
            </div>
            {state === 'setup' && <SetUp twoFactor={twoFactor} email={email} />}
            {state === 'on' && <On twoFactor={twoFactor} />}
            {state === 'off' && (
                <>
                    <p className="t-body t-muted m-0">
                        Coffer asks for a code when you sign in, unlock the
                        vault or reveal a password. Use any authenticator app.
                    </p>
                    {twoFactor.required && (
                        <AlertBanner variant="warn" slim>
                            Admins need 2FA. Set it up now to keep your
                            companies safe.
                        </AlertBanner>
                    )}
                    <Button
                        variant="primary"
                        disc={ArrowRight}
                        className="self-start"
                        onClick={() =>
                            router.visit(startTwoFactor().url, {
                                preserveScroll: true,
                            })
                        }
                    >
                        Set up 2FA
                    </Button>
                </>
            )}
        </section>
    );
}
