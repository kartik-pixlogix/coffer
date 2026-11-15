import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Length = number | string;

export type SkeletonProps = {
    /** `line` = 12 px text line, `title` = 18 px × 40 %, `circle` = round (avatars, icon chips). */
    variant?: 'block' | 'line' | 'title' | 'circle';
    width?: Length;
    height?: Length;
    /** Fully rounded ends, for bar and pill placeholders. */
    pill?: boolean;
    className?: string;
    /** Layout-only styles (e.g. `flex: 1` for a bar in a row). */
    style?: CSSProperties;
    'aria-label'?: string;
};

/**
 * Loading placeholder (`cf-skel`) shaped like the content it stands for. The shimmer
 * stops under reduced motion (in the stylesheet). Mark the region with SkeletonGroup.
 */
export function Skeleton({
    variant = 'block',
    width,
    height,
    pill = false,
    className,
    style,
    'aria-label': ariaLabel,
}: SkeletonProps) {
    return (
        <span
            className={cn(
                'cf-skel',
                variant !== 'block' && `cf-skel--${variant}`,
                className,
            )}
            style={{
                width,
                height,
                ...(pill ? { borderRadius: 'var(--radius-full)' } : undefined),
                ...style,
            }}
            aria-label={ariaLabel}
        />
    );
}

export type SkeletonLine = Length | { width: Length; height?: Length };

export type SkeletonTextProps = {
    /** One entry per line: a width, or a width and height. Default three lines. */
    lines?: SkeletonLine[];
    className?: string;
    style?: CSSProperties;
};

/** Stacked text lines (6 px apart), e.g. a list row's name and caption. */
export function SkeletonText({
    lines = ['100%', '80%', '60%'],
    className,
    style,
}: SkeletonTextProps) {
    return (
        <span
            className={className}
            style={{
                display: 'flex',
                flexDirection: 'column',
                gap: 6,
                ...style,
            }}
        >
            {lines.map((line, i) => {
                const { width, height } =
                    typeof line === 'object' ? line : { width: line };

                return (
                    <Skeleton
                        key={i}
                        variant="line"
                        width={width}
                        height={height}
                    />
                );
            })}
        </span>
    );
}

export type SkeletonGroupProps = {
    /** What is loading: "Loading dashboard". */
    'aria-label': string;
    children: ReactNode;
    className?: string;
    style?: CSSProperties;
};

/** The loading region: `aria-busy="true"` with a label. Keep real headings outside it. */
export function SkeletonGroup({
    'aria-label': ariaLabel,
    children,
    className,
    style,
}: SkeletonGroupProps) {
    return (
        <div
            className={className}
            style={style}
            aria-busy="true"
            aria-label={ariaLabel}
        >
            {children}
        </div>
    );
}
