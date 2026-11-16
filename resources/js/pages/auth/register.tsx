import { Form, Head } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import {
    GoogleButton,
    MeterDots,
    OrDivider,
    StrengthLabel,
} from '@/components/auth/auth-parts';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { PasswordField } from '@/components/ui/password-field';
import { TextInput } from '@/components/ui/text-input';
import type { AuthLayoutProps } from '@/layouts/auth/auth-split-layout';
import { signUpStrength } from '@/lib/password-strength';
import { login } from '@/routes';
import { redirect as googleRedirect } from '@/routes/google';
import { store } from '@/routes/register';

const EMAIL = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;

/**
 * Sign up (design/screens/SignUp.dc.html): name, company, work email, password with
 * the strength meter, and the terms. Then email verification and the create-company
 * wizard, on a 14-day trial with no card (spec §18).
 */
export default function Register() {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const meter = signUpStrength(password);
    const emailBad = email.length > 0 && !EMAIL.test(email);

    return (
        <>
            <Head title="Start your free trial" />

            <Form
                {...store.form()}
                resetOnError={['password']}
                className="flex flex-col gap-[18px]"
            >
                {({ processing, errors }) => (
                    <>
                        <h1 className="cf-h1">
                            <span className="t-display-light">
                                Start your free trial
                            </span>
                            <span className="t-display">Set up Coffer</span>
                        </h1>

                        <GoogleButton
                            href={
                                googleRedirect({ query: { intent: 'signup' } })
                                    .url
                            }
                        >
                            Sign up with Google
                        </GoogleButton>

                        <OrDivider />

                        <div className="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                            <TextInput
                                id="su-name"
                                name="name"
                                label="Your name"
                                autoComplete="name"
                                required
                                autoFocus
                                error={errors.name}
                            />
                            <TextInput
                                id="su-co"
                                name="company_name"
                                label="Company name"
                                autoComplete="organization"
                                required
                                error={errors.company_name}
                            />
                        </div>

                        <TextInput
                            id="su-email"
                            name="email"
                            type="email"
                            label="Work email"
                            autoComplete="email"
                            required
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            error={
                                errors.email ??
                                (emailBad
                                    ? 'Use your work email so teammates can find the company.'
                                    : undefined)
                            }
                        />

                        <PasswordField
                            id="su-pass"
                            name="password"
                            label="Password"
                            autoComplete="new-password"
                            required
                            revealable={false}
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            labelAction={<StrengthLabel meter={meter} />}
                            meter={<MeterDots meter={meter} />}
                            help="At least 12 characters. A short sentence works well."
                            error={errors.password}
                        />

                        <Checkbox
                            name="terms"
                            value="1"
                            required
                            className="items-start"
                            error={errors.terms}
                        >
                            <span className="t-body">
                                I agree to the <a href="#terms">Terms</a> and{' '}
                                <a href="#privacy">Privacy Policy</a>.
                            </span>
                        </Checkbox>

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            disc={ArrowRight}
                            loading={processing}
                            className="w-full justify-between"
                        >
                            Create account
                        </Button>

                        <p className="t-caption t-muted m-0">
                            No card needed for the trial. We’ll send a link to
                            confirm your email.
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    aside: 'signup',
    width: 440,
    prompt: { text: 'Have an account?', label: 'Sign in', href: login().url },
} satisfies AuthLayoutProps;
