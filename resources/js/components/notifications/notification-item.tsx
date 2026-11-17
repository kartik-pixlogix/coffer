import { Link, router } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Bell,
    CalendarCheck,
    Check,
    Clock,
    CreditCard,
    Hourglass,
    KeyRound,
    Lock,
    Receipt,
    ShieldAlert,
    Upload,
    UserCheck,
    UserPlus,
    Users,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { StatusPill } from '@/components/ui/status-pill';
import { useToast } from '@/components/ui/toast';
import { cn } from '@/lib/utils';
import { approve, reject } from '@/routes/expenses';
import type { NotificationRow, NotificationTone } from '@/types';

/** The payload's lucide icon names (App\Notifications\*::icon()). */
const ICONS: Record<string, LucideIcon> = {
    receipt: Receipt,
    'key-round': KeyRound,
    clock: Clock,
    'user-plus': UserPlus,
    'user-check': UserCheck,
    users: Users,
    upload: Upload,
    lock: Lock,
    'calendar-check': CalendarCheck,
    'arrow-left-right': ArrowLeftRight,
    hourglass: Hourglass,
    'credit-card': CreditCard,
    'shield-alert': ShieldAlert,
};

const TONE: Record<NotificationTone, string | undefined> = {
    plain: undefined,
    warn: 'cf-notif-ic--warn',
    neg: 'cf-notif-ic--neg',
    lime: 'cf-notif-ic--lime',
};

type Props = {
    item: NotificationRow;
    /** Mark the row read (opening its link, or deciding it inline). */
    onRead: (item: NotificationRow) => void;
    /** After an inline approve / reject: reload the list. */
    onDecided: () => void;
    /** Following the link (e.g. close the panel). */
    onNavigate?: () => void;
};

/**
 * One notification (Notifications.dc.html `cf-notif-item`): typed icon disc, the bold line,
 * the detail, inline Approve / Reject for an expense that's still waiting (through the
 * same permission-checked routes as Expenses), the link, the outcome pill and the age.
 */
export function NotificationItem({
    item,
    onRead,
    onDecided,
    onNavigate,
}: Props) {
    const toast = useToast();
    const [busy, setBusy] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');
    const [reasonError, setReasonError] = useState<string | null>(null);

    const decide = (kind: 'approve' | 'reject') => {
        if (item.expenseId === null) {
            return;
        }

        const route =
            kind === 'approve'
                ? approve(item.expenseId)
                : reject(item.expenseId);

        setBusy(true);
        router.post(route.url, kind === 'reject' ? { reason } : {}, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setRejecting(false);
                onRead(item);
                onDecided();
            },
            onError: (errors) => {
                if (errors.reason) {
                    setReasonError(errors.reason);

                    return;
                }

                toast.error(errors.expense ?? 'That didn’t work. Try again.');
                onDecided();
            },
            onFinish: () => setBusy(false),
        });
    };

    const submitReject = (event: FormEvent) => {
        event.preventDefault();

        if (reason.trim() === '') {
            setReasonError('Say why it’s rejected.');

            return;
        }

        decide('reject');
    };

    return (
        <article
            className={cn(
                'cf-notif-item notif-row',
                item.unread && 'is-unread',
            )}
            aria-label={(item.unread ? 'Unread: ' : '') + item.title}
        >
            <span className={cn('cf-notif-ic', TONE[item.tone])}>
                <Icon icon={ICONS[item.icon] ?? Bell} size={16} />
            </span>
            <div className="cf-stack min-w-0" style={{ gap: 4 }}>
                <span className="t-body">
                    <span className="t-label-strong">{item.title}</span>
                </span>
                <span className="t-caption t-muted">{item.body}</span>
                {item.canDecide && !rejecting && (
                    <div className="cf-row mt-1" style={{ gap: 6 }}>
                        <Button
                            variant="primary"
                            size="sm"
                            loading={busy}
                            onClick={() => decide('approve')}
                        >
                            Approve
                        </Button>
                        <Button
                            variant="secondary"
                            size="sm"
                            disabled={busy}
                            onClick={() => setRejecting(true)}
                        >
                            Reject
                        </Button>
                    </div>
                )}
                {item.canDecide && rejecting && (
                    <form
                        className="notif-reject"
                        onSubmit={submitReject}
                        noValidate
                    >
                        <label
                            className="cf-label"
                            htmlFor={`reason-${item.id}`}
                        >
                            Why is it rejected?
                        </label>
                        <input
                            id={`reason-${item.id}`}
                            className="cf-input cf-input--sm"
                            value={reason}
                            maxLength={500}
                            autoFocus
                            aria-invalid={reasonError ? true : undefined}
                            aria-describedby={
                                reasonError
                                    ? `reason-err-${item.id}`
                                    : undefined
                            }
                            onChange={(e) => {
                                setReason(e.target.value);
                                setReasonError(null);
                            }}
                            placeholder="The submitter sees this"
                        />
                        {reasonError && (
                            <span
                                id={`reason-err-${item.id}`}
                                className="cf-errtext"
                            >
                                {reasonError}
                            </span>
                        )}
                        <div className="cf-row" style={{ gap: 6 }}>
                            <Button
                                type="submit"
                                variant="primary"
                                size="sm"
                                loading={busy}
                            >
                                Reject
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                disabled={busy}
                                onClick={() => {
                                    setRejecting(false);
                                    setReasonError(null);
                                }}
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                )}
                {item.url !== '' && (
                    <Link
                        href={item.url}
                        className="cf-link t-caption self-start"
                        style={{ marginTop: 2 }}
                        onClick={() => {
                            if (item.unread) {
                                onRead(item);
                            }

                            onNavigate?.();
                        }}
                    >
                        {item.link}
                    </Link>
                )}
                {item.done && (
                    <StatusPill
                        tone={item.done.tone}
                        icon={item.done.tone === 'pos' ? Check : X}
                        size="sm"
                        className="self-start"
                        style={{ marginTop: 4 }}
                    >
                        {item.done.label}
                    </StatusPill>
                )}
                <span className="t-caption t-muted">{item.when}</span>
            </div>
            {item.unread ? (
                <button
                    type="button"
                    className="notif-dot"
                    aria-label={`Mark “${item.title}” read`}
                    title="Mark read"
                    onClick={() => onRead(item)}
                >
                    <span className="cf-unread" aria-hidden="true" />
                </button>
            ) : (
                <span aria-hidden="true" />
            )}
        </article>
    );
}
