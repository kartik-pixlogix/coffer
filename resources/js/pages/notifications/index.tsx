import { Head, Link, router } from '@inertiajs/react';
import { CheckCheck } from 'lucide-react';
import { NotificationItem } from '@/components/notifications/notification-item';
import { PageHeader } from '@/components/shell/page-header';
import { Button } from '@/components/ui/button';
import { LinkTab, LinkTabs } from '@/components/ui/tabs';
import { Pagination } from '@/components/ui/pagination';
import { jsonRequest } from '@/lib/json-request';
import {
    index as notificationsIndex,
    read as readRoute,
    readAll as readAllRoute,
} from '@/routes/notifications';
import { notifications as settingsRoute } from '@/routes/settings';
import type {
    NotificationCategory,
    NotificationCounts,
    NotificationFilter,
    NotificationRow,
} from '@/types';

type Props = {
    filter: NotificationFilter;
    counts: NotificationCounts;
    categories: Record<NotificationCategory, string>;
    items: NotificationRow[];
    pagination: {
        page: number;
        pageCount: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};

/**
 * Notifications (spec §19, `/notifications`, D78): the bell's rows in full, with the same
 * filters plus Team & billing, inline approvals, Mark all read and pages of 20. Rows are
 * this company's (and workspace-wide billing ones), kept for 30 days.
 */
export default function NotificationsPage({
    filter,
    counts,
    categories,
    items,
    pagination,
}: Props) {
    const filters: Array<{ key: NotificationFilter; label: string }> = [
        { key: 'all', label: 'All' },
        { key: 'unread', label: 'Unread' },
        ...(
            Object.entries(categories) as Array<[NotificationCategory, string]>
        ).map(([key, label]) => ({ key, label })),
    ];

    const reload = () =>
        router.reload({
            only: ['items', 'counts', 'pagination', 'notifications'],
        });

    const markRead = (item: NotificationRow) => {
        void jsonRequest(readRoute(item.id).url, 'POST').then(reload);
    };

    const go = (next: { filter?: NotificationFilter; page?: number }) => {
        const query: Record<string, string | number> = {};
        const f = next.filter ?? filter;

        if (f !== 'all') {
            query.filter = f;
        }

        if ((next.page ?? 1) > 1) {
            query.page = next.page ?? 1;
        }

        router.get(
            notificationsIndex({ query }).url,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="Notifications" />
            <PageHeader
                kicker="Inbox"
                title="Notifications"
                crumbs={[{ title: 'Notifications' }]}
                actions={
                    <>
                        <Link
                            href={settingsRoute().url}
                            className="cf-btn cf-btn--secondary cf-btn--lg"
                        >
                            Notification settings
                        </Link>
                        <Button
                            variant="primary"
                            size="lg"
                            disc={CheckCheck}
                            disabled={counts.unread === 0}
                            onClick={() =>
                                router.post(
                                    readAllRoute().url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Mark all read
                        </Button>
                    </>
                }
            />

            <section
                className="cf-card notif-page flex flex-col gap-3"
                aria-labelledby="nt-h"
            >
                <div className="cf-kv flex-wrap gap-2">
                    <h2 id="nt-h" className="cf-dot-title t-title">
                        {counts.unread > 0
                            ? `${counts.unread} unread`
                            : 'You’re all caught up'}
                    </h2>
                    <span className="t-caption t-muted">
                        Approvals, vault alerts, settlements and billing · kept
                        for 30 days
                    </span>
                </div>
                <div className="cf-table-wrap">
                    <LinkTabs aria-label="Filter notifications">
                        {filters.map((f) => (
                            <LinkTab
                                key={f.key}
                                href={
                                    notificationsIndex({
                                        query:
                                            f.key === 'all'
                                                ? {}
                                                : { filter: f.key },
                                    }).url
                                }
                                current={f.key === filter}
                                count={counts[f.key]}
                                preserveScroll
                            >
                                {f.label}
                            </LinkTab>
                        ))}
                    </LinkTabs>
                </div>

                <div className="notif-page-list">
                    {items.map((item) => (
                        <NotificationItem
                            key={item.id}
                            item={item}
                            onRead={markRead}
                            onDecided={reload}
                        />
                    ))}
                    {items.length === 0 && (
                        <div className="cf-empty" style={{ padding: 32 }}>
                            <span className="t-label-strong">Nothing here</span>
                            <span className="t-caption t-muted">
                                {filter === 'unread'
                                    ? 'Everything is read.'
                                    : 'Nothing in this filter in the last 30 days.'}
                            </span>
                        </div>
                    )}
                </div>

                {pagination.pageCount > 1 && (
                    <Pagination
                        page={pagination.page}
                        pageCount={pagination.pageCount}
                        onPageChange={(page) => go({ page })}
                        summary={`${pagination.from ?? 0}–${pagination.to ?? 0} of ${pagination.total}`}
                    />
                )}
            </section>
        </>
    );
}
