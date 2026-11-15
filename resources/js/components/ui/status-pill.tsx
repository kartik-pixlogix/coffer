import {
    ArrowUp,
    Check,
    Clock,
    Minus,
    Plus,
    RotateCw,
    TriangleAlert,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { HTMLAttributes, ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

export type StatusTone =
    | 'neutral'
    | 'pos'
    | 'warn'
    | 'neg'
    | 'info'
    | 'graphite'
    | 'outline'
    | 'lime';

type Preset = {
    tone: StatusTone;
    label: string;
    icon?: LucideIcon;
    iconSize?: number;
    micro?: boolean;
};

/** The statuses Coffer shows everywhere, with the icon and word from the design. */
export const STATUS_PRESETS = {
    approved: { tone: 'pos', label: 'Approved', icon: Check },
    pending: { tone: 'warn', label: 'Pending', icon: Clock },
    rejected: { tone: 'neg', label: 'Rejected', icon: X },
    billable: { tone: 'info', label: 'Billable', icon: Plus },
    nonBillable: { tone: 'neutral', label: 'Non-billable', icon: Minus },
    owes: { tone: 'neg', label: 'OWES', icon: TriangleAlert, micro: true },
    owed: { tone: 'pos', label: 'OWED', icon: ArrowUp, micro: true },
    settled: { tone: 'pos', label: 'SETTLED', icon: Check, micro: true },
    auto: { tone: 'neutral', label: 'Auto', icon: RotateCw, iconSize: 12 },
    production: { tone: 'graphite', label: 'Production' },
    staging: { tone: 'warn', label: 'Staging' },
    dev: { tone: 'outline', label: 'Dev' },
    new: { tone: 'lime', label: 'New' },
    breached: { tone: 'neg', label: 'Breached' },
} as const satisfies Record<string, Preset>;

export type StatusPreset = keyof typeof STATUS_PRESETS;

export type StatusPillProps = {
    /** A preset status (icon, word and tone from the design). Other props override it. */
    status?: StatusPreset;
    tone?: StatusTone;
    icon?: LucideIcon;
    iconSize?: number;
    /** `--sm`: 20 px, for table rows next to a title. */
    size?: 'md' | 'sm';
    /** `--micro`: caps, for settlement states (OWES, OWED, SETTLED). */
    micro?: boolean;
    /** The word. Defaults to the preset's label. */
    children?: ReactNode;
} & Omit<HTMLAttributes<HTMLSpanElement>, 'children'>;

/**
 * Status pill (`cf-pill`): the state of a record. Always an icon or a word that
 * carries the meaning without colour. Not a button (filter with Filter chips).
 */
export function StatusPill({
    status,
    tone,
    icon,
    iconSize,
    size = 'md',
    micro,
    className,
    children,
    ...rest
}: StatusPillProps) {
    const preset: Preset | undefined = status
        ? STATUS_PRESETS[status]
        : undefined;
    const finalTone = tone ?? preset?.tone ?? 'neutral';
    const finalIcon = icon ?? preset?.icon;
    const isMicro = micro ?? preset?.micro ?? false;
    const glyphSize =
        iconSize ??
        (size === 'sm' ? 11 : (preset?.iconSize ?? (isMicro ? 12 : 13)));

    return (
        <span
            className={cn(
                'cf-pill',
                size === 'sm' && 'cf-pill--sm',
                finalTone !== 'neutral' && `cf-pill--${finalTone}`,
                isMicro && 'cf-pill--micro',
                className,
            )}
            {...rest}
        >
            {finalIcon && (
                <Icon icon={finalIcon} size={glyphSize} strokeWidth={2} />
            )}
            {children ?? preset?.label}
        </span>
    );
}
