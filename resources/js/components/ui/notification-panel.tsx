import { Link } from '@inertiajs/react';
import * as PopoverPrimitive from '@radix-ui/react-popover';
import { ArrowLeftRight, Bell, Lock, Receipt } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId } from 'react';
import type { MouseEvent, ReactElement, ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

/** approval → warning disc, security → negative, money → lime, other → muted. */
export type NotificationKind = 'approval' | 'security' | 'money' | 'other';

export type NotificationItem = {
    id: string | number;
    kind: NotificationKind;
    /** The bold line. Put amounts in `t-money-sm`. */
    title: ReactNode;
    /** Source and age: "Meera Iyer · ₹25,000 · 2h". */
    caption: ReactNode;
    /** Straight to the record. */
    href: string;
    unread: boolean;
    icon?: LucideIcon;
};

const KIND: Record<NotificationKind, { className?: string; icon: LucideIcon }> =
    {
        approval: { className: 'cf-notif-ic--warn', icon: Receipt },
        security: { className: 'cf-notif-ic--neg', icon: Lock },
        money: { className: 'cf-notif-ic--lime', icon: ArrowLeftRight },
        other: { icon: Bell },
    };

export type NotificationPanelProps = {
    items: NotificationItem[];
    onMarkAllRead?: () => void;
    /** Runs when a row is followed (e.g. close the popover, mark it read). */
    onItemClick?: (item: NotificationItem, event: MouseEvent) => void;
    emptyText?: ReactNode;
    className?: string;
    /** Set by NotificationPopover so the dialog is named by the heading. */
    titleId?: string;
};

function PanelContent({
    items,
    onMarkAllRead,
    onItemClick,
    emptyText = 'You’re all caught up. Approvals, vault alerts and settlement reminders show up here.',
    titleId,
}: NotificationPanelProps & { titleId: string }) {
    const unread = items.some((item) => item.unread);

    return (
        <>
            <div className="cf-notif-head">
                <span id={titleId} className="t-title" style={{ fontSize: 16 }}>
                    Notifications
                </span>
                {unread && onMarkAllRead && (
                    <button
                        type="button"
                        className="cf-link"
                        onClick={onMarkAllRead}
                    >
                        Mark all read
                    </button>
                )}
            </div>
            {items.length === 0 ? (
                <p className="t-body t-muted m-0 border-t border-line px-4 py-4">
                    {emptyText}
                </p>
            ) : (
                items.map((item) => {
                    const kind = KIND[item.kind];

                    return (
                        <Link
                            key={item.id}
                            href={item.href}
                            className={cn(
                                'cf-notif-item',
                                item.unread && 'is-unread',
                            )}
                            onClick={(event) => onItemClick?.(item, event)}
                        >
                            <span className={cn('cf-notif-ic', kind.className)}>
                                <Icon icon={item.icon ?? kind.icon} size={16} />
                            </span>
                            <span style={{ flex: 1, minWidth: 0 }}>
                                <span
                                    className="t-body"
                                    style={{
                                        fontWeight: 500,
                                        display: 'block',
                                    }}
                                >
                                    {item.title}
                                </span>
                                <span className="t-caption t-muted">
                                    {item.caption}
                                </span>
                            </span>
                            {item.unread && (
                                <span
                                    className="cf-unread"
                                    role="img"
                                    aria-label="Unread"
                                />
                            )}
                        </Link>
                    );
                })
            )}
        </>
    );
}

/**
 * The bell's panel (`cf-pop cf-notif`, 400px): rows that link straight to the
 * record, unread ones with a blue dot, and Mark all read. Static for the gallery;
 * use NotificationPopover in the top bar.
 */
export function NotificationPanel({
    className,
    titleId,
    ...rest
}: NotificationPanelProps) {
    const ownId = useId();
    const id = titleId ?? ownId;

    return (
        <div
            className={cn('cf-pop cf-notif', className)}
            role="dialog"
            aria-labelledby={id}
        >
            <PanelContent {...rest} titleId={id} />
        </div>
    );
}

export type NotificationPopoverProps = Omit<
    NotificationPanelProps,
    'titleId' | 'className'
> & {
    /** The bell button (badge count = unread rows). */
    trigger: ReactElement;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};

/** NotificationPanel opened from the top-bar bell. Esc or an outside click closes it. */
export function NotificationPopover({
    trigger,
    open,
    onOpenChange,
    ...rest
}: NotificationPopoverProps) {
    const titleId = useId();

    return (
        <PopoverPrimitive.Root open={open} onOpenChange={onOpenChange}>
            <PopoverPrimitive.Trigger asChild>
                {trigger}
            </PopoverPrimitive.Trigger>
            <PopoverPrimitive.Portal>
                <PopoverPrimitive.Content
                    align="end"
                    sideOffset={8}
                    collisionPadding={16}
                    aria-labelledby={titleId}
                    className="cf-pop cf-notif z-50 max-w-[calc(100vw-32px)] animate-[cfFade_var(--duration-panel)_var(--ease-out)] focus-visible:shadow-pop"
                >
                    <PanelContent {...rest} titleId={titleId} />
                </PopoverPrimitive.Content>
            </PopoverPrimitive.Portal>
        </PopoverPrimitive.Root>
    );
}
