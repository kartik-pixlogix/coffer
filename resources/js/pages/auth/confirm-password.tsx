import { Form, Head } from '@inertiajs/react';
import { ArrowRight, LockKeyhole } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { PasswordField } from '@/components/ui/password-field';
import { store } from '@/routes/password/confirm';

/** Confirm your password before a sensitive change (2FA setup), on the sign-in layout. */
export default function ConfirmPassword() {
    return (
        <>
            <Head title="Confirm your password" />

            <div className="flex flex-col gap-2.5">
                <span className="cf-empty-ic auth-shield">
                    <Icon icon={LockKeyhole} size={22} />
                </span>
                <h1 className="cf-h1">
                    <span className="t-display-light">Just checking,</span>
                    <span className="t-display auth-display-sm">
                        Confirm your password
                    </span>
                </h1>
                <p className="t-body t-muted m-0">
                    This change affects how you sign in, so enter your password
                    first. We won’t ask again for a few hours.
                </p>
            </div>

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <PasswordField
                            name="password"
                            label="Password"
                            autoComplete="current-password"
                            required
                            autoFocus
                            error={errors.password}
                        />

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            disc={ArrowRight}
                            loading={processing}
                            className="w-full justify-between"
                        >
                            Confirm
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}
