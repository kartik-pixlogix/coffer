import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from '@/components/ui/toast';
import { copySecret } from '@/components/vault/home/clipboard';
import {
    isVerified,
    nextTotpDelay,
    pruneRevealed,
    secondsUntil,
} from '@/components/vault/home/vault-home.utils';
import { useVaultSession } from '@/components/vault/home/vault-session';
import { downloadVaultAttachment, vaultRequest } from '@/lib/vault-api';
import type { VaultResult } from '@/lib/vault-api';
import { unlock } from '@/routes/vault';
import { show as attachmentShow } from '@/routes/vault/attachments';
import {
    copy as copyRoute,
    reveal as revealRoute,
    totp as totpRoute,
} from '@/routes/vault/items';
import type {
    VaultAttachmentMeta,
    VaultRevealResponse,
    VaultTotpResponse,
    VaultUnlockResponse,
} from '@/types/vault';

type Revealed = { value: string; label: string; until: number };

export type TotpState = {
    code: string;
    /** Epoch ms the code stops working. */
    expiresAt: number;
    period: number;
};

type Outcome = 'ok' | 'stepUp' | 'locked' | 'error';

/** A secret action that may need the "Confirm it's you" code first. */
type Pending = { target: string; run: () => Promise<void> };

/**
 * Reveal, copy, one-time codes and downloads for one vault item (spec §14.3). Values
 * live only in this hook's state, hide themselves after the policy time and vanish when
 * the drawer unmounts (item change, close, lock). Each call is one field, audited by the
 * server; a missing or stale 2FA check shows the inline code form, then retries.
 */
