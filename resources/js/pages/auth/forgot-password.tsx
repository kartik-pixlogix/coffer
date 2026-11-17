import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { TextInput } from '@/components/ui/text-input';
import type { AuthLayoutProps } from '@/layouts/auth/auth-split-layout';
import { login, register } from '@/routes';
import { email } from '@/routes/password';

/** Forgot password, on the sign-in layout (no design of its own; DESIGN_MAP). */
export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <>
            <Head title="Reset your password" />

            <Link
                href={login()}
                className="cf-link self-start p-0 no-underline"
            >
                <Icon icon={ArrowLeft} size={16} />
                Back to sign in
            </Link>

            <h1 className="cf-h1">
                <span className="t-display-light">Forgot it?</span>
                <span className="t-display">Reset password</span>
            </h1>
            <p className="t-body t-muted m-0">
                Enter the email you sign in with. We’ll send a link to choose a
                new password. It works for 60 minutes.
            </p>

            {status && (
                <AlertBanner variant="pos" slim role="status">
                    {status}
                </AlertBanner>
            )}

            <Form {...email.form()} className="flex flex-col gap-5">
                {({ processing, errors }) => (
                    <>
                        <TextInput
                            name="email"
                            type="email"
                            label="Work email"
                            placeholder="you@company.com"
                            autoComplete="email"
                            required
                            autoFocus
                            error={errors.email}
                        />

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            disc={ArrowRight}
                            loading={processing}
                            className="w-full justify-between"
                        >
                            Send reset link
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

ForgotPassword.layout = {
    prompt: {
        text: 'New to Coffer?',
        label: 'Start free trial',
        href: register().url,
    },
} satisfies AuthLayoutProps;
