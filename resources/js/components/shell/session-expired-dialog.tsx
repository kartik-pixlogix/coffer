import { Clock, Eye, EyeOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { initialsFrom } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { CodeInput } from '@/components/ui/code-input';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { useToast } from '@/components/ui/toast';
import {
    registerSessionDialog,
    sessionRequest,
    setSessionExpired,
    useSessionExpired,
} from '@/lib/session-expiry';
import { cn } from '@/lib/utils';
import { login } from '@/routes';
import { request as forgotPassword } from '@/routes/password';
import { reauth, token as sessionToken } from '@/routes/session';
import { code as reauthCode } from '@/routes/session/reauth';

type Step = 'password' | 'code';

const firstError = (
    data: Record<string, unknown>,
    field: string,
): string | undefined => {
    const errors = data.errors as Record<string, string[]> | undefined;

    return errors?.[field]?.[0];
};

/**
 * "Your session ended" (design/screens/StateSession.dc.html, D30): over the dimmed page,
 * sign in again with the password (then the 2FA code) and carry on where you were.
 * Nothing on the page reloads, so a half-filled form is still there.
 */
export function SessionExpiredDialog({
    email,
    name,
}: {
    email: string;
    name: string;
}) {
    const open = useSessionExpired();
    const [step, setStep] = useState<Step>('password');
    const [password, setPassword] = useState('');
    const [shown, setShown] = useState(false);
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);
    const passwordRef = useRef<HTMLInputElement>(null);
    const toast = useToast();

    useEffect(() => registerSessionDialog(), []);

    useEffect(() => {
        if (open) {
            passwordRef.current?.focus();
        }
    }, [open, step]);

    if (!open) {
        return null;
    }

    const finish = () => {
        setSessionExpired(false);
        setStep('password');
        setPassword('');
        setCode('');
        setError(undefined);
        toast.success('Welcome back. Anything you hadn’t saved is still here.');
    };

    const submitPassword = async () => {
        setBusy(true);
        setError(undefined);

        try {
            // A new session needs a new CSRF token before we can post to it.
            await sessionRequest(sessionToken().url);
            const { status, data } = await sessionRequest(reauth().url, {
                email,
                password,
            });

            if (status === 200 && data.twoFactor) {
                setStep('code');
            } else if (status === 200 && data.ok) {
                finish();
            } else {
                setError(
                    firstError(data, 'password') ??
                        'That password didn’t work. Try again or reset it.',
                );
            }
        } finally {
            setBusy(false);
        }
    };

    const submitCode = async (value = code) => {
        setBusy(true);
        setError(undefined);

        try {
            const { status, data } = await sessionRequest(reauthCode().url, {
                code: value,
            });

            if (status === 200 && data.ok) {
                finish();
            } else {
                setError(
                    firstError(data, 'code') ??
                        'That code didn’t match. Codes change every 30 seconds.',
                );
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <div className="cf-scrim" aria-hidden="true" />
            <div
                className="cf-modal cf-modal--sm session-modal"
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="se-t"
                aria-describedby="se-d"
            >
                <span className="cf-disc session-disc">
                    <Icon icon={Clock} size={22} />
                </span>
                <div className="cf-stack gap-1.5">
                    <h2 id="se-t" className="t-heading m-0">
                        {step === 'password'
                            ? 'Your session ended'
                            : 'One more step'}
                    </h2>
                    <p id="se-d" className="t-body t-muted m-0">
                        {step === 'password'
                            ? 'You were signed out after a while without activity. Sign in again and you’ll land back on this page.'
                            : 'Enter the code from your authenticator app to get back in.'}
                    </p>
                </div>

                {step === 'password' ? (
                    <form
                        className="flex flex-col gap-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void submitPassword();
                        }}
                    >
                        <div className="cf-field">
                            <div className="cf-label-row">
                                <span className="cf-label">Email</span>
                                <a
                                    href={login().url}
                                    className="cf-link t-caption"
                                >
                                    Not you?
                                </a>
                            </div>
                            <span className="cf-inwrap is-readonly">
                                <span
                                    className="cf-avatar cf-avatar--sm"
                                    aria-hidden="true"
                                >
                                    {initialsFrom(name)}
                                </span>
                                <span className="t-body">{email}</span>
                            </span>
                        </div>
                        <div className="cf-field">
                            <div className="cf-label-row">
                                <label className="cf-label" htmlFor="se-p">
                                    Password
                                </label>
                                <a
                                    href={forgotPassword().url}
                                    className="cf-link t-caption"
                                >
                                    Forgot it?
                                </a>
                            </div>
                            <span
                                className={cn('cf-inwrap', error && 'is-error')}
                            >
                                <input
                                    ref={passwordRef}
                                    id="se-p"
                                    type={shown ? 'text' : 'password'}
                                    value={password}
                                    autoComplete="current-password"
                                    aria-invalid={error ? true : undefined}
                                    aria-describedby={
                                        error ? 'se-p-e' : undefined
                                    }
                                    onChange={(e) => {
                                        setPassword(e.target.value);
                                        setError(undefined);
                                    }}
                                />
                                <IconButton
                                    icon={shown ? EyeOff : Eye}
                                    size="sm"
                                    variant="bare"
                                    iconSize={16}
                                    aria-label={
                                        shown
                                            ? 'Hide password'
                                            : 'Show password'
                                    }
                                    aria-pressed={shown}
                                    onClick={() => setShown((s) => !s)}
                                />
                            </span>
                            {error && (
                                <span id="se-p-e" className="cf-errtext">
                                    {error}
                                </span>
                            )}
                        </div>
                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            loading={busy}
                            disabled={password === ''}
                        >
                            {busy ? 'Signing in' : 'Sign in again'}
                        </Button>
                    </form>
                ) : (
                    <>
                        <CodeInput
                            legend="6-digit code from your authenticator"
                            autoFocus
                            value={code}
                            verifying={busy}
                            verifyingLabel="Checking"
                            onChange={(value) => {
                                setCode(value);
                                setError(undefined);
                            }}
                            onComplete={(value) => void submitCode(value)}
                            error={error}
                        />
                        <div className="cf-row gap-2">
                            <Button
                                variant="secondary"
                                size="lg"
                                onClick={() => setStep('password')}
                            >
                                Back
                            </Button>
                            <Button
                                variant="primary"
                                size="lg"
                                className="flex-1"
                                loading={busy}
                                disabled={code.length !== 6}
                                onClick={() => void submitCode()}
                            >
                                {busy ? 'Checking' : 'Continue'}
                            </Button>
                        </div>
                    </>
                )}

                <span className="t-caption t-muted">
                    Anything you hadn’t saved stays on this page until you’re
                    back.
                </span>
            </div>
        </>
    );
}
