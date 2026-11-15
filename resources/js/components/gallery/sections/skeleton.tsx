import type { CSSProperties } from 'react';
import { PvNote } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import {
    Skeleton,
    SkeletonGroup,
    SkeletonText,
} from '@/components/ui/skeleton';

const CARD: CSSProperties = {
    flex: 1,
    display: 'flex',
    flexDirection: 'column',
    gap: 12,
};

const BARS = ['40%', '70%', '55%', '90%'];

export default {
    name: 'Skeleton',
    group: 'Feedback and overlays',
    order: 20,
    subtitle: 'Loading placeholders shaped like the content',
    render: () => (
        <>
            <SkeletonGroup
                aria-label="Loading dashboard"
                className="pv-row"
                style={{ alignItems: 'stretch' }}
            >
                <div className="cf-card" style={{ ...CARD, minHeight: 130 }}>
                    <Skeleton variant="circle" width={32} height={32} />
                    <Skeleton width="70%" height={32} />
                    <Skeleton variant="line" width="45%" />
                </div>
                <div className="cf-card" style={CARD}>
                    <Skeleton variant="title" />
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'flex-end',
                            gap: 10,
                            height: 70,
                        }}
                    >
                        {BARS.map((height) => (
                            <Skeleton
                                key={height}
                                height={height}
                                pill
                                style={{ flex: 1 }}
                            />
                        ))}
                    </div>
                </div>
            </SkeletonGroup>
            <div
                className="cf-inset"
                style={{ display: 'flex', alignItems: 'center', gap: 12 }}
            >
                <Skeleton variant="circle" width={36} height={36} />
                <SkeletonText
                    lines={['55%', { width: '35%', height: 10 }]}
                    style={{ flex: 1 }}
                />
                <Skeleton width={70} height={24} pill />
            </div>
            <PvNote>
                Shimmer runs 1.4 s on ease-drift; with reduced motion the blocks
                stay still.
            </PvNote>
        </>
    ),
} satisfies GallerySection;
