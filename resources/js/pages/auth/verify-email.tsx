import { Form, Head } from '@inertiajs/react';
import { MailCheck } from 'lucide-react';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Button, TextButton } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

type Props = {
    status?: string;
    email: string | null;
};

/** "Confirm your email" after sign-up, on the sign-in layout (spec §18). */
export default function VerifyEmail({ status, email }: Props) {
    return (
        <>
            <Head title="Confirm your email" />

            <div className="flex flex-col gap-2.5">
                <span className="cf-empty-ic auth-shield">
                    <Icon icon={MailCheck} size={22} />
                </span>
                <h1 className="cf-h1">
                    <span className="t-display-light">One more step,</span>
                    <span className="t-display auth-display-sm">
                        Confirm your email
                    </span>
                </h1>
                <p className="t-body t-muted m-0">
                    We sent a link to{' '}
                    <span className="font-medium text-ink">
                        {email ?? 'your email'}
                    </span>
                    . Open it to confirm the address, then you’ll set up your
                    company.
                </p>
            </div>

            {status === 'verification-link-sent' && (
                <AlertBanner variant="pos" slim role="status">
                    We sent a new link. It works for 60 minutes.
                </AlertBanner>
            )}

            <Form {...send.form()} className="flex flex-col gap-4">
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="secondary"
                        size="lg"
                        loading={processing}
                        className="w-full"
                    >
                        Send the link again
                    </Button>
                )}
            </Form>

            <Form {...logout.form()}>
                <TextButton type="submit">Use a different email</TextButton>
            </Form>
        </>
    );
}
