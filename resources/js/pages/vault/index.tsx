import { Head, router, usePage } from '@inertiajs/react';
import { Lock, LockOpen, Plus, ShieldCheck, ShieldOff } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { PageHeader } from '@/components/shell/page-header';
import { Button, ButtonLink } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Icon } from '@/components/ui/icon';
import { toast } from '@/components/ui/toast';
import { ItemDetail, ItemLoading } from '@/components/vault/home/item-detail';
import { LockCard } from '@/components/vault/home/lock-card';
import type { LockReason } from '@/components/vault/home/lock-card';
import { useIdleLock } from '@/components/vault/home/use-idle-lock';
import { visibleItems } from '@/components/vault/home/vault-home.utils';
import type {
    VaultScope,
    VaultSort,
    VaultView,
} from '@/components/vault/home/vault-home.utils';
import { VaultList } from '@/components/vault/home/vault-list';
import { scopeLabel, VaultRail } from '@/components/vault/home/vault-rail';
import { VaultSessionContext } from '@/components/vault/home/vault-session';
import type { VaultSessionValue } from '@/components/vault/home/vault-session';
import { PHONE_QUERY, useMediaQuery } from '@/hooks/use-media-query';
import { firstName } from '@/lib/team';
import { vaultRequest } from '@/lib/vault-api';
import { edit as editProfile } from '@/routes/profile';
import { index, lock } from '@/routes/vault';
import { create, favourite } from '@/routes/vault/items';
import type { VaultIndexProps } from '@/types/vault';

const VIEW_KEY = 'coffer.vault.view';

/** Everything the lock changes (spec §14.3): reloaded after locking and unlocking. */
const VAULT_PROPS = [
    'vaultState',
    'items',
    'selected',
    'selectedId',
    'health',
    'vaults',
];

function readView(): VaultView {
    try {
        return window.localStorage.getItem(VIEW_KEY) === 'grid'
            ? 'grid'
            : 'list';
    } catch {
        return 'list';
    }
}

function saveView(view: VaultView): void {
    try {
        window.localStorage.setItem(VIEW_KEY, view);
    } catch {
        // Private mode or blocked storage: the view just isn't remembered.
    }
}

/**
 * Credentials Vault home (Vault.dc.html, MobileVault.dc.html; spec §14): the rail of
 * vaults, smart filters and health, the item list or grid, and the item drawer
 * (`?item=`). Locked, the list gives way to the lock card (VaultLockPolicies.dc.html).
 * Secrets only arrive from the reveal / copy / code endpoints, one field at a time, and
 * live in the drawer's state until they hide themselves.
 */
