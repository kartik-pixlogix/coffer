import { X } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { Money } from '@/components/ui/money';
import { cn } from '@/lib/utils';

export type BulkAction = {
    /** A verb: "Approve", "Reject", "Export". */
    label: string;
    icon?: LucideIcon;
    onClick: () => void;
    disabled?: boolean;
};

export type BulkActionBarProps = {
    /** Selected rows. */
    count: number;
    /** What is selected, singular and plural: ["expense", "expenses"]. */
    noun: [one: string, other: string];
    /** Sum of the selected rows, in paise. */
    totalPaise?: number;
    /** The one lime action that applies to every selected row. */
    primary: BulkAction;
    /** Glass actions after it. */
    actions?: BulkAction[];
    onClear: () => void;
    /** The action is running: buttons disable, the main one spins. */
    busy?: boolean;
    /** Shown in place of the count while busy: "Approving 3 expenses…". */
    busyLabel?: string;
    className?: string;
};

/**
 * Graphite bar that replaces the table toolbar while rows are selected: count and
 * total, a lime main action, glass secondary actions and Clear selection.
 * Report the outcome with a toast afterwards.
 */
export function BulkActionBar({
    count,
    noun,
    totalPaise,
    primary,
    actions = [],
    onClear,
    busy = false,
    busyLabel,
    className,
}: BulkActionBarProps) {
    const what = count === 1 ? noun[0] : noun[1];
    const busyText = busyLabel ?? `Working on ${count} ${what}…`;

    return (
        <div
            className={cn('cf-bulkbar', className)}
            role="region"
            aria-label={busy ? busyText : `${count} ${what} selected`}
            aria-busy={busy || undefined}
        >
            <span className="cf-bulkbar-count">
                {busy ? (
                    busyText
                ) : (
                    <>
                        {count} selected
                        {totalPaise !== undefined && (
                            <>
                                {' · '}
                                <Money
                                    paise={totalPaise}
                                    size="sm"
                                    tone="none"
                                    className="text-on-graphite"
                                />
                            </>
                        )}
                    </>
                )}
            </span>
            <Button
                variant="lime"
                size="sm"
                onClick={primary.onClick}
                disabled={busy || primary.disabled}
                loading={busy}
            >
                {!busy && primary.icon && (
                    <Icon icon={primary.icon} size={14} strokeWidth={2} />
                )}
                {primary.label}
            </Button>
            {actions.map((action) => (
                <Button
                    key={action.label}
                    variant="glass"
                    size="sm"
                    icon={action.icon}
                    onClick={action.onClick}
                    disabled={busy || action.disabled}
                >
                    {action.label}
                </Button>
            ))}
            {!busy && (
                <IconButton
                    icon={X}
                    size="sm"
                    variant="glass"
                    iconSize={14}
                    aria-label="Clear selection"
                    onClick={onClear}
                />
            )}
        </div>
    );
}
