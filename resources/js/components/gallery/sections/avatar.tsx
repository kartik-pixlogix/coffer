import { RotateCw } from 'lucide-react';
import { PvNote, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Avatar } from '@/components/ui/avatar';
import { Skeleton } from '@/components/ui/skeleton';

export default {
    name: 'Avatar',
    group: 'Data and charts',
    order: 1,
    subtitle: 'Initials with the partner colour dot · 28, 36, 48, 64 px, tiles',
    render: () => (
        <>
            <PvRow>
                <Avatar
                    name="Aarav Mehta"
                    label="Aarav Mehta, Vertex Labs"
                    size="sm"
                    partner={1}
                />
                <Avatar
                    name="Ishita Rao"
                    label="Ishita Rao, Vertex Labs"
                    partner={2}
                />
                <Avatar
                    name="Kabir Shah"
                    label="Kabir Shah, Bluefin Infotech"
                    size="lg"
                    partner={3}
                />
                <Avatar
                    name="Meera Iyer"
                    label="Meera Iyer, Bluefin Infotech"
                    size="xl"
                    partner={4}
                />
                <Avatar name="Saarthi Portal JV" graphite />
                <Avatar name="GitHub" initials="GH" tile graphite />
                <Avatar name="Razorpay" initials="RZ" tile />
                <Avatar icon={RotateCw} label="Payroll, automatic" />
                <Skeleton
                    variant="circle"
                    width={36}
                    height={36}
                    aria-label="Loading"
                />
            </PvRow>
            <PvNote>
                Partner dots: Aarav partner-1, Ishita partner-2, Kabir
                partner-3, Meera partner-4; team members take the dot of the
                partner they bill to. Graphite tiles are companies and vault
                providers.
            </PvNote>
        </>
    ),
} satisfies GallerySection;
