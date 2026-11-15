import { PvNote } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Tab, Tabs, TabsList } from '@/components/ui/tabs';

export default {
    name: 'Tabs',
    group: 'Actions and navigation',
    order: 5,
    subtitle: 'Pill tabs for settings sections and filtered views, with counts',
    render: () => (
        <>
            <Tabs defaultValue="company">
                <TabsList aria-label="Settings">
                    <Tab value="company">Company</Tab>
                    <Tab value="members" className="is-hover">
                        Members &amp; invites
                    </Tab>
                    <Tab value="roles" className="is-focus">
                        Roles
                    </Tab>
                    <Tab value="billing" disabled>
                        Billing
                    </Tab>
                </TabsList>
            </Tabs>
            <Tabs defaultValue="aarav">
                <TabsList aria-label="Team by partner">
                    <Tab value="all" count={8}>
                        All
                    </Tab>
                    <Tab value="aarav" count={3}>
                        Aarav
                    </Tab>
                    <Tab value="kabir" count={3}>
                        Kabir
                    </Tab>
                </TabsList>
            </Tabs>
            <PvNote>
                Selected: ink fill (near-black in light, near-white in dark).
                Hover: surface. Disabled: faint text.
            </PvNote>
        </>
    ),
} satisfies GallerySection;
