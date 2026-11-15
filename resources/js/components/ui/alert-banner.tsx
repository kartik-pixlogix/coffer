import { Check, Info, Scale, TriangleAlert } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { formatInr } from '@/lib/format';
import type { MoneyFormatOptions } from '@/lib/format';
import { cn } from '@/lib/utils';

export type AlertVariant = 'graphite' | 'warn' | 'neg' | 'info' | 'pos';

const DEFAULT_ICON: Record<AlertVariant, LucideIcon> = {
    graphite: Scale,
    warn: TriangleAlert,
    neg: TriangleAlert,
    info: Info,
    pos: Check,
};

export type AlertBannerProps = {
    /**
     * graphite = money owed between partners or sides (lime disc), warn = out of balance
     * or expiring, neg = security and failures, info = offline and notices, pos = all clear.
     */
    variant: AlertVariant;
    /** One-line version: a 32 px disc and `children` as a single body line. */
    slim?: boolean;
    icon?: LucideIcon;
    /** Glyph stroke (the design uses 2 for the check). */
    iconStrokeWidth?: number;
    /** Bold line with the figure (use AlertMoney for amounts). Not shown when slim. */
    title?: ReactNode;
    /** Something beside the title, e.g. an OWES status pill. */
    badge?: ReactNode;
    /** Context line under the title, or the whole line when slim. */
    children?: ReactNode;
    /** The fixing action, on the right. */
    action?: ReactNode;
    /** `alert` only for problems that appear while the person is on the page. */
    role?: 'alert' | 'status';
    /** Names the region when it has no live role ("Settlement alert"). */
    'aria-label'?: string;
    className?: string;
    /** Layout-only styles (e.g. `flex: 1` in a row of slim banners). */
    style?: CSSProperties;
};

/**
 * A situation on the page that needs action: icon disc, one bold line with the figure,
 * one line of context, and the fixing action on the right (`cf-alert`).
 */
export function AlertBanner({
    variant,
    slim = false,
    icon,
    iconStrokeWidth,
    title,
    badge,
    children,
    action,
    role,
    'aria-label': ariaLabel,
    className,
    style,
}: AlertBannerProps) {
    const Element = slim ? 'div' : 'section';
    const glyph = (
        <span className="cf-alert-ic">
            <Icon
                icon={icon ?? DEFAULT_ICON[variant]}
                size={slim ? 16 : 22}
                strokeWidth={iconStrokeWidth}
            />
        </span>
    );
    const titleLine = <span className="cf-alert-title">{title}</span>;

    return (
        <Element
            className={cn(
                'cf-alert',
                `cf-alert--${variant}`,
                slim && 'cf-alert--slim',
                className,
            )}
            role={role}
            aria-label={ariaLabel}
            style={style}
        >
            {slim ? (
                <div className="cf-alert-main" style={{ flexBasis: 200 }}>
                    {glyph}
                    <span className="t-body">{children}</span>
                </div>
            ) : (
                <div className="cf-alert-main">
                    {glyph}
                    <div>
                        {badge !== undefined ? (
                            <div
                                className="cf-row"
                                style={{ flexWrap: 'wrap' }}
                            >
                                {titleLine}
                                {badge}
                            </div>
                        ) : (
                            <div className="cf-alert-title">{title}</div>
                        )}
                        {children !== undefined && (
                            <div className="cf-alert-sub">{children}</div>
                        )}
                    </div>
                </div>
            )}
            {action}
        </Element>
    );
}

/** An amount inside an alert title: `t-money` at the title's 16 px. */
export function AlertMoney({
    paise,
    decimals,
}: {
    paise: number;
    decimals?: MoneyFormatOptions['decimals'];
}) {
    return (
        <span className="t-money" style={{ fontSize: 16 }}>
            {formatInr(paise, { decimals })}
        </span>
    );
}
