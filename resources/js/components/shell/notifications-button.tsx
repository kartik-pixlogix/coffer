import { Bell } from 'lucide-react';
import type { ButtonHTMLAttributes, Ref } from 'react';
import { Icon } from '@/components/ui/icon';
import { NotificationPopover } from '@/components/ui/notification-panel';
import type { NotificationItem } from '@/components/ui/notification-panel';
/** Phase 8 fills these from the server (spec §19). */
const items: NotificationItem[] = [];
const placeholderUnread = 0;

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
 * Top-bar bell opening the NotificationPanel. Until Phase 8 wires real
 * notifications and Mark all read, the list is empty.
 */
export function NotificationsButton() {
    const unread = placeholderUnread;

    return (
        <NotificationPopover
            items={items}
            trigger={<Bell54 unread={unread} />}
        />
    );
}
