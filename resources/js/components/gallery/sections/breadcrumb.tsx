import type { GallerySection } from '@/components/gallery/types';
import { Breadcrumb } from '@/components/ui/breadcrumb';

export default {
    name: 'Breadcrumb',
    group: 'Feedback and overlays',
    order: 1,
    subtitle: 'Group / company / page above every page title',
    render: () => (
        <div>
            <Breadcrumb
                items={[
                    { label: 'Vertex Group', href: '#' },
                    { label: 'Saarthi Portal JV', href: '#' },
                    { label: 'Settings', href: '#' },
                    { label: 'Roles & permissions' },
                ]}
            />
            <h1 className="cf-h1">
                <span className="t-display-light">Settings</span>
                <span
                    className="t-display"
                    style={{ fontSize: 36, lineHeight: '40px' }}
                >
                    Roles &amp; permissions
                </span>
            </h1>
        </div>
    ),
} satisfies GallerySection;
