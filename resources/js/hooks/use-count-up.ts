import { useEffect, useRef, useState } from 'react';
import { useReducedMotion } from '@/hooks/use-reduced-motion';

/** duration-count token. */
export const COUNT_UP_MS = 900;

/** easeOutQuart, as in the design prototypes. */
const ease = (x: number) => 1 - Math.pow(1 - x, 4);

/**
 * Animate a figure from 0 to `target` over duration-count on first render and
 * from the previous value on change. Integer in, integer out (paise stay paise).
 * Under reduced motion the final value shows at once.
 */
export function useCountUp(
    target: number,
    enabled = true,
    duration = COUNT_UP_MS,
): number {
    const reduced = useReducedMotion();
    const animate = enabled && !reduced;
    const [value, setValue] = useState(animate ? 0 : target);
    const from = useRef(animate ? 0 : target);

    useEffect(() => {
        if (!animate) {
            from.current = target;
            setValue(target);

            return;
        }

        const start = performance.now();
        const origin = from.current;
        let frame = 0;

        const step = (now: number) => {
            const x = Math.min(1, (now - start) / duration);
            const next = Math.round(origin + (target - origin) * ease(x));

            from.current = next;
            setValue(next);

            if (x < 1) {
                frame = requestAnimationFrame(step);
            }
        };

        frame = requestAnimationFrame(step);

        return () => cancelAnimationFrame(frame);
    }, [target, animate, duration]);

    return value;
}
