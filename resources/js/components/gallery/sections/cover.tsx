import type { GallerySection } from '@/components/gallery/types';
import { Cover } from '@/components/ui/cover';

export default {
    name: 'Cover',
    group: 'Brand',
    layout: 'stack',
    order: 0,
    render: () => <Cover as="p" />,
} satisfies GallerySection;
