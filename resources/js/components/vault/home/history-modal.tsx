import { router } from '@inertiajs/react';
import { Check, History, RotateCcw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Modal } from '@/components/ui/modal';
import { Skeleton, SkeletonGroup } from '@/components/ui/skeleton';
import { StatusPill } from '@/components/ui/status-pill';
import { toast } from '@/components/ui/toast';
import { versionLine } from '@/components/vault/home/vault-home.utils';
import { useVaultSession } from '@/components/vault/home/vault-session';
import { vaultRequest } from '@/lib/vault-api';
import { history as historyRoute, restore } from '@/routes/vault/items';
import type { VaultItemDetail, VaultVersion } from '@/types/vault';

type Props = {
    item: VaultItemDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/** Version history of an item, with Restore for those allowed (spec §14.1). */
export function HistoryModal({ item, open, onOpenChange }: Props) {
    const session = useVaultSession();
    const [versions, setVersions] = useState<VaultVersion[] | null>(null);
    const [canRestore, setCanRestore] = useState(false);
    const [confirm, setConfirm] = useState<VaultVersion | null>(null);
    const [restoring, setRestoring] = useState(false);
    const latest = useRef({ onOpenChange, onLocked: session.onLocked });

    useEffect(() => {
        latest.current = { onOpenChange, onLocked: session.onLocked };
    });

    useEffect(() => {
        if (!open) {
            return;
        }

        let live = true;
        setVersions(null);

        void vaultRequest<{ versions: VaultVersion[]; canRestore: boolean }>(
            historyRoute(item.id).url,
        ).then((result) => {
            if (!live) {
                return;
            }

            if (result.ok) {
                setVersions(result.data.versions);
                setCanRestore(result.data.canRestore);
            } else if (result.locked) {
                latest.current.onOpenChange(false);
                latest.current.onLocked();
            } else {
                setVersions([]);
                toast.error(result.message);
            }
        });

        return () => {
            live = false;
        };
    }, [open, item.id]);

    const doRestore = () => {
        if (confirm === null) {
            return;
        }

        router.post(
            restore({ item: item.id, version: confirm.version }).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setRestoring(true),
                onFinish: () => setRestoring(false),
                onSuccess: () => {
                    setConfirm(null);
                    onOpenChange(false);
                },
            },
        );
    };

    return (
        <>
            <Modal
                open={open}
                onOpenChange={onOpenChange}
                icon={History}
                title="Version history"
                subtitle={item.name}
                footer={
                    <Button variant="ghost" onClick={() => onOpenChange(false)}>
                        Close
                    </Button>
                }
            >
                {versions === null ? (
                    <SkeletonGroup aria-label="Loading versions">
                        <div className="vh-versions">
                            {[0, 1, 2].map((i) => (
                                <Skeleton key={i} variant="line" width="70%" />
                            ))}
                        </div>
                    </SkeletonGroup>
                ) : versions.length === 0 ? (
                    <p className="t-body t-muted m-0">No versions saved yet.</p>
                ) : (
                    <ul className="vh-versions">
                        {versions.map((version) => (
                            <li key={version.version} className="vh-version">
                                <span className="t-body">
                                    {versionLine(version, session.timeZone)}
                                </span>
                                {version.current ? (
                                    <StatusPill
                                        tone="lime"
                                        icon={Check}
                                        size="sm"
                                    >
                                        Current
                                    </StatusPill>
                                ) : (
                                    canRestore && (
                                        <Button
                                            size="sm"
                                            icon={RotateCcw}
                                            onClick={() => setConfirm(version)}
                                        >
                                            Restore
                                        </Button>
                                    )
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Modal>
            <ConfirmDialog
                open={confirm !== null}
                onOpenChange={(next) => !next && setConfirm(null)}
                onConfirm={doRestore}
                loading={restoring}
                icon={RotateCcw}
                title={`Restore version ${confirm?.version ?? ''}?`}
                description={`${item.name} goes back to the fields and secrets of version ${confirm?.version ?? ''}. It saves as a new version, so nothing is lost.`}
                confirmLabel="Restore version"
                cancelLabel="Cancel"
            />
        </>
    );
}
