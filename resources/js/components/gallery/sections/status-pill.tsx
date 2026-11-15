import { PvGrid, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { StatusPill } from '@/components/ui/status-pill';
import type { StatusPreset } from '@/components/ui/status-pill';

const GRID: StatusPreset[] = [
    'approved',
    'pending',
    'rejected',
    'billable',
    'nonBillable',
    'owes',
    'owed',
    'settled',
    'auto',
    'production',
    'staging',
    'dev',
];

export default {
    name: 'StatusPill',
    group: 'Data and charts',
    order: 1,
    subtitle:
        'Approved, Pending, Rejected, Billable, Non-billable, OWES, OWED, Settled, Auto, Production, Staging, Dev',
    render: () => (
        <>
            <PvGrid
                columns="repeat(3,minmax(0,1fr))"
                style={{ justifyItems: 'start' }}
            >
                {GRID.map((status) => (
                    <StatusPill key={status} status={status} />
                ))}
            </PvGrid>
            <PvRow>
                <StatusPill size="sm" tone="pos">
                    Small
                </StatusPill>
                <StatusPill status="new" />
                <StatusPill status="breached" />
                <StatusPill tone="warn">Expires in 9 days</StatusPill>
                <span
                    className="cf-skel rounded-full"
                    style={{ width: 76, height: 24 }}
                    aria-label="Loading"
                />
            </PvRow>
        </>
    ),
} satisfies GallerySection;
