import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { Button, TextButton } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { CodeInput } from '@/components/ui/code-input';
import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import { TextInput } from '@/components/ui/text-input';
import type { AuthLayoutProps } from '@/layouts/auth/auth-split-layout';
import { login } from '@/routes';
import { store } from '@/routes/two-factor/login';

type Props = {
    /** The account being signed in, for "…the 6-digit code for {email}". */
    email: string | null;
};

/**
 * The 2FA step of sign-in (SignIn.dc.html, "2FA code" state): six boxes that submit
 * on the sixth digit, "Trust this device for 30 days", recovery-code mode and
 * "Lost your device?".
 */
export default function TwoFactorChallenge({ email }: Props) {
    const [recovery, setRecovery] = useState(false);
    const [lostOpen, setLostOpen] = useState(false);

    return (
        <>
            <Head title="Enter your code" />

            <Link
                href={login()}
                className="cf-link self-start p-0 no-underline"
            >
                <Icon icon={ArrowLeft} size={16} />
                Use a different account
            </Link>

            <div className="flex flex-col gap-2.5">
                <span className="cf-empty-ic auth-shield">
                    <Icon icon={ShieldCheck} size={22} />
                </span>
                <h1 className="cf-h1">
                    <span className="t-display-light">Two-step check</span>
                    <span className="t-display auth-display-sm">
                        {recovery ? 'Use a recovery code' : 'Enter your code'}
                    </span>
                </h1>
                <p className="t-body t-muted m-0">
                    {recovery ? (
                        'Enter one of the recovery codes you saved when you turned on 2FA. Each code works once.'
                    ) : (
                        <>
                            Open your authenticator app and enter the 6-digit
                            code
                            {email && (
                                <>
                                    {' '}
                                    for{' '}
                                    <span className="font-medium text-ink">
                                        {email}
                                    </span>
                                </>
                            )}
                            .
                        </>
                    )}
                </p>
            </div>

            <Form
                {...store.form()}
                resetOnError
                className="flex flex-col gap-5"
            >
                {({ processing, errors, submit }) => (
                    <>
                        {recovery ? (
                            <TextInput
                                key="recovery"
                                name="recovery_code"
                                label="Recovery code"
                                autoComplete="one-time-code"
                                autoFocus
                                className="t-money-sm"
                                placeholder="XXXX-XXXX"
                                error={errors.recovery_code}
                            />
                        ) : (
                            <CodeInput
                                key="code"
                                name="code"
                                legend="6-digit code"
                                autoFocus
                                verifying={processing}
                                verifyingLabel="Checking"
                                onComplete={() => submit()}
                                error={
                                    errors.code
                                        ? 'That code didn’t match. Codes change every 30 seconds.'
                                        : undefined
                                }
                            />
                        )}

                        <Checkbox name="trust_device" value="1">
                            Trust this device for 30 days
                        </Checkbox>

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            disc={ArrowRight}
                            loading={processing}
                            className="w-full justify-between"
                        >
                            Verify and sign in
                        </Button>

                        <div className="flex flex-wrap gap-x-4 gap-y-2">
                            <TextButton onClick={() => setRecovery((r) => !r)}>
                                {recovery
                                    ? 'Use your authenticator app'
                                    : 'Use a recovery code'}
                            </TextButton>
                            <TextButton onClick={() => setLostOpen(true)}>
                                Lost your device?
                            </TextButton>
                        </div>
                    </>
                )}
            </Form>

            <Modal
                open={lostOpen}
                onOpenChange={setLostOpen}
                size="sm"
                icon={ShieldCheck}
                title="Lost your device?"
                footer={
                    <>
                        <Button
                            variant="secondary"
                            onClick={() => setLostOpen(false)}
                        >
                            Close
                        </Button>
                        <Button
                            variant="primary"
                            onClick={() => {
                                setRecovery(true);
                                setLostOpen(false);
                            }}
                        >
                            Use a recovery code
                        </Button>
                    </>
                }
            >
                <p className="t-body t-muted m-0">
                    Use one of the recovery codes you saved when you turned on
                    2FA. If you don’t have them, ask an Admin of your company to
                    reset two-factor authentication for you.
                </p>
            </Modal>
        </>
    );
}

TwoFactorChallenge.layout = {} satisfies AuthLayoutProps;
