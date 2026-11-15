import { Bell } from 'lucide-react';
import type { ButtonHTMLAttributes, Ref } from 'react';
import { Icon } from '@/components/ui/icon';
import { NotificationPopover } from '@/components/ui/notification-panel';
import type { NotificationItem } from '@/components/ui/notification-panel';
import { placeholderUnreadNotifications } from '@/lib/placeholder-shell';

/** Phase 8 fills this from the server (spec: notifications). */
const items: NotificationItem[] = [];

type BellProps = ButtonHTMLAttributes<HTMLButtonElement> & {
    unread: number;
    ref?: Ref<HTMLButtonElement>;
};

/** 54px bell with the unread badge; spreads the props Radix passes through `asChild`. */
function Bell54({ unread, ...rest }: BellProps) {
    return (
        <button
            type="button"
            className="cf-iconbtn cf-iconbtn--lg"
            aria-label={
                unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'
            }
            {...rest}
        >
            <Icon icon={Bell} size={22} />
            {unread > 0 && (
                <span
                    className="cf-badge"
                    style={{ top: 7, right: 7, border: 0 }}
                    aria-hidden="true"
                >
                    {unread}
                </span>
            )}
        </button>
    );
}

/**
 * Top-bar bell opening the NotificationPanel. Phase 1: an empty list with the
 * placeholder unread count; Phase 8 wires real notifications and Mark all read.
 */
export function NotificationsButton() {
    const unread = placeholderUnreadNotifications;

    return (
        <NotificationPopover
            items={items}
            trigger={<Bell54 unread={unread} />}
        />
    );
}
