import { PvNote, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { AvatarStack } from '@/components/ui/avatar-stack';

export default {
    name: 'AvatarStack',
    group: 'Data and charts',
    order: 2,
    subtitle: 'Overlapping avatars with a +N count',
    render: () => (
        <>
            <PvRow style={{ gap: 28 }}>
                <AvatarStack
                    prefix="Shared with"
                    people={[
                        { name: 'Aarav Mehta', partner: 1 },
                        { name: 'Ishita Rao', partner: 2 },
                        { name: 'Rohan Desai', partner: 1 },
                        { name: 'Sana Qureshi', partner: 2 },
                    ]}
                />
                <AvatarStack
                    prefix="Paid by"
                    size="md"
                    people={[
                        { name: 'Aarav Mehta', partner: 1 },
                        { name: 'Kabir Shah', partner: 3 },
                    ]}
                />
                {/* The preview shows all four here despite the three-avatar rule. */}
                <AvatarStack
                    label="Four partners"
                    size="lg"
                    max={4}
                    people={[
                        { name: 'Aarav Mehta' },
                        { name: 'Ishita Rao' },
                        { name: 'Kabir Shah' },
                        { name: 'Meera Iyer' },
                    ]}
                />
            </PvRow>
            <PvNote>
                Show at most three, then +N. The stack’s label lists everyone.
            </PvNote>
        </>
    ),
} satisfies GallerySection;
