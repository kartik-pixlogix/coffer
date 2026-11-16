import { router } from '@inertiajs/react';
import { ArrowRight, Banknote } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn } from '@/components/ui/data-table';
import { FieldError } from '@/components/ui/field';
import { Modal } from '@/components/ui/modal';
import { MonthPicker } from '@/components/ui/month-picker';
import { StatusPill } from '@/components/ui/status-pill';
import { formatInr, toDate, toIsoDate } from '@/lib/format';
import { formatCenti } from '@/lib/hours';
import { monthLong } from '@/lib/team';
import {
    preview as salaryPreview,
    store as runSalaries,
} from '@/routes/team/salaries';
import type { CostType } from '@/types/team';

type Row = {
    id: number;
    name: string;
    costType: CostType;
    billableCenti: number;
    amountPaise: number;
    /** What's posted for the month now; null when nothing is. */
    postedPaise: number | null;
};

const lastMonth = (today: string): string => {
    const d = toDate(today);

    return toIsoDate(new Date(d.getFullYear(), d.getMonth() - 1, 1)).slice(
        0,
        7,
    );
};

/**
 * "Run salaries" (spec §8.3 "Run now"; the design has no board for it, D38): pick a
 * finished month, see what each member's salary will be against what's posted, run it.
 * Changed amounts are reversed and re-posted; unchanged ones stay.
 */
export function RunSalariesModal({
    open,
    onOpenChange,
    today,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    today: string;
}) {
    const [month, setMonth] = useState(() => lastMonth(today));
    const [rows, setRows] = useState<Row[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [running, setRunning] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        setRows(null);
        setFailed(false);

        fetch(salaryPreview({ query: { month } }).url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((r) =>
                r.ok ? r.json() : Promise.reject(new Error(String(r.status))),
            )
            .then((data: { rows: Row[] }) => setRows(data.rows))
            .catch((error: unknown) => {
                if (
                    !(
                        error instanceof DOMException &&
                        error.name === 'AbortError'
                    )
                ) {
                    setFailed(true);
                }
            });

        return () => controller.abort();
    }, [open, month]);

    const total = (rows ?? []).reduce((s, r) => s + r.amountPaise, 0);
    const changes = (rows ?? []).filter(
        (r) =>
            r.postedPaise !== r.amountPaise &&
            !(r.postedPaise === null && r.amountPaise === 0),
    ).length;

    const columns: DataTableColumn<Row>[] = [
        {
            key: 'name',
            header: 'Member',
            cell: (r) => <span className="t-label-strong">{r.name}</span>,
        },
        {
            key: 'hours',
            header: 'Billable',
            width: '100px',
            numeric: true,
            cell: (r) =>
                r.costType === 'hourly'
                    ? `${formatCenti(r.billableCenti)} h`
                    : 'Fixed',
        },
        {
            key: 'amount',
            header: 'Salary',
            width: '120px',
            numeric: true,
            cell: (r) => formatInr(r.amountPaise),
        },
        {
            key: 'state',
            header: 'Posted',
            width: '160px',
            skeleton: 'pill',
            cell: (r) => {
                if (r.postedPaise === r.amountPaise) {
                    return (
                        <StatusPill tone="neutral" size="sm">
                            Unchanged
                        </StatusPill>
                    );
                }

                if (r.postedPaise === null) {
                    return r.amountPaise === 0 ? (
                        <span className="t-caption t-muted">
                            Nothing to post
                        </span>
                    ) : (
                        <StatusPill tone="info" size="sm">
                            New
                        </StatusPill>
                    );
                }

                return (
                    <StatusPill tone="warn" size="sm">
                        Was {formatInr(r.postedPaise)}
                    </StatusPill>
                );
            },
        },
    ];

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title="Run salaries"
            subtitle="Posted as Auto salary expenses on the month’s last day"
            icon={Banknote}
            size="lg"
            footer={
                <>
                    <Button
                        variant="secondary"
                        size="lg"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        size="lg"
                        disc={ArrowRight}
                        loading={running}
                        disabled={rows === null || changes === 0}
                        onClick={() =>
                            router.post(
                                runSalaries().url,
                                { month },
                                {
                                    preserveScroll: true,
                                    onStart: () => setRunning(true),
                                    onFinish: () => setRunning(false),
                                    onSuccess: () => onOpenChange(false),
                                },
                            )
                        }
                    >
                        {changes === 0
                            ? 'Nothing to change'
                            : `Run ${monthLong(`${month}-01`)}`}
                    </Button>
                </>
            }
        >
            <div className="cf-kv flex-wrap gap-3">
                <MonthPicker
                    aria-label="Salary month"
                    value={month}
                    max={lastMonth(today)}
                    onValueChange={setMonth}
                />
                <span className="t-caption t-muted">
                    Hourly: billable hours × rate. Fixed: the monthly cost.
                </span>
            </div>
            <DataTable
                aria-label={`Salaries for ${monthLong(`${month}-01`)}`}
                rows={rows ?? []}
                loading={rows === null && !failed}
                columns={columns}
                getRowId={(r) => String(r.id)}
                totals={{ name: 'Total', amount: formatInr(total) }}
                empty={{ title: 'No one to pay for this month' }}
                minWidth={520}
            />
            {failed && (
                <FieldError>
                    Couldn’t load the salaries. Close and try again.
                </FieldError>
            )}
        </Modal>
    );
}
