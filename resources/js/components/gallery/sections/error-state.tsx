import type { GallerySection } from '@/components/gallery/types';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';

export default {
    name: 'ErrorState',
    group: 'Feedback and overlays',
    order: 22,
    subtitle: 'A panel or page that failed to load',
    render: () => (
        <ErrorState
            title="Reports didn’t load"
            description="We couldn’t reach the server. Your data is safe; try again in a moment."
            reference="7F3A-21C9"
            actions={
                <>
                    <Button variant="primary">Try again</Button>
                    <Button variant="ghost">Contact support</Button>
                </>
            }
        />
    ),
} satisfies GallerySection;
