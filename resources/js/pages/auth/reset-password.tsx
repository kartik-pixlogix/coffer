import { Form, Head } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import { MeterDots, StrengthLabel } from '@/components/auth/auth-parts';
import { Button } from '@/components/ui/button';
import { PasswordField } from '@/components/ui/password-field';
import { TextInput } from '@/components/ui/text-input';
import { profileStrength } from '@/lib/password-strength';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
};

/** Choose a new password from the emailed link, on the sign-in layout. */
export default function ResetPassword({ token, email }: Props) {
    const [password, setPassword] = useState('');
    const [repeat, setRepeat] = useState('');
    const meter = profileStrength(password);
    const mismatch = repeat.length > 0 && repeat !== password;

    return (
        <>
            <Head title="Choose a new password" />

            <h1 className="cf-h1">
                <span className="t-display-light">Almost back in,</span>
                <span className="t-display">New password</span>
            </h1>

            <Form
                {...update.form()}
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <TextInput
                            name="email"
                            type="email"
                            label="Work email"
                            autoComplete="email"
                            value={email}
                            readOnly
                            error={errors.email}
                        />

                        <PasswordField
                            name="password"
                            label="New password"
                            autoComplete="new-password"
                            required
                            autoFocus
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            labelAction={<StrengthLabel meter={meter} />}
                            meter={<MeterDots meter={meter} />}
                            help="At least 12 characters. A short sentence works well."
                            error={errors.password}
                        />

                        <PasswordField
                            name="password_confirmation"
                            label="Repeat new password"
                            autoComplete="new-password"
                            required
                            value={repeat}
                            onChange={(e) => setRepeat(e.target.value)}
                            error={
                                mismatch
                                    ? 'The two passwords don’t match.'
                                    : errors.password_confirmation
                            }
                        />

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            disc={ArrowRight}
                            loading={processing}
                            disabled={mismatch}
                            className="w-full justify-between"
                        >
                            Save and sign in
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}
