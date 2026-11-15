import { Plus, TrendingUp } from 'lucide-react';
import type { GallerySection } from '@/components/gallery/types';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';

export default {
    name: 'EmptyState',
    group: 'Feedback and overlays',
    order: 21,
    subtitle: 'First-run module and no-results variants',
    render: () => (
        <EmptyState
            icon={TrendingUp}
            title="No income yet"
            description="Record what a client paid and Coffer splits it across partners and updates the dashboard and settlements."
            actions={
                <>
                    <Button variant="primary" disc={Plus}>
                        Add income
                    </Button>
                    <Button variant="secondary">Import CSV</Button>
                </>
            }
        />
    ),
} satisfies GallerySection;
