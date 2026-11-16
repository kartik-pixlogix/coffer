import { Lock } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button, TextButton } from '@/components/ui/button';
import { CodeInput } from '@/components/ui/code-input';
import { Icon } from '@/components/ui/icon';
import { PasswordField } from '@/components/ui/password-field';
import { Skeleton } from '@/components/ui/skeleton';
import { TextInput } from '@/components/ui/text-input';
import { signOut } from '@/components/shell/sign-out';
import {
    durationText,
    reaskText,
} from '@/components/vault/home/vault-home.utils';
import { vaultRequest } from '@/lib/vault-api';
import { unlock } from '@/routes/vault';
import type { VaultPolicy, VaultUnlockResponse } from '@/types/vault';

export type LockReason = 'idle' | 'manual' | 'session';

type Mode = 'code' | 'recovery' | 'password';

type Props = {
    firstName: string;
    policy: VaultPolicy;
    reason: LockReason;
    onUnlocked: (
        state: VaultUnlockResponse['state'],
        withCode: boolean,
    ) => void;
};

const SKELETON_ROWS = ['46%', '38%', '52%', '30%', '44%', '36%', '50%', '28%'];

/**
 * The locked vault (VaultLockPolicies.dc.html "Vault locked"): the list blurred behind
 * a card asking for the 6-digit code, a recovery code or the account password.
 */
export function LockCard({ firstName, policy, reason, onUnlocked }: Props) {
    const [mode, setMode] = useState<Mode>('code');
    const [code, setCode] = useState('');
    const [text, setText] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [checking, setChecking] = useState(false);
    const idle = durationText(policy.idleLockMinutes);

    const switchTo = (next: Mode) => {
        setMode(next);
        setCode('');
        setText('');
        setError(undefined);
    };

    const ready =
        mode === 'code' ? /^\d{6}$/.test(code) : text.trim().length > 0;

    const submit = async (value?: string) => {
        const codeValue = value ?? code;

        if (
            checking ||
            (mode === 'code' ? !/^\d{6}$/.test(codeValue) : !ready)
        ) {
            return;
        }

        setChecking(true);
        setError(undefined);
        const body =
            mode === 'password'
                ? { password: text }
                : { code: mode === 'code' ? codeValue : text.trim() };
        const result = await vaultRequest<VaultUnlockResponse>(
            unlock().url,
            body,
        );

        if (result.ok) {
            onUnlocked(result.data.state, mode !== 'password');

            return;
        }

        setChecking(false);
        setError(
            result.errors.code?.[0] ??
                result.errors.password?.[0] ??
                result.message,
        );
        setCode('');
    };

    const onSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        void submit();
    };

    const description =
        reason === 'idle'
            ? `It locked after ${idle} without activity. Enter the 6-digit code from your authenticator app to open it.`
            : reason === 'manual'
              ? 'You locked it. Enter the 6-digit code from your authenticator app to open it again.'
              : `Enter the 6-digit code from your authenticator app to open it. It locks itself after ${idle} without activity.`;

    return (
        <section
            className="cf-card vh-lock"
            aria-label="Vault locked"
            id="vh-lock"
        >
            <div className="vh-lock-bg" aria-hidden="true">
                <div className="cf-row">
                    <Skeleton variant="title" width={220} />
                    <Skeleton width={120} height={32} pill />
                    <Skeleton width={120} height={32} pill />
                </div>
                {SKELETON_ROWS.map((width, i) => (
                    <div key={i} className="cf-row" style={{ gap: 14 }}>
                        <Skeleton variant="circle" width={40} height={40} />
                        <Skeleton variant="line" width={width} />
                        <Skeleton
                            variant="line"
                            width={140}
                            style={{ marginLeft: 'auto' }}
                        />
                    </div>
                ))}
            </div>
            <form
                className="vh-lock-card"
                role="dialog"
                aria-modal="false"
                aria-labelledby="vh-lock-t"
                aria-describedby="vh-lock-d"
                onSubmit={onSubmit}
                noValidate
            >
                <span className="cf-disc vh-lock-disc">
                    <Icon icon={Lock} size={24} />
                </span>
                <div className="cf-stack vh-lock-copy">
                    <h2 id="vh-lock-t" className="t-heading m-0">
                        Your vault is locked
                    </h2>
                    <p id="vh-lock-d" className="t-body t-muted m-0">
                        {mode === 'code'
                            ? description
                            : mode === 'recovery'
                              ? 'Enter one of the recovery codes you saved when you turned on two-factor sign-in. Each one works once.'
                              : 'Enter your account password. Revealing a password will still ask for a code from your authenticator app.'}
                    </p>
                </div>

                {mode === 'code' ? (
                    <div
                        role="group"
                        aria-label="6-digit code"
                        className="vh-lock-code"
                    >
                        <CodeInput
                            value={code}
                            onChange={(value) => {
                                setCode(value);

                                if (value !== '') {
                                    setError(undefined);
                                }
                            }}
                            onComplete={(value) => void submit(value)}
                            error={error}
                            disabled={checking}
                            autoFocus
                        />
                    </div>
                ) : mode === 'recovery' ? (
                    <TextInput
                        label="Recovery code"
                        value={text}
                        onChange={(event) => {
                            setText(event.currentTarget.value);
                            setError(undefined);
                        }}
                        error={error}
                        autoComplete="off"
                        spellCheck={false}
                        autoFocus
                    />
                ) : (
                    <PasswordField
                        label="Password"
                        value={text}
                        onChange={(event) => {
                            setText(event.currentTarget.value);
                            setError(undefined);
                        }}
                        error={error}
                        autoFocus
                    />
                )}

                <Button
                    type="submit"
                    variant="primary"
                    size="lg"
                    loading={checking}
                    disabled={!ready && !checking}
                >
                    {checking ? 'Checking' : 'Unlock vault'}
                </Button>

                <div className="vh-lock-links">
                    {mode === 'code' ? (
                        <>
                            <TextButton
                                className="t-caption"
                                onClick={() => switchTo('recovery')}
                            >
                                Use a recovery code
                            </TextButton>
                            <span
                                className="t-caption t-muted"
                                aria-hidden="true"
                            >
                                ·
                            </span>
                            <TextButton
                                className="t-caption"
                                onClick={() => switchTo('password')}
                            >
                                Use your password
                            </TextButton>
                        </>
                    ) : (
                        <TextButton
                            className="t-caption"
                            onClick={() => switchTo('code')}
                        >
                            Use the 6-digit code
                        </TextButton>
                    )}
                    <span className="t-caption t-muted" aria-hidden="true">
                        ·
                    </span>
                    <TextButton className="t-caption" onClick={signOut}>
                        Not {firstName}? Sign out
                    </TextButton>
                </div>
                <span className="t-caption t-muted">
                    Set by your Admin: re-ask every{' '}
                    {reaskText(policy.reauthMinutes)}.
                </span>
            </form>
        </section>
    );
}
