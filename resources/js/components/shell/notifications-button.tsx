import { Link, router, usePage } from '@inertiajs/react';
import * as PopoverPrimitive from '@radix-ui/react-popover';
import { Bell, X } from 'lucide-react';
import { useCallback, useEffect, useId, useRef, useState } from 'react';
import type { ButtonHTMLAttributes, Ref } from 'react';
import { NotificationItem } from '@/components/notifications/notification-item';
import { Icon } from '@/components/ui/icon';
import { IconButton } from '@/components/ui/icon-button';
import { Skeleton } from '@/components/ui/skeleton';
import { Tab, Tabs, TabsList } from '@/components/ui/tabs';
import { jsonRequest } from '@/lib/json-request';
import { cn } from '@/lib/utils';
import {
    index as notificationsIndex,
    panel as panelRoute,
    read as readRoute,
    readAll as readAllRoute,
} from '@/routes/notifications';
import { notifications as settingsRoute } from '@/routes/settings';
import type {
    NotificationCounts,
    NotificationFilter,
    NotificationPanelData,
    NotificationRow,
} from '@/types';

/** The bell panel's filters (Notifications.dc.html: All, Approvals, Security, Money; D78 adds Unread). */
const TABS: Array<{ key: NotificationFilter; label: string }> = [
    { key: 'all', label: 'All' },
    { key: 'unread', label: 'Unread' },
    { key: 'approvals', label: 'Approvals' },
    { key: 'security', label: 'Security' },
    { key: 'money', label: 'Money' },
];

type BellProps = ButtonHTMLAttributes<HTMLButtonElement> & {
    unread: number;
    open: boolean;
    ref?: Ref<HTMLButtonElement>;
};

/** 54px bell with the unread badge; spreads the props Radix passes through `asChild`. */
function Bell54({ unread, open, ...rest }: BellProps) {
    return (
        <button
            type="button"
            className="cf-iconbtn cf-iconbtn--lg"
            aria-label={
                unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'
            }
            aria-haspopup="dialog"
            style={open ? { background: 'var(--surface-muted)' } : undefined}
            {...rest}
        >
            <Icon icon={Bell} size={22} />
            {unread > 0 && (
                <span
                    className="cf-badge"
                    style={{ top: 6, right: 6, borderColor: 'var(--surface)' }}
                    aria-hidden="true"
                >
                    {unread > 99 ? '99+' : unread}
                </span>
            )}
        </button>
    );
}

function PanelSkeleton() {
    return (
        <div aria-hidden="true">
            {[0, 1, 2].map((i) => (
                <div key={i} className="cf-notif-item notif-row">
                    <Skeleton width={36} height={36} pill />
                    <div className="cf-stack" style={{ gap: 6 }}>
                        <Skeleton width="70%" height={14} />
                        <Skeleton width="50%" height={12} />
                    </div>
                    <span />
                </div>
            ))}
        </div>
    );
}

/**
 * Top-bar bell and its panel (Notifications.dc.html, spec §19, D78): the badge is the
 * shared unread count; the rows load when the panel opens and again on a filter, after
 * an inline decision, or after Mark all read.
 */
