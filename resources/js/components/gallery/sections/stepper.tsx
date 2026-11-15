import type { GallerySection } from '@/components/gallery/types';
import { Stepper } from '@/components/ui/stepper';

export default {
    name: 'Stepper',
    group: 'Actions and navigation',
    order: 7,
    subtitle: 'Create company and bulk import progress',
    render: () => (
        <>
            <Stepper
                aria-label="Create company"
                steps={['Company & partners', 'Team', 'Review & launch']}
                current={1}
            />
            <Stepper
                aria-label="Bulk import"
                steps={['Upload', 'Map columns', 'Preview']}
                current={2}
            />
        </>
    ),
} satisfies GallerySection;
