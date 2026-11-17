import { router, usePage } from '@inertiajs/react';
import { ShieldCheck, ShieldOff } from 'lucide-react';
import { useCallback, useState } from 'react';
import type { ReactNode } from 'react';
import { ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { toast } from '@/components/ui/toast';
import { LockCard } from '@/components/vault/home/lock-card';
import type { LockReason } from '@/components/vault/home/lock-card';
import {
    useIdleLock,
    useLockOnClose,
} from '@/components/vault/home/use-idle-lock';
import { firstName } from '@/lib/team';
import { vaultRequest } from '@/lib/vault-api';
import { edit as editProfile } from '@/routes/profile';
import { lock } from '@/routes/vault';
import type { VaultState } from '@/types/vault';

type Props = {
    vaultState: VaultState;
    children: ReactNode;
};

/**
 * The vault's pages beyond home (requests, health, audit, policies, import, export) sit
 * behind the same lock (spec §14.3): without 2FA the set-up prompt, locked the unlock
 * card (VaultLockPolicies.dc.html), unlocked the page with the idle lock running.
 */
export function VaultGate({ vaultState, children }: Props) {
    const { auth } = usePage().props;
    const [reason, setReason] = useState<LockReason>('session');
    const [locking, setLocking] = useState(false);
    const unlocked = vaultState.twoFactor && vaultState.unlocked && !locking;

    const relock = useCallback((why: LockReason, tellServer: boolean) => {
        setReason(why);
        setLocking(true);
        const reload = () =>
            router.reload({ onFinish: () => setLocking(false) });

        if (tellServer) {
            void vaultRequest(lock().url, {}).finally(reload);
        } else {
            reload();
        }
    }, []);

    useLockOnClose(unlocked, vaultState.policy.lockOnClose);

    useIdleLock({
        active: unlocked,
        idleMinutes: vaultState.policy.idleLockMinutes,
        onIdle: () => relock('idle', true),
        onLocked: () => {
            toast.info('The vault locked. Unlock it to continue.');
            relock('idle', false);
        },
    });

    if (!vaultState.twoFactor) {
        return (
            <EmptyState
                icon={ShieldOff}
                headingLevel={2}
                title="Turn on two-factor sign-in to use the vault"
                description="The vault asks for a code from your authenticator app before it shows a password."
                className="vh-no2fa"
                actions={
                    <ButtonLink
                        href={`${editProfile().url}#tf-h`}
                        variant="primary"
                        disc={ShieldCheck}
                    >
                        Turn on two-factor sign-in
                    </ButtonLink>
                }
            />
        );
    }

    if (!unlocked) {
        return (
            <section className="vg-lock">
                <LockCard
                    firstName={firstName(auth.user.name)}
                    policy={vaultState.policy}
                    reason={reason}
                    onUnlocked={() => {
                        toast.success('Vault unlocked');
                        router.reload();
                    }}
                />
            </section>
        );
    }

    return <>{children}</>;
}
