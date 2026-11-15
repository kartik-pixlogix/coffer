import { useState } from 'react';
import { PvNote, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import {
    PasswordField,
    VaultSecretField,
    VaultSecretView,
} from '@/components/ui/password-field';

const DEMO_SECRET = 'Vx9#kq!4LmP2@zr8';

/** Stands in for the step-up + reveal request. */
const revealAfterCheck = () =>
    new Promise<string>((resolve) =>
        window.setTimeout(() => resolve(DEMO_SECRET), 1200),
    );

function SignInErrorDemo() {
    const [password, setPassword] = useState('short');

    return (
        <PasswordField
            label="Error"
            fieldClassName="flex-1"
            value={password}
            onChange={(event) => setPassword(event.currentTarget.value)}
            revealable={false}
            autoComplete="new-password"
            error={
                password.length < 12 ? 'Use at least 12 characters.' : undefined
            }
        />
    );
}

export default {
    name: 'PasswordField',
    group: 'Forms',
    order: 9,
    subtitle:
        'Vault secret with Reveal and Copy · masked, verifying, shown, sign-in entry',
    render: () => (
        <>
            <VaultSecretField
                label="Masked"
                meta="Last changed 28 Sep 2026"
                onReveal={revealAfterCheck}
            />
            <VaultSecretView
                label="Shown · auto-hides"
                state="shown"
                secret={DEMO_SECRET}
                secondsLeft={24}
                copied
                wrapClassName="is-focus"
            />
            <VaultSecretView
                label="Revealing · checking your code"
                state="verifying"
            />
            <PvRow style={{ alignItems: 'flex-start' }}>
                <PasswordField
                    label="Sign-in password"
                    fieldClassName="flex-1"
                    defaultValue="hunter22!"
                />
                <SignInErrorDemo />
            </PvRow>
            <PvNote>
                The first field is live: Reveal waits for a stand-in check, the
                secret hides after 30 s, and Copy clears the clipboard 30 s
                later.
            </PvNote>
        </>
    ),
} satisfies GallerySection;