export default function VaultIndex(props: VaultIndexProps) {
    const { auth } = usePage().props;
    const { vaultState, vaults, items, health, selected, abilities } = props;
    const phone = useMediaQuery(PHONE_QUERY);

    const [scope, setScope] = useState<VaultScope>('all');
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState<VaultSort>('rotated');
    const [view, setView] = useState<VaultView>(readView);
    const [openId, setOpenId] = useState<number | null>(props.selectedId);
    const [lastSelectedId, setLastSelectedId] = useState(props.selectedId);
    const [loadingId, setLoadingId] = useState<number | null>(null);
    const [locking, setLocking] = useState(false);
    const [lockReason, setLockReason] = useState<LockReason>('session');
    const [verifiedUntil, setVerifiedUntil] = useState(
        vaultState.verifiedUntil,
    );
    const [lastVerified, setLastVerified] = useState(vaultState.verifiedUntil);

    // Follow the server when it moves the drawer (created, restored) or re-verifies.
    if (props.selectedId !== lastSelectedId) {
        setLastSelectedId(props.selectedId);
        setOpenId(props.selectedId);
    }

    if (vaultState.verifiedUntil !== lastVerified) {
        setLastVerified(vaultState.verifiedUntil);
        setVerifiedUntil(vaultState.verifiedUntil);
    }

    const twoFactor = vaultState.twoFactor;
    const unlocked = twoFactor && vaultState.unlocked && !locking;
    const timeZone = auth.user.timezone;

    const reloadVault = useCallback(
        (onFinish?: () => void) =>
            router.reload({ only: VAULT_PROPS, onFinish: () => onFinish?.() }),
        [],
    );

    /** Show the lock screen now, tell the server, then reload without the items. */
    const lockVault = useCallback(
        (reason: LockReason, tellServer: boolean) => {
            setLockReason(reason);
            setLocking(true);
            setVerifiedUntil(null);

            const reload = () => reloadVault(() => setLocking(false));

            if (tellServer) {
                void vaultRequest(lock().url, {}).finally(reload);
            } else {
                reload();
            }
        },
        [reloadVault],
    );

    const onServerLocked = useCallback(() => {
        toast.info('The vault locked. Unlock it to continue.');
        lockVault('idle', false);
    }, [lockVault]);

    useIdleLock({
        active: unlocked,
        idleMinutes: vaultState.policy.idleLockMinutes,
        onIdle: () => lockVault('idle', true),
        onLocked: onServerLocked,
    });

    const session = useMemo<VaultSessionValue>(
        () => ({
            policy: vaultState.policy,
            verifiedUntil,
            setVerifiedUntil,
            onLocked: onServerLocked,
            timeZone,
        }),
        [vaultState.policy, verifiedUntil, onServerLocked, timeZone],
    );

    const shown = useMemo(
        () => visibleItems(items, scope, query, sort),
        [items, scope, query, sort],
    );

    const visit = (id: number | null) =>
        router.get(index().url, id === null ? {} : { item: id }, {
            only: ['selected', 'selectedId'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoadingId(id),
            onFinish: () => setLoadingId(null),
        });

    const openItem = (id: number) => {
        if (id === openId) {
            return;
        }

        setOpenId(id);
        visit(id);
    };

    const closeItem = () => {
        setOpenId(null);
        visit(null);
    };

    const toggleFavourite = (id: number) =>
        router.post(
            favourite(id).url,
            {},
            {
                only: ['items', 'vaults'],
                preserveScroll: true,
                preserveState: true,
            },
        );

    const changeView = (next: VaultView) => {
        setView(next);
        saveView(next);
    };

    const onUnlocked = (
        state: { verifiedUntil: string | null },
        withCode: boolean,
    ) => {
        setVerifiedUntil(state.verifiedUntil);
        toast.success(
            withCode
                ? 'Vault unlocked'
                : 'Vault unlocked · revealing still asks for a code',
        );
        reloadVault();
    };

    const focusUnlock = () => {
        document
            .querySelector<HTMLElement>('#vh-lock .cf-code-box, #vh-lock input')
            ?.focus();
    };

    const row =
        openId === null ? null : (items.find((i) => i.id === openId) ?? null);
    const detail =
        unlocked && selected && selected.id === openId ? selected : null;
    const loadingRow =
        unlocked && detail === null && loadingId === openId ? row : null;

    const actions = (
        <>
            <span className="vh-e2e">
                <span className="vh-e2e-ic">
                    <Icon icon={ShieldCheck} size={18} />
                </span>
                End-to-end encrypted
            </span>
            {twoFactor &&
                (unlocked ? (
                    <Button
                        size="lg"
                        icon={Lock}
                        onClick={() => lockVault('manual', true)}
                    >
                        Lock vault
                    </Button>
                ) : (
                    <Button size="lg" icon={LockOpen} onClick={focusUnlock}>
                        Unlock vault
                    </Button>
                ))}
            {abilities.create && unlocked && (
                <ButtonLink
                    href={create().url}
                    variant="primary"
                    size="lg"
                    disc={Plus}
                >
                    Add item
                </ButtonLink>
            )}
        </>
    );

    return (
        <VaultSessionContext.Provider value={session}>
            <Head title="Credentials Vault" />
            <PageHeader
                kicker="Security"
                title="Credentials Vault"
                crumbs={[{ title: 'Credentials Vault' }]}
                actions={actions}
            />

            {!twoFactor ? (
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
            ) : (
                <section className="vh-layout">
                    <VaultRail
                        vaults={vaults}
                        items={items}
                        health={unlocked ? health : null}
                        scope={scope}
                        onScope={setScope}
                        locked={!unlocked}
                        canAddVault={abilities.newVault}
                    />

                    {unlocked ? (
                        <>
                            <VaultList
                                items={shown}
                                total={items.length}
                                title={scopeLabel(scope, vaults)}
                                query={query}
                                onQuery={setQuery}
                                sort={sort}
                                onSort={setSort}
                                view={view}
                                onView={changeView}
                                openId={detail || loadingRow ? openId : null}
                                onOpen={openItem}
                                onShowAll={() => {
                                    setScope('all');
                                    setQuery('');
                                }}
                                canCreate={abilities.create}
                                timeZone={timeZone}
                            />
                            {detail ? (
                                <ItemDetail
                                    key={detail.id}
                                    item={detail}
                                    favourite={
                                        row?.favourite ?? detail.favourite
                                    }
                                    phone={phone}
                                    openShare={props.openShare}
                                    onClose={closeItem}
                                    onFavourite={() =>
                                        toggleFavourite(detail.id)
                                    }
                                    onDeleted={() => setOpenId(null)}
                                />
                            ) : (
                                loadingRow && (
                                    <ItemLoading
                                        row={loadingRow}
                                        phone={phone}
                                        onClose={closeItem}
                                    />
                                )
                            )}
                        </>
                    ) : (
                        <LockCard
                            firstName={firstName(auth.user.name)}
                            policy={vaultState.policy}
                            reason={lockReason}
                            onUnlocked={onUnlocked}
                        />
                    )}
                </section>
            )}
        </VaultSessionContext.Provider>
    );
}
