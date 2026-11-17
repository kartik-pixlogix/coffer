import { Check, Plus } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { cn } from '@/lib/utils';

export type Faq = readonly [question: string, answer: string];

/**
 * The FAQ accordion of Landing / Pricing: one open at a time (the first to start), the
 * plus turns into a cross, `aria-expanded` / `aria-controls` on each question.
 */
export function FaqList({
    faqs,
    size = 'md',
}: {
    faqs: readonly Faq[];
    size?: 'md' | 'sm';
}) {
    const [open, setOpen] = useState(0);
    const base = useId();

    return (
        <div>
            {faqs.map(([question, answer], index) => {
                const id = `${base}-faq-${index}`;
                const isOpen = open === index;

                return (
                    <div
                        key={question}
                        className={cn('lp-faq', size === 'sm' && 'lp-faq--sm')}
                    >
                        <h3>
                            <button
                                type="button"
                                aria-expanded={isOpen}
                                aria-controls={id}
                                onClick={() => setOpen(isOpen ? -1 : index)}
                            >
                                {question}
                                <Icon icon={Plus} size={20} />
                            </button>
                        </h3>
                        {isOpen && <p id={id}>{answer}</p>}
                    </div>
                );
            })}
        </div>
    );
}

/** The design's check-mark list (`lp-ul`). */
export function CheckList({
    items,
    inherit = false,
}: {
    items: readonly string[];
    /** Take the card's text colour (lime / graphite cards). */
    inherit?: boolean;
}) {
    return (
        <ul className={cn('lp-ul', inherit && 'lp-ul--inherit')}>
            {items.map((item) => (
                <li key={item}>
                    <Icon icon={Check} size={16} strokeWidth={2.25} />
                    <span className="t-body">{item}</span>
                </li>
            ))}
        </ul>
    );
}

/** easeOutQuart, as in the design prototypes. */
const ease = (x: number) => 1 - Math.pow(1 - x, 4);

/**
 * A figure that counts up from zero once it scrolls into view (duration-count). The
 * server render and reduced motion show the final text, so crawlers and screen readers
 * always read the real figure; the animated copy is aria-hidden.
 */
export function CountUp({
    value,
    format,
    className,
    style,
}: {
    value: number;
    format: (value: number) => string;
    className?: string;
    style?: CSSProperties;
}) {
    const reduced = useReducedMotion();
    const ref = useRef<HTMLSpanElement>(null);
    const [shown, setShown] = useState<number | null>(null);

    useEffect(() => {
        const node = ref.current;

        if (reduced || node === null) {
            setShown(null);

            return;
        }

        let frame = 0;
        let start = 0;
        const step = (now: number) => {
            start ||= now;
            const x = Math.min(1, (now - start) / 900);
            setShown(Math.round(value * ease(x)));

            if (x < 1) {
                frame = requestAnimationFrame(step);
            }
        };

        setShown(0);

        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry?.isIntersecting) {
                    observer.disconnect();
                    frame = requestAnimationFrame(step);
                }
            },
            { threshold: 0.3 },
        );
        observer.observe(node);

        return () => {
            observer.disconnect();
            cancelAnimationFrame(frame);
        };
    }, [value, reduced]);

    const text = format(value);

    return (
        <span ref={ref} className={className} style={style}>
            {shown === null ? (
                text
            ) : (
                <>
                    <span className="sr-only">{text}</span>
                    <span aria-hidden="true">{format(shown)}</span>
                </>
            )}
        </span>
    );
}

/** Section heading row: title left, an action right (wraps on phones). */
export function SectionHead({
    title,
    id,
    action,
    width = 620,
}: {
    title: ReactNode;
    id: string;
    action?: ReactNode;
    width?: number;
}) {
    return (
        <div className="lp-head-row">
            <h2 id={id} className="lp-h2" style={{ maxWidth: width }}>
                {title}
            </h2>
            {action}
        </div>
    );
}
