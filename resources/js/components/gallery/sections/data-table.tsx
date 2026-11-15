import { Check, Ellipsis, Paperclip, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import type { GallerySection } from '@/components/gallery/types';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import type { DataTableColumn, SortState } from '@/components/ui/data-table';
import { IconButton } from '@/components/ui/icon-button';
import { StatusPill } from '@/components/ui/status-pill';
import type { StatusPreset } from '@/components/ui/status-pill';
import { formatDate, formatInr } from '@/lib/format';

type Expense = {
    id: string;
    title: string;
    /** Name used in checkbox and action labels. */
    name: string;
    note?: string;
    auto?: boolean;
    category: string;
    paise: number;
    date: string;
    status: StatusPreset;
    error?: string;
};

const EXPENSES: Expense[] = [
    {
        id: 'salary',
        title: 'Salary — Rohan Desai — 2026-10',
        name: 'Salary — Rohan Desai',
        auto: true,
        category: 'Salary',
        paise: 80000_00,
        date: '2026-10-31',
        status: 'approved',
    },
    {
        id: 'meta',
        title: 'Meta ads — October',
        name: 'Meta ads — October',
        note: '· hover',
        category: 'Marketing',
        paise: 25000_00,
        date: '2026-10-19',
        status: 'pending',
    },
    {
        id: 'figma',
        title: 'Figma organisation seats',
        name: 'Figma organisation seats',
        note: '· selected',
        category: 'Software',
        paise: 14200_00,
        date: '2026-10-05',
        status: 'approved',
    },
    {
        id: 'dinner',
        title: 'Team dinner — Diwali',
        name: 'Team dinner — Diwali',
        error: '· row error: duplicate of 24 Oct entry',
        category: 'Office & misc',
        paise: 18500_00,
        date: '2026-10-24',
        status: 'rejected',
    },
];

const COLUMNS: DataTableColumn<Expense>[] = [
    {
        key: 'title',
        header: 'Description',
        width: 'minmax(200px,2fr)',
        className: (row) =>
            row.auto ? 't-label-strong cf-row' : 't-label-strong',
        cell: (row) => (
            <>
                {row.title} {row.auto && <StatusPill status="auto" size="sm" />}
                {row.note && <span className="pv-state">{row.note}</span>}
                {row.error && <span className="t-caption">{row.error}</span>}
            </>
        ),
    },
    {
        key: 'category',
        header: 'Category',
        width: '110px',
        className: (row) => (row.error ? 't-body' : 't-body t-muted'),
        cell: (row) => row.category,
    },
    {
        key: 'amount',
        header: 'Amount',
        width: '120px',
        numeric: true,
        sortable: true,
        firstSort: 'descending',
        sortValue: (row) => row.paise,
        cell: (row) => formatInr(row.paise),
    },
    {
        key: 'date',
        header: 'Date',
        width: '110px',
        sortable: true,
        firstSort: 'descending',
        sortValue: (row) => row.date,
        className: (row) => (row.error ? 't-body' : 't-body t-muted'),
        cell: (row) => formatDate(row.date),
    },
    {
        key: 'status',
        header: 'Status',
        width: '130px',
        skeleton: 'pill',
        cell: (row) => <StatusPill status={row.status} />,
    },
];

function actions(row: Expense) {
    if (row.error) {
        return <Button size="sm">Fix</Button>;
    }

    if (row.status === 'pending') {
        return (
            <>
                <IconButton
                    icon={Check}
                    size="sm"
                    variant="pos"
                    aria-label={`Approve ${row.name}`}
                />
                <IconButton
                    icon={X}
                    size="sm"
                    variant="neg"
                    aria-label={`Reject ${row.name}`}
                />
                <IconButton
                    icon={Ellipsis}
                    size="sm"
                    strokeWidth={2.5}
                    aria-label="More actions for Meta ads"
                />
            </>
        );
    }

    if (row.id === 'figma') {
        return (
            <IconButton
                icon={Trash2}
                size="sm"
                aria-label={`Delete ${row.name}`}
            />
        );
    }

    return (
        <IconButton
            icon={Paperclip}
            size="sm"
            aria-label={`Receipt for ${row.name}`}
        />
    );
}

function DataTableDemo() {
    // The preview shows Amount sorted descending; the rows keep the preview's order.
    const [sort, setSort] = useState<SortState | null>({
        key: 'amount',
        direction: 'descending',
    });
    const [selected, setSelected] = useState<Set<string>>(
        () => new Set(['figma']),
    );

    return (
        <>
            <div className="cf-card">
                <DataTable
                    aria-label="Expenses, October 2026"
                    rows={EXPENSES}
                    columns={COLUMNS}
                    getRowId={(row) => row.id}
                    sort={sort}
                    onSortChange={setSort}
                    manualSort={
                        sort?.key === 'amount' &&
                        sort.direction === 'descending'
                    }
                    selectable
                    selected={selected}
                    onSelectionChange={setSelected}
                    canSelectRow={(row) => !row.error}
                    rowLabel={(row) => row.name}
                    rowError={(row) => Boolean(row.error)}
                    rowClassName={(row) =>
                        row.id === 'meta' ? 'is-hover' : undefined
                    }
                    rowActions={actions}
                    minWidth={880}
                />
                <div className="mt-1.5">
                    <DataTable
                        aria-label="Expenses, loading"
                        rows={[]}
                        columns={COLUMNS}
                        getRowId={(row) => row.id}
                        selectable
                        rowActions={actions}
                        loading
                        loadingRows={1}
                        showHeader={false}
                        minWidth={880}
                    />
                </div>
            </div>
            <div className="cf-card">
                <DataTable
                    aria-label="Expenses, filtered"
                    rows={[]}
                    columns={COLUMNS}
                    getRowId={(row) => row.id}
                    empty={{
                        title: 'No expenses match these filters',
                        action: <Button size="sm">Clear filters</Button>,
                    }}
                />
            </div>
        </>
    );
}

export default {
    name: 'DataTable',
    group: 'Data and charts',
    order: 5,
    layout: 'stack',
    subtitle:
        'Sortable header, rows (hover, selected, error), row actions, empty and loading rows',
    render: () => <DataTableDemo />,
} satisfies GallerySection;
