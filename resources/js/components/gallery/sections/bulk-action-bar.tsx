import { Check } from 'lucide-react';
import { useState } from 'react';
import { PvNote, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Button } from '@/components/ui/button';
import { BulkActionBar } from '@/components/ui/bulk-action-bar';
import { toast } from '@/components/ui/toast';

const noop = () => undefined;
const NOUN: [string, string] = ['expense', 'expenses'];

function LiveBar() {
    const [count, setCount] = useState(3);
    const [busy, setBusy] = useState(false);

    if (count === 0) {
        return (
            <PvRow>
                <Button size="sm" onClick={() => setCount(3)}>
                    Select 3 rows again
                </Button>
            </PvRow>
        );
    }

    return (
        <BulkActionBar
            count={count}
            noun={NOUN}
            totalPaise={1_04_200_00}
            busy={busy}
            busyLabel={`Approving ${count} expenses…`}
            primary={{
                label: 'Approve',
                icon: Check,
                onClick: () => {
                    setBusy(true);
                    window.setTimeout(() => {
                        setBusy(false);
                        setCount(0);
                        toast.success('3 expenses approved');
                    }, 1200);
                },
            }}
            actions={[
                { label: 'Reject', onClick: noop },
                {
                    label: 'Export',
                    onClick: () => toast.info('Exporting 3 expenses'),
                },
            ]}
            onClear={() => setCount(0)}
        />
    );
}

export default {
    name: 'BulkActionBar',
    group: 'Feedback and overlays',
    order: 7,
    subtitle: 'Appears above a table when rows are selected',
    render: () => (
        <>
            <BulkActionBar
                count={3}
                noun={NOUN}
                totalPaise={1_04_200_00}
                primary={{ label: 'Approve', icon: Check, onClick: noop }}
                actions={[
                    { label: 'Reject', onClick: noop },
                    { label: 'Export', onClick: noop },
                ]}
                onClear={noop}
            />
            <BulkActionBar
                count={3}
                noun={NOUN}
                busy
                busyLabel="Approving 3 expenses…"
                primary={{ label: 'Approve', icon: Check, onClick: noop }}
                actions={[{ label: 'Reject', onClick: noop }]}
                onClear={noop}
            />
            <PvNote>
                Live: Approve runs for a moment, then a toast reports it
            </PvNote>
            <LiveBar />
        </>
    ),
} satisfies GallerySection;
