import { PvGrid } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Textarea } from '@/components/ui/textarea';

const LONG_NOTE = (
    'The webhook secret is a custom field on this item. Rotate it with the live keys ' +
    'and update Razorpay before the old one is revoked. '
)
    .repeat(5)
    .slice(0, 511)
    .concat('…');

export default {
    name: 'Textarea',
    group: 'Forms',
    order: 2,
    subtitle: 'Notes and longer descriptions',
    render: () => (
        <PvGrid
            columns="repeat(2,minmax(0,1fr))"
            style={{ alignItems: 'start' }}
        >
            <Textarea
                label="Default"
                placeholder="Add a note for the partners"
            />
            <Textarea
                label="Focus"
                className="cf-input is-focus"
                style={{ height: 'auto' }}
                defaultValue="Break-glass only. Daily work goes through IAM users with their own MFA."
            />
            <Textarea
                label="Disabled"
                defaultValue="Locked after the FY was closed."
                disabled
            />
            {/* Live: delete text to get under 500 and the error clears. */}
            <Textarea label="Error" limit={500} defaultValue={LONG_NOTE} />
        </PvGrid>
    ),
} satisfies GallerySection;