export function NotificationsButton() {
    const shared = usePage().props.notifications;
    const [unread, setUnread] = useState(shared?.unread ?? 0);
    const [open, setOpen] = useState(false);
    const [filter, setFilter] = useState<NotificationFilter>('all');
    const [data, setData] = useState<NotificationPanelData | null>(null);
    const [failed, setFailed] = useState(false);
    const titleId = useId();
    const request = useRef(0);

    useEffect(() => setUnread(shared?.unread ?? 0), [shared?.unread]);

    const load = useCallback(async (next: NotificationFilter) => {
        const ticket = ++request.current;
        const result = await jsonRequest<NotificationPanelData>(
            panelRoute({ query: { filter: next } }).url,
        );

        if (ticket !== request.current) {
            return;
        }

        if (result.ok) {
            setData(result.data);
            setUnread(result.data.counts.unread);
            setFailed(false);
        } else {
            setFailed(true);
        }
    }, []);

    useEffect(() => {
        if (open) {
            void load(filter);
        }
    }, [open, filter, load]);

    const markRead = (item: NotificationRow) => {
        setData((d) =>
            d === null
                ? d
                : {
                      ...d,
                      items: d.items.map((i) =>
                          i.id === item.id ? { ...i, unread: false } : i,
                      ),
                  },
        );
        setUnread((n) => Math.max(0, n - (item.unread ? 1 : 0)));
        void jsonRequest<{ unread: number }>(readRoute(item.id).url, 'POST');
    };

    const markAll = async () => {
        const result = await jsonRequest<{ unread: number }>(
            readAllRoute().url,
            'POST',
        );

        if (result.ok) {
            setUnread(result.data.unread);
            void load(filter);
        }
    };

    const counts: NotificationCounts | null = data?.counts ?? null;
    const items = data?.items ?? [];
    const close = () => setOpen(false);

    return (
        <PopoverPrimitive.Root open={open} onOpenChange={setOpen}>
            <PopoverPrimitive.Trigger asChild>
                <Bell54 unread={unread} open={open} />
            </PopoverPrimitive.Trigger>
            <PopoverPrimitive.Portal>
                <PopoverPrimitive.Content
                    align="end"
                    sideOffset={8}
                    collisionPadding={16}
                    role="dialog"
                    aria-labelledby={titleId}
                    className="cf-pop cf-notif notif-panel z-40 animate-[cfFade_var(--duration-panel)_var(--ease-out)] focus-visible:shadow-pop"
                >
                    <div className="cf-notif-head">
                        <h2 id={titleId} className="t-title m-0">
                            Notifications
                        </h2>
                        <div className="cf-row" style={{ gap: 4 }}>
                            <button
                                type="button"
                                className="cf-btn cf-btn--ghost cf-btn--sm"
                                onClick={() => void markAll()}
                                disabled={unread === 0}
                            >
                                Mark all read
                            </button>
                            <IconButton
                                icon={X}
                                size="sm"
                                aria-label="Close notifications"
                                onClick={close}
                            />
                        </div>
                    </div>
                    <div style={{ padding: '0 14px 10px' }}>
                        <Tabs
                            value={filter}
                            onValueChange={(v) =>
                                setFilter(v as NotificationFilter)
                            }
                        >
                            <TabsList
                                aria-label="Filter"
                                className="notif-tabs"
                            >
                                {TABS.map((t) => (
                                    <Tab
                                        key={t.key}
                                        value={t.key}
                                        count={counts?.[t.key]}
                                    >
                                        {t.label}
                                    </Tab>
                                ))}
                            </TabsList>
                        </Tabs>
                    </div>
                    <div className="notif-list" aria-busy={data === null}>
                        {data === null && !failed && <PanelSkeleton />}
                        {failed && (
                            <div className="cf-empty notif-empty">
                                <span className="t-label-strong">
                                    Couldn’t load notifications
                                </span>
                                <button
                                    type="button"
                                    className="cf-link t-caption"
                                    onClick={() => void load(filter)}
                                >
                                    Try again
                                </button>
                            </div>
                        )}
                        {data !== null &&
                            items.map((item) => (
                                <NotificationItem
                                    key={item.id}
                                    item={item}
                                    onRead={markRead}
                                    onDecided={() => {
                                        void load(filter);
                                        router.reload({
                                            only: ['notifications'],
                                        });
                                    }}
                                    onNavigate={close}
                                />
                            ))}
                        {data !== null && items.length === 0 && (
                            <div className="cf-empty notif-empty">
                                <span className="t-label-strong">
                                    You’re all caught up
                                </span>
                                <span className="t-caption t-muted">
                                    Nothing in this filter.
                                </span>
                            </div>
                        )}
                    </div>
                    <div
                        className={cn('cf-kv')}
                        style={{
                            padding: '12px 16px',
                            borderTop: '1px solid var(--line)',
                        }}
                    >
                        <span className="cf-row" style={{ gap: 14 }}>
                            <Link
                                href={notificationsIndex().url}
                                className="cf-link t-caption"
                                onClick={close}
                            >
                                See all
                            </Link>
                            <Link
                                href={settingsRoute().url}
                                className="cf-link t-caption"
                                onClick={close}
                            >
                                Notification settings
                            </Link>
                        </span>
                        <span className="t-caption t-muted">
                            Kept for 30 days
                        </span>
                    </div>
                </PopoverPrimitive.Content>
            </PopoverPrimitive.Portal>
        </PopoverPrimitive.Root>
    );
}
