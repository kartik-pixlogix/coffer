import { Form, Head, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { GoogleButton, OrDivider } from '@/components/auth/auth-parts';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { PasswordField } from '@/components/ui/password-field';
import { TextInput } from '@/components/ui/text-input';
import type { AuthLayoutProps } from '@/layouts/auth/auth-split-layout';
import { register } from '@/routes';
import { redirect as googleRedirect } from '@/routes/google';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
    googleEnabled: boolean;
};

/**
 * Sign in (design/screens/SignIn.dc.html, "Sign in" and "Error" states). A wrong
 * password shows the red line and, from the third try, how many are left before the
 * 15-minute lock (spec §18). The 2FA code step is auth/two-factor-challenge.
 */
export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Sign in" />

            <h1 className="cf-h1">
                <span className="t-display-light">Welcome back,</span>
                <span className="t-display">Sign in</span>
            </h1>

            <GoogleButton
                href={googleRedirect({ query: { intent: 'login' } }).url}
            >
                Continue with Google
            </GoogleButton>

            <OrDivider />

            {status && (
                <AlertBanner variant="pos" slim role="status">
                    {status}
                </AlertBanner>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                resetOnError={['password']}
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        {errors.email && (
                            <AlertBanner variant="neg" slim role="alert">
                                {errors.email}
                            </AlertBanner>
                        )}

                        <TextInput
                            id="si-email"
                            name="email"
                            type="email"
                            label="Work email"
                            autoComplete="email"
                            required
                            autoFocus
                        />

                        <PasswordField
                            id="si-pass"
                            name="password"
                            label="Password"
                            required
                            autoComplete="current-password"
                            error={errors.password}
                            labelAction={
                                canResetPassword ? (
                                    <Link
                                        href={request()}
                                        className="t-caption font-medium"
                                    >
                                        Forgot password?
                                    </Link>
                                ) : undefined
                            }
                        />

                        <Checkbox name="remember" value="1">
                            Remember me on this device
                        </Checkbox>

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            disc={ArrowRight}
                            loading={processing}
                            className="w-full justify-between"
                        >
                            Continue
                        </Button>

                        <p className="t-caption t-muted m-0">
                            Protected by 2FA. We’ll ask for a code from your
                            authenticator app next.
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    prompt: {
        text: 'New to Coffer?',
        label: 'Start free trial',
        href: register().url,
    },
} satisfies AuthLayoutProps;
