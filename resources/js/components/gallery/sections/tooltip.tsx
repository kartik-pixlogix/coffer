import { Coins, Info } from 'lucide-react';
import type { CSSProperties } from 'react';
import { PvNote, PvRow } from '@/components/gallery/preview';
import type { GallerySection } from '@/components/gallery/types';
import { Icon } from '@/components/ui/icon';
import { Tooltip, TooltipPanel } from '@/components/ui/tooltip';

/** The preview's `.t` column: tooltip above its trigger. */
const STACK: CSSProperties = {
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    gap: 10,
};

const DOTTED: CSSProperties = {
    textDecoration: 'underline dotted',
    textUnderlineOffset: 3,
};

export default {
    name: 'Tooltip',
    group: 'Feedback and overlays',
    order: 1,
    subtitle: 'Short label for an icon or a truncated value',
    render: (theme) => {
        const id = (n: number) => `tt${n}-${theme}`;

        return (
            <>
                <PvRow style={{ gap: 40, alignItems: 'flex-end' }}>
                    <div style={STACK}>
                        <TooltipPanel id={id(1)}>Partner Loans</TooltipPanel>
                        <button
                            type="button"
                            className="cf-rail-item is-hover"
                            aria-describedby={id(1)}
                            aria-label="Partner Loans"
                        >
                            <Icon icon={Coins} size={20} />
                        </button>
                    </div>
                    <div style={STACK}>
                        <TooltipPanel id={id(2)}>
                            Rotated 28 Sep 2026 by Ishita Rao
                        </TooltipPanel>
                        <span
                            className="cf-pill"
                            tabIndex={0}
                            aria-describedby={id(2)}
                        >
                            Strong
                        </span>
                    </div>
                    <div style={STACK}>
                        <TooltipPanel id={id(3)}>
                            <span className="t-money-sm">₹12,45,775</span> held
                            of <span className="t-money-sm">₹9,21,175</span>{' '}
                            entitled
                        </TooltipPanel>
                        <span
                            className="t-money"
                            tabIndex={0}
                            aria-describedby={id(3)}
                            style={DOTTED}
                        >
                            ₹3,24,600
                        </span>
                    </div>
                </PvRow>

                <PvRow style={{ gap: 40, alignItems: 'center' }}>
                    <Tooltip label="Partner Loans">
                        <button
                            type="button"
                            className="cf-rail-item"
                            aria-label="Partner Loans"
                        >
                            <Icon icon={Coins} size={20} />
                        </button>
                    </Tooltip>
                    <Tooltip
                        label={
                            <>
                                <span className="t-money-sm">₹12,45,775</span>{' '}
                                held of{' '}
                                <span className="t-money-sm">₹9,21,175</span>{' '}
                                entitled
                            </>
                        }
                    >
                        <span className="t-money" tabIndex={0} style={DOTTED}>
                            ₹3,24,600
                        </span>
                    </Tooltip>
                    <Tooltip label="Billable hours bill the client at the partner rate">
                        <button
                            type="button"
                            className="cf-iconbtn cf-iconbtn--sm cf-iconbtn--bare"
                            aria-label="About billable hours"
                        >
                            <Icon icon={Info} size={15} />
                        </button>
                    </Tooltip>
                    <PvNote>Live: hover or Tab to these (300 ms delay)</PvNote>
                </PvRow>
            </>
        );
    },
} satisfies GallerySection;
