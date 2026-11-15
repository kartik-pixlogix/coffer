import {
    Bell,
    Check,
    ChevronLeft,
    Copy,
    Ellipsis,
    LayoutGrid,
    LogOut,
    Moon,
    X,
} from 'lucide-react';
import { PvGrid, PvRow, PvState } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { IconButton } from '@/components/ui/icon-button';

export default {
    name: 'IconButton',
    group: 'Actions and navigation',
    order: 2,
    subtitle:
        'Round icon buttons · 54, 40, 32 · approve, reject, graphite, bare',
    render: () => (
        <>
            <PvGrid columns="repeat(7,minmax(0,1fr))">
                {[
                    'Default',
                    'Hover',
                    'Focus',
                    'Selected',
                    'Disabled',
                    'Loading',
                    'Error',
                ].map((s) => (
                    <PvState key={s}>{s}</PvState>
                ))}
                <IconButton icon={Bell} aria-label="Notifications" />
                <IconButton
                    icon={Bell}
                    aria-label="Notifications"
                    className="is-hover"
                />
                <IconButton
                    icon={Bell}
                    aria-label="Notifications"
                    className="is-focus"
                />
                <IconButton icon={LayoutGrid} aria-label="Grid view" pressed />
                <IconButton
                    icon={ChevronLeft}
                    aria-label="Previous page"
                    disabled
                />
                <IconButton icon={Bell} aria-label="Refreshing" loading />
                <IconButton icon={Copy} aria-label="Copy failed" invalid />
            </PvGrid>
            <PvRow>
                <IconButton
                    icon={Moon}
                    size="lg"
                    aria-label="Switch to dark theme"
                />
                <IconButton
                    icon={Copy}
                    iconSize={16}
                    aria-label="Copy password"
                />
                <IconButton
                    icon={Check}
                    size="sm"
                    variant="pos"
                    aria-label="Approve"
                />
                <IconButton
                    icon={X}
                    size="sm"
                    variant="neg"
                    aria-label="Reject"
                />
                <IconButton
                    icon={LogOut}
                    variant="graphite"
                    aria-label="Sign out"
                />
                <IconButton
                    icon={Ellipsis}
                    size="sm"
                    variant="bare"
                    iconSize={16}
                    strokeWidth={2.5}
                    aria-label="More actions"
                />
                <PvState>
                    54 · 40 · 32 · approve · reject · graphite · bare
                </PvState>
            </PvRow>
        </>
    ),
} satisfies GallerySection;
