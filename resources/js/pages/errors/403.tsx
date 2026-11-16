import { Head, Link, router } from '@inertiajs/react';
import { Check, Lock, UserRound } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/shell/page-header';
import { AlertBanner } from '@/components/ui/alert-banner';
import { Button, ButtonLink } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { TextInput } from '@/components/ui/text-input';
import { pageIcon } from '@/lib/navigation';
import {
    destroy as cancelRequest,
    store as storeRequest,
} from '@/routes/access-requests';
import type { PageKey } from '@/types';

type CanOpen = {
    page: PageKey | 'profile';
    label: string;
    url: string | null;
    note: string;
};

type Props = {
    /** The page that was refused, or null for any other 403. */
    page: PageKey | null;
    pageLabel: string | null;
    explanation: string | null;
    canOpen: CanOpen[];
    /** Admins who get the request (not the viewer). */
    admins: string[];
    request: { id: number; reason: string | null } | null;
    landing: { url: string; label: string } | null;
};

/** "Aarav Mehta and Kabir Shah", "Aarav Mehta, Kabir Shah and Meera Iyer". */
function names(list: string[]): string {
    if (list.length <= 1) {
        return list[0] ?? 'your Admins';
    }

    return `${list.slice(0, -1).join(', ')} and ${list[list.length - 1]}`;
}

/**
 * Permission denied (design/screens/StateDenied.dc.html): a direct visit to a page
 * the role can't open. Explains why, lets the person ask an Admin, and lists what
 * they can open instead.
 */
export default function Denied({
    page,
    pageLabel,
    explanation,
    canOpen,
    admins,
    request,
    landing,
}: Props) {
    const [reason, setReason] = useState('');
    const [sending, setSending] = useState(false);

    const ask = () => {
        if (page === null || sending) {
            return;
        }

        router.post(
            storeRequest().url,
            { page, reason },
            {
                preserveScroll: true,
                onStart: () => setSending(true),
                onFinish: () => setSending(false),
            },
        );
    };

    const undo = () => {
        if (request !== null) {
            router.delete(cancelRequest(request.id).url, {
                preserveScroll: true,
            });
        }
    };

    const title = pageLabel ?? 'This page';
    const landingLink =
        landing !== null ? (
            <ButtonLink href={landing.url} variant="secondary" size="lg">
                {landing.label}
            </ButtonLink>
        ) : null;

    return (
        <>
            <Head title={`${title}: no access`} />

            <PageHeader kicker={title} title="No access" crumbs={[{ title }]} />

            <div className="flex flex-wrap items-stretch gap-4">
                <section
                    className="cf-card flex flex-col items-start gap-3.5 p-8"
                    style={{ flex: '999 1 560px' }}
                    aria-labelledby="dn-h"
                >
                    <span className="denied-ic">
                        <Icon icon={Lock} size={24} />
                    </span>
                    <h2 id="dn-h" className="t-heading denied-title">
                        {pageLabel !== null
                            ? `You can’t open ${pageLabel}`
                            : 'You can’t do that here'}
                    </h2>
                    <p className="t-body t-muted denied-body">
                        {explanation ??
                            'Your role in this company doesn’t include this. An Admin can change it in Settings → Roles and permissions.'}
                    </p>

                    {page !== null && request === null && (
                        <>
                            <TextInput
                                id="dn-r"
                                fieldClassName="w-full max-w-[560px]"
                                label={
                                    <>
                                        Why do you need it?{' '}
                                        <span
                                            className="t-muted"
                                            style={{ fontWeight: 400 }}
                                        >
                                            Optional
                                        </span>
                                    </>
                                }
                                value={reason}
                                maxLength={500}
                                onChange={(e) => setReason(e.target.value)}
                                placeholder="For example, checking hours billed to Asha Retail"
                            />
                            <div className="cf-row flex-wrap gap-2.5">
                                <Button
                                    variant="primary"
                                    size="lg"
                                    loading={sending}
                                    onClick={ask}
                                >
                                    {sending
                                        ? 'Sending'
                                        : 'Ask an Admin for access'}
                                </Button>
                                {landingLink}
                            </div>
                        </>
                    )}

                    {page !== null && request !== null && (
                        <>
                            <AlertBanner
                                variant="pos"
                                role="status"
                                icon={Check}
                                iconStrokeWidth={2}
                                className="w-full"
                                title={`Request sent to ${names(admins)}`}
                                action={
                                    <Button variant="ghost" onClick={undo}>
                                        Cancel request
                                    </Button>
                                }
                            >
                                You’ll get a notification when one of them
                                answers. Nothing changes until then.
                            </AlertBanner>
                            {landingLink}
                        </>
                    )}

                    {page === null && landingLink}
                </section>

                <aside
                    className="cf-card flex flex-col gap-2"
                    style={{ flex: '1 1 340px' }}
                    aria-labelledby="cn-h"
                >
                    <h2 id="cn-h" className="cf-dot-title t-title mb-1">
                        What you can open
                    </h2>
                    {canOpen.map((item) => {
                        const content = (
                            <>
                                <Icon
                                    icon={
                                        item.page === 'profile'
                                            ? UserRound
                                            : pageIcon(item.page)
                                    }
                                    size={16}
                                />
                                <span className="t-body flex-1 font-medium">
                                    {item.label}
                                </span>
                                <span className="t-caption t-muted">
                                    {item.url === null
                                        ? 'Coming soon'
                                        : item.note}
                                </span>
                            </>
                        );

                        return item.url === null ? (
                            <span
                                key={item.page}
                                className="denied-can"
                                aria-disabled="true"
                            >
                                {content}
                            </span>
                        ) : (
                            <Link
                                key={item.page}
                                href={item.url}
                                className="denied-can"
                            >
                                {content}
                            </Link>
                        );
                    })}
                    <p className="t-caption t-muted mt-1.5 mb-0">
                        Admins change these in Settings → Roles and permissions.
                    </p>
                </aside>
            </div>
        </>
    );
}
