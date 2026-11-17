import { motion } from 'motion/react';
import type { HTMLMotionProps, Transition, Variants } from 'motion/react';
import { useSyncExternalStore } from 'react';

/**
 * Marketing motion (design-system README "Motion", D103): sections and cards reveal as
 * they scroll into view (fade + slide, staggered), cards lift 3px on hover. Built on
 * `motion`.
 *
 * The marketing pages are server-rendered (D83), so the hidden start state is part of
 * the server HTML and the reveal plays once the page hydrates. Every revealed element
 * carries `data-reveal`: under prefers-reduced-motion, and without JavaScript
 * (app.blade.php), CSS pins it to its final look, so nothing moves or stays hidden.
 */

/** ease-out token (cubic-bezier(0.22, 1, 0.36, 1)). */
export const EASE_OUT = [0.22, 1, 0.36, 1] as const;

/** duration-hero for reveals, duration-ui for hover lifts (seconds). */
const REVEAL: Transition = { duration: 0.8, ease: EASE_OUT };
const LIFT: Transition = { duration: 0.2, ease: EASE_OUT };

const VIEWPORT = { once: true, amount: 0.15, margin: '0px 0px -8% 0px' };

const TAGS = {
    div: motion.div,
    section: motion.section,
    article: motion.article,
    li: motion.li,
    ul: motion.ul,
    ol: motion.ol,
    p: motion.p,
} as const;

type RevealTag = keyof typeof TAGS;

type BaseProps = Omit<
    HTMLMotionProps<'div'>,
    | 'initial'
    | 'animate'
    | 'exit'
    | 'whileInView'
    | 'whileHover'
    | 'variants'
    | 'viewport'
    | 'transition'
> & {
    as?: RevealTag;
    /** Lift 3px on hover (cards). */
    lift?: boolean;
};

const HOVER = { y: -3 };

/** One element that fades and slides up as it enters the viewport. */
export function Reveal({
    as = 'div',
    lift = false,
    delay = 0,
    distance = 28,
    fromScale = 1,
    immediate = false,
    ...rest
}: BaseProps & {
    delay?: number;
    distance?: number;
    fromScale?: number;
    /** Play once the page is ready instead of on entering the viewport. */
    immediate?: boolean;
}) {
    const Tag = TAGS[as] as typeof motion.div;
    const shown = {
        opacity: 1,
        y: 0,
        scale: 1,
        transition: { ...REVEAL, delay },
    };

    return (
        <Tag
            data-reveal=""
            initial={{ opacity: 0, y: distance, scale: fromScale }}
            animate={immediate ? shown : undefined}
            whileInView={immediate ? undefined : shown}
            whileHover={lift ? HOVER : undefined}
            viewport={immediate ? undefined : VIEWPORT}
            transition={LIFT}
            {...rest}
        />
    );
}

const group = (stagger: number): Variants => ({
    hidden: {},
    show: { transition: { staggerChildren: stagger } },
});

const item = (distance: number, fromScale: number): Variants => ({
    hidden: { opacity: 0, y: distance, scale: fromScale },
    show: { opacity: 1, y: 0, scale: 1, transition: REVEAL },
});

/** A list or grid whose RevealItem children enter one after another. */
export function RevealGroup({
    as = 'div',
    stagger = 0.08,
    ...rest
}: Omit<BaseProps, 'lift'> & { stagger?: number }) {
    const Tag = TAGS[as] as typeof motion.div;

    return (
        <Tag
            initial="hidden"
            whileInView="show"
            viewport={VIEWPORT}
            variants={group(stagger)}
            {...rest}
        />
    );
}

/** A child of RevealGroup. */
export function RevealItem({
    as = 'div',
    lift = false,
    distance = 28,
    fromScale = 1,
    ...rest
}: BaseProps & { distance?: number; fromScale?: number }) {
    const Tag = TAGS[as] as typeof motion.div;

    return (
        <Tag
            data-reveal=""
            variants={item(distance, fromScale)}
            whileHover={lift ? HOVER : undefined}
            transition={LIFT}
            {...rest}
        />
    );
}

const FINE = '(hover: hover) and (pointer: fine)';

const subscribeFine = (callback: () => void) => {
    const media = window.matchMedia(FINE);
    media.addEventListener('change', callback);

    return () => media.removeEventListener('change', callback);
};

/** True for a mouse or trackpad (cursor effects); false on touch and on the server. */
export function useFinePointer(): boolean {
    return useSyncExternalStore(
        subscribeFine,
        () => window.matchMedia(FINE).matches,
        () => false,
    );
}
