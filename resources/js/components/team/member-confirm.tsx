import { router } from '@inertiajs/react';
import { Trash2, UserRoundCheck, UserRoundX } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { destroy as deleteMember, status as toggleStatus } from '@/routes/team';
import type { TeamMember } from '@/types/team';

export type MemberConfirmState = {
    kind: 'status' | 'delete';
    member: TeamMember;
} | null;

/**
 * Deactivate / reactivate and delete confirmations for a team member (spec §7:
 * deactivate, not delete, once they have hours or salary history).
 */
export function MemberConfirm({
    confirm,
    onClose,
    afterDelete,
}: {
    confirm: MemberConfirmState;
    onClose: () => void;
    afterDelete?: () => void;
}) {
    const [busy, setBusy] = useState(false);
    const m = confirm?.member;
    const finish = {
        onStart: () => setBusy(true),
        onFinish: () => {
            setBusy(false);
            onClose();
        },
    };

    if (confirm?.kind === 'delete') {
        return (
            <ConfirmDialog
                open
                onOpenChange={(o) => !o && onClose()}
                icon={Trash2}
                title={`Delete ${m?.name}?`}
                description="They have no hours or salary on record, so they go for good."
                confirmLabel="Delete member"
                loading={busy}
                onConfirm={() =>
                    m &&
                    router.delete(deleteMember(m.id).url, {
                        ...finish,
                        onSuccess: afterDelete,
                    })
                }
            />
        );
    }

    return (
        <ConfirmDialog
            open={confirm !== null}
            onOpenChange={(o) => !o && onClose()}
            icon={m?.active ? UserRoundX : UserRoundCheck}
            title={
                m?.active ? `Deactivate ${m?.name}?` : `Reactivate ${m?.name}?`
            }
            description={
                m?.active
                    ? 'They stop costing money from today: no more salary and no new hours. Their history stays.'
                    : 'They can log hours again, and their rate applies from this month.'
            }
            confirmLabel={m?.active ? 'Deactivate' : 'Reactivate'}
            loading={busy}
            onConfirm={() =>
                m &&
                router.patch(
                    toggleStatus(m.id).url,
                    {},
                    { preserveScroll: true, ...finish },
                )
            }
        />
    );
}
