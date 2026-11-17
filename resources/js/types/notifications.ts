/** Notification filters (D78): All, Unread, then the categories. */
export type NotificationCategory =
    | 'approvals'
    | 'security'
    | 'money'
    | 'workspace';

export type NotificationFilter = 'all' | 'unread' | NotificationCategory;

export type NotificationTone = 'plain' | 'warn' | 'neg' | 'lime';

/** One row of the bell's panel or the Notifications page (App\Services\Notifications\Inbox). */
export type NotificationRow = {
    id: string;
    title: string;
    body: string;
    url: string;
    /** lucide icon name, e.g. "receipt". */
    icon: string;
    tone: NotificationTone;
    /** The row's link text ("Open the expense", "Record the transfer"). */
    link: string;
    kind: string;
    category: NotificationCategory;
    unread: boolean;
    /** "2 hours ago", "Yesterday, 13:20", "12 Nov". */
    when: string;
    createdAt: string | null;
    /** An expense the row is about, for inline Approve / Reject. */
    expenseId: number | null;
    canDecide: boolean;
    done: { label: string; tone: 'pos' | 'neg' } | null;
};

export type NotificationCounts = Record<NotificationFilter, number>;

export type NotificationPanelData = {
    items: NotificationRow[];
    counts: NotificationCounts;
};

export type NotificationPreferenceEvent = {
    key: string;
    label: string;
    hint: string;
    database: boolean;
    mail: boolean;
    /** Channels that stay on (a failed payment is always emailed). */
    required: Array<'database' | 'mail'>;
};

export type NotificationPreferenceGroup = {
    key: NotificationCategory;
    label: string;
    events: NotificationPreferenceEvent[];
};
