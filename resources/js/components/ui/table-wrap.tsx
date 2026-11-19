import { useEffect, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * `cf-table-wrap`: a wide table scrolls sideways inside its card. While it
 * overflows, the wrapper joins the tab order so keyboard users can scroll it
 * (WCAG 2.1.1, axe "scrollable-region-focusable", D91), and `is-overflowing` pins
 * the lead column and fades the edge that has more to show (D128).
 */
export function TableWrap({
    label,
    className,
    style,
    children,
}: {
    /** Names the scroll region, e.g. "Members table". */
    label?: string;
    className?: string;
    style?: CSSProperties;
    children: ReactNode;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const [overflows, setOverflows] = useState(false);

    useEffect(() => {
        const el = ref.current;

        if (el === null || typeof ResizeObserver === 'undefined') {
            return;
        }

        const check = () => setOverflows(el.scrollWidth > el.clientWidth + 1);
        const observer = new ResizeObserver(check);
        observer.observe(el);

        if (el.firstElementChild !== null) {
            observer.observe(el.firstElementChild);
        }

        check();

        return () => observer.disconnect();
    }, []);

    return (
        <div
            ref={ref}
            className={cn(
                'cf-table-wrap',
                overflows && 'is-overflowing',
                className,
            )}
            style={style}
            tabIndex={overflows ? 0 : undefined}
            role={overflows && label !== undefined ? 'region' : undefined}
            aria-label={overflows ? label : undefined}
        >
            {children}
        </div>
    );
}
