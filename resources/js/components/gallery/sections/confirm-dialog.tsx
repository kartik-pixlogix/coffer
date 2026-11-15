import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Button } from '@/components/ui/button';
import { ConfirmDialog, ConfirmPanel } from '@/components/ui/confirm-dialog';
import { toast } from '@/components/ui/toast';

const DELETE_COPY =
    '“Team dinner — Diwali” (₹18,500) will be removed for every partner. This can’t be undone.';
const CLOSE_COPY =
    'Entries dated 1 Apr 2025 – 31 Mar 2026 lock, profit moves to capital accounts and opening balances roll forward.';

function LiveDelete() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button variant="danger" onClick={() => setOpen(true)}>
                Delete expense…
            </Button>
            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                icon={Trash2}
                title="Delete this expense?"
                description={DELETE_COPY}
                cancelLabel="Keep it"
                confirmLabel="Delete expense"
                onConfirm={() => {
                    setOpen(false);
                    toast.success('Expense deleted', {
                        icon: Trash2,
                        action: { label: 'Undo', onClick: () => undefined },
                    });
                }}
            />
        </>
    );
}

function LiveCloseFy() {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    return (
        <>
            <Button onClick={() => setOpen(true)}>Close FY 2025-26…</Button>
            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                title="Close FY 2025-26?"
                description={CLOSE_COPY}
                confirmPhrase="CLOSE FY 2025-26"
                confirmLabel="Close FY"
                loading={busy}
                onConfirm={() => {
                    setBusy(true);
                    window.setTimeout(() => {
                        setBusy(false);
                        setOpen(false);
                        toast.success('FY 2025-26 closed');
                    }, 900);
                }}
            />
        </>
    );
}

export default {
    name: 'ConfirmDialog',
    group: 'Feedback and overlays',
    order: 4,
    subtitle: 'Simple confirm, and type-to-confirm for irreversible actions',
    render: () => (
        <>
            <ConfirmPanel
                icon={Trash2}
                title="Delete this expense?"
                description={DELETE_COPY}
                cancelLabel="Keep it"
                confirmLabel="Delete expense"
                style={{ width: '100%' }}
            />
            <ConfirmPanel
                title="Close FY 2025-26?"
                description={CLOSE_COPY}
                confirmPhrase="CLOSE FY 2025-26"
                typed="CLOSE FY 2025-2"
                inputClassName="is-focus"
                confirmLabel="Close FY"
                style={{ width: '100%' }}
            />
            <PvRow>
                <LiveDelete />
                <LiveCloseFy />
            </PvRow>
        </>
    ),
} satisfies GallerySection;