export function useItemSecrets(itemId: number) {
    const session = useVaultSession();
    const sessionRef = useRef(session);
    const [revealed, setRevealed] = useState<Record<string, Revealed>>({});
    const [now, setNow] = useState(() => Date.now());
    const [busy, setBusy] = useState<string | null>(null);
    const [stepUpTarget, setStepUpTarget] = useState<string | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const [totp, setTotp] = useState<TotpState | null>(null);
    const pending = useRef<Pending | null>(null);
    const totpTimer = useRef<number | null>(null);
    const totpStarted = useRef(0);
    const mounted = useRef(true);

    useEffect(() => {
        sessionRef.current = session;
    });

    useEffect(() => {
        mounted.current = true;

        return () => {
            mounted.current = false;

            if (totpTimer.current !== null) {
                window.clearTimeout(totpTimer.current);
            }
        };
    }, []);

    const hasRevealed = Object.keys(revealed).length > 0;

    // Count down while anything is shown; forget each value when its time is up.
    useEffect(() => {
        if (!hasRevealed) {
            return;
        }

        const timer = window.setInterval(() => {
            const at = Date.now();
            setNow(at);
            setRevealed((prev) => pruneRevealed(prev, at));
        }, 1000);

        return () => window.clearInterval(timer);
    }, [hasRevealed]);

    /** Map a failed call to the lock screen, the code form or a toast. */
    const outcome = useCallback(
        <T>(result: VaultResult<T>, quiet = false): Outcome => {
            if (result.ok) {
                return 'ok';
            }

            if (result.locked) {
                sessionRef.current.onLocked();

                return 'locked';
            }

            if (result.stepUp) {
                sessionRef.current.setVerifiedUntil(null);

                return 'stepUp';
            }

            if (!quiet) {
                toast.error(result.message);
            }

            return 'error';
        },
        [],
    );

    /** Run `action` now when the 2FA check is fresh, else after the code form. */
    const guarded = useCallback(
        (target: string, action: () => Promise<Outcome>) => {
            const attempt = async () => {
                setBusy(target);
                let result: Outcome = 'error';

                try {
                    result = await action();
                } catch {
                    toast.error('Something went wrong. Try again.');
                } finally {
                    if (mounted.current) {
                        setBusy(null);
                    }
                }

                if (result === 'stepUp' && mounted.current) {
                    pending.current = { target, run: attempt };
                    setStepUpTarget(target);
                }
            };

            if (isVerified(sessionRef.current.verifiedUntil, Date.now())) {
                void attempt();
            } else {
                pending.current = { target, run: attempt };
                setStepUpTarget(target);
            }
        },
        [],
    );

    const reveal = (field: string) =>
        guarded(field, async () => {
            const result = await vaultRequest<VaultRevealResponse>(
                revealRoute(itemId).url,
                { field },
            );

            if (result.ok && mounted.current) {
                const { value, label, hideAfter } = result.data;
                const at = Date.now();
                setNow(at);
                setRevealed((prev) => ({
                    ...prev,
                    [field]: { value, label, until: at + hideAfter * 1000 },
                }));
                setAnnouncement(
                    `${label} shown. It hides in ${hideAfter} seconds.`,
                );
            }

            return outcome(result);
        });

    const hide = (field: string) => {
        setRevealed((prev) => {
            const next = { ...prev };
            delete next[field];

            return next;
        });
        setAnnouncement('Hidden again.');
    };

    const copy = (field: string) =>
        guarded(field, async () => {
            const result = await vaultRequest<VaultRevealResponse>(
                copyRoute(itemId).url,
                { field },
            );

            if (result.ok) {
                const { value, label, clearAfter } = result.data;
                const copied = await copySecret(value, clearAfter);

                if (copied) {
                    toast.success(
                        `${label} copied · clipboard clears in ${clearAfter} s`,
                    );
                } else {
                    toast.error(
                        'Your browser blocked the clipboard. Reveal it instead.',
                    );
                }
            }

            return outcome(result);
        });

    const stopTotp = useCallback(() => {
        if (totpTimer.current !== null) {
            window.clearTimeout(totpTimer.current);
            totpTimer.current = null;
        }

        setTotp(null);
    }, []);

    const fetchTotp = useCallback(
        async (auto: boolean): Promise<Outcome> => {
            const result = await vaultRequest<VaultTotpResponse>(
                totpRoute(itemId).url,
                {},
            );

            if (!mounted.current) {
                return 'ok';
            }

            if (!result.ok) {
                stopTotp();
                const failed = outcome(result, auto);

                // A refresh that finds the check stale just stops; it never prompts by itself.
                return auto ? 'error' : failed;
            }

            const at = Date.now();
            const { code, secondsLeft, period } = result.data;
            setTotp({ code, period, expiresAt: at + secondsLeft * 1000 });

            const delay = nextTotpDelay(totpStarted.current, at, secondsLeft);
            totpTimer.current = window.setTimeout(
                () => {
                    totpTimer.current = null;

                    if (delay === null) {
                        setTotp(null);
                    } else {
                        void fetchTotp(true);
                    }
                },
                delay ?? Math.max(0, secondsLeft * 1000),
            );

            return 'ok';
        },
        [itemId, outcome, stopTotp],
    );

    const showCode = () =>
        guarded('totp', () => {
            totpStarted.current = Date.now();

            return fetchTotp(false);
        });

    const copyCode = async () => {
        if (totp === null) {
            return;
        }

        const seconds = session.policy.clipboardClearSeconds;

        if (await copySecret(totp.code, seconds)) {
            toast.success(
                `One-time code copied · clipboard clears in ${seconds} s`,
            );
        } else {
            toast.error('Your browser blocked the clipboard.');
        }
    };

    const download = (attachment: VaultAttachmentMeta) =>
        guarded(`att:${attachment.id}`, async () =>
            outcome(
                await downloadVaultAttachment(
                    attachmentShow(attachment.id).url,
                    attachment.name,
                ),
            ),
        );

    /** The inline code form: unlock with the code (a fresh step-up), then retry. */
    const verify = async (code: string): Promise<string | null> => {
        const result = await vaultRequest<VaultUnlockResponse>(unlock().url, {
            code,
        });

        if (!result.ok) {
            return result.errors.code?.[0] ?? result.message;
        }

        session.setVerifiedUntil(result.data.state.verifiedUntil);
        const next = pending.current;
        pending.current = null;
        setStepUpTarget(null);

        if (next) {
            await next.run();
        }

        return null;
    };

    const cancelStepUp = () => {
        pending.current = null;
        setStepUpTarget(null);
    };

    const shown = (field: string) => {
        const entry = revealed[field];

        return entry === undefined || entry.until <= now
            ? null
            : {
                  value: entry.value,
                  secondsLeft: secondsUntil(entry.until, now),
              };
    };

    return {
        shown,
        reveal,
        hide,
        copy,
        busy,
        stepUpTarget,
        verify,
        cancelStepUp,
        totp,
        showCode,
        copyCode,
        download,
        announcement,
    };
}

export type ItemSecrets = ReturnType<typeof useItemSecrets>;
