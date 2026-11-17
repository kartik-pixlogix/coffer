import {
    Check,
    ChevronsDown,
    ChevronsUp,
    ChevronsUpDown,
    Minus,
    Search,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { nextSort, sortRows } from '@/components/ui/table-sort';
import type {
    SortDirection,
    SortState,
    SortValue,
} from '@/components/ui/table-sort';
import { TableWrap } from '@/components/ui/table-wrap';
import { cn } from '@/lib/utils';

export type { SortDirection, SortState, SortValue };

export type DataTableColumn<Row> = {
    key: string;
    header: ReactNode;
    /** Grid track, e.g. "minmax(200px,2fr)" or "120px". Default `minmax(0,1fr)`. */
    width?: string;
    /** `end` right-aligns the header and the cell (money, actions). */
    align?: 'start' | 'end';
    /** Money and figures: right-aligned IBM Plex Mono with tabular digits (`cf-num`). */
    numeric?: boolean;
    sortable?: boolean;
    /** Direction of the first click on this column (amounts and dates: descending). */
    firstSort?: SortDirection;
    /** The value to sort by when the table sorts locally. */
    sortValue?: (row: Row) => SortValue;
    cell: (row: Row) => ReactNode;
    /** Classes on the cell, e.g. "t-label-strong" or "t-body t-muted". */
    className?: string | ((row: Row) => string | undefined);
    /** Skeleton shape while loading; default a line (a pill for status columns). */
    skeleton?: 'line' | 'pill' | 'none';
};

export type DataTableEmpty = {
    title: ReactNode;
    icon?: LucideIcon;
    /** The way out, e.g. a "Clear filters" button. */
    action?: ReactNode;
};

export type DataTableProps<Row> = {
    rows: readonly Row[];
    columns: readonly DataTableColumn<Row>[];
    getRowId: (row: Row) => string;
    'aria-label': string;
    /** Controlled sort. `null` = unsorted. */
    sort?: SortState | null;
    onSortChange?: (sort: SortState) => void;
    /** The rows arrive sorted (server-side); don't sort them here. */
    manualSort?: boolean;
    /** Adds the checkbox column. */
    selectable?: boolean;
    selected?: ReadonlySet<string>;
    onSelectionChange?: (selected: Set<string>) => void;
    /** Rows that can't be selected (e.g. import errors) get an empty checkbox cell. */
    canSelectRow?: (row: Row) => boolean;
    /** Names a row for its checkbox: "Select {rowLabel}". */
    rowLabel?: (row: Row) => string;
    /** Buttons at the far right (`cf-rowacts`). */
    rowActions?: (row: Row) => ReactNode;
    actionsHeader?: ReactNode;
    actionsWidth?: string;
    /** `.is-error`: an import or validation problem on this row. */
    rowError?: (row: Row) => boolean;
    rowClassName?: (row: Row) => string | undefined;
    /** A `.is-total` row after the data, cells by column key. */
    totals?: Partial<Record<string, ReactNode>>;
    loading?: boolean;
    loadingRows?: number;
    empty?: DataTableEmpty;
    /** Keep the header visible while the wrap scrolls (give it a `maxHeight`). */
    stickyHeader?: boolean;
    /** Leave out the header row (a continuation of a table above). Default shown. */
    showHeader?: boolean;
    maxHeight?: number | string;
    /** Wrap in `cf-table-wrap` so wide tables scroll sideways. Default on. */
    scroll?: boolean;
    /** Minimum width of the table inside the scroll wrap, e.g. 880. */
    minWidth?: number | string;
    className?: string;
};

const SORT_ICON: Record<SortDirection | 'none', LucideIcon> = {
    ascending: ChevronsUp,
    descending: ChevronsDown,
    none: ChevronsUpDown,
};

const SKELETON_WIDTHS = ['70%', '60%'];

function RowCheckbox({
    checked,
    indeterminate = false,
    label,
    onChange,
}: {
    checked: boolean;
    indeterminate?: boolean;
    label: string;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label className="cf-check">
            <input
                ref={(input) => {
                    if (input) {
                        input.indeterminate = indeterminate;
                    }
                }}
                className="cf-sr"
                type="checkbox"
                checked={checked}
                aria-label={label}
                onChange={(event) => onChange(event.target.checked)}
            />
            <span
                className="cf-check-box"
                style={
                    indeterminate
                        ? {
                              background: 'var(--ink)',
                              borderColor: 'var(--ink)',
                          }
                        : undefined
                }
            >
                <Icon
                    icon={indeterminate ? Minus : Check}
                    size={14}
                    strokeWidth={2.5}
                    className={indeterminate ? 'opacity-100' : undefined}
                />
            </span>
        </label>
    );
}

/**
 * Data table (`cf-table`): white rounded rows on a surface-card panel, money right-
 * aligned in mono, status as pills and actions at the far right. Sorting and
 * selection are controlled; rows sort locally by `sortValue` unless `manualSort`.
 */
export function DataTable<Row>({
    rows,
    columns,
    getRowId,
    'aria-label': ariaLabel,
    sort = null,
    onSortChange,
    manualSort = false,
    selectable = false,
    selected,
    onSelectionChange,
    canSelectRow,
    rowLabel,
    rowActions,
    actionsHeader = 'Actions',
    actionsWidth = '116px',
    rowError,
    rowClassName,
    totals,
    loading = false,
    loadingRows = 3,
    empty,
    stickyHeader = false,
    showHeader = true,
    maxHeight,
    scroll = true,
    minWidth,
    className,
}: DataTableProps<Row>) {
    const selection = selected ?? new Set<string>();

    const tracks = [
        selectable ? '28px' : null,
        ...columns.map((c) => c.width ?? 'minmax(0,1fr)'),
        rowActions ? actionsWidth : null,
    ].filter((t): t is string => t !== null);
    const gridStyle: CSSProperties = { gridTemplateColumns: tracks.join(' ') };

    const sortColumn = sort
        ? columns.find((c) => c.key === sort.key)
        : undefined;
    const shownRows =
        !manualSort && sort && sortColumn?.sortValue
            ? sortRows(rows, sort.direction, sortColumn.sortValue)
            : rows;

    const selectableRows = shownRows.filter(
        (row) => !canSelectRow || canSelectRow(row),
    );
    const selectedCount = selectableRows.filter((row) =>
        selection.has(getRowId(row)),
    ).length;
    const allSelected =
        selectableRows.length > 0 && selectedCount === selectableRows.length;
    const someSelected = selectedCount > 0 && !allSelected;

    const setRow = (id: string, on: boolean) => {
        const next = new Set(selection);

        if (on) {
            next.add(id);
        } else {
            next.delete(id);
        }

        onSelectionChange?.(next);
    };

    const setAll = (on: boolean) => {
        const next = new Set(selection);

        for (const row of selectableRows) {
            if (on) {
                next.add(getRowId(row));
            } else {
                next.delete(getRowId(row));
            }
        }

        onSelectionChange?.(next);
    };

    const alignStyle = (column: {
        align?: 'start' | 'end';
        numeric?: boolean;
    }) =>
        column.align === 'end' || column.numeric
            ? ({ textAlign: 'right' } as const)
            : undefined;

    const header = (
        <div
            className={cn(
                'cf-tr cf-thead',
                stickyHeader && 'sticky top-0 z-10 bg-surface-card',
            )}
            role="row"
            style={gridStyle}
        >
            {selectable && (
                <span role="columnheader">
                    <RowCheckbox
                        checked={allSelected}
                        indeterminate={someSelected}
                        label="Select all rows"
                        onChange={() => setAll(!allSelected)}
                    />
                </span>
            )}
            {columns.map((column) => {
                if (!column.sortable) {
                    return (
                        <span
                            key={column.key}
                            role="columnheader"
                            style={alignStyle(column)}
                        >
                            {column.header}
                        </span>
                    );
                }

                const direction =
                    sort?.key === column.key ? sort.direction : 'none';

                return (
                    <span
                        key={column.key}
                        role="columnheader"
                        aria-sort={direction}
                        style={alignStyle(column)}
                    >
                        <button
                            type="button"
                            className="cf-sort"
                            onClick={() =>
                                onSortChange?.(
                                    nextSort(
                                        sort,
                                        column.key,
                                        column.firstSort,
                                    ),
                                )
                            }
                        >
                            {column.header}
                            <Icon
                                icon={SORT_ICON[direction]}
                                size={12}
                                strokeWidth={2}
                            />
                        </button>
                    </span>
                );
            })}
            {rowActions && (
                <span role="columnheader" style={{ textAlign: 'right' }}>
                    {actionsHeader}
                </span>
            )}
        </div>
    );

    let body: ReactNode;

    if (loading) {
        body = Array.from({ length: loadingRows }, (_, r) => (
            <div
                key={`loading-${r}`}
                className="cf-tr"
                role="row"
                aria-busy="true"
                style={gridStyle}
            >
                {selectable && <span role="cell" />}
                {columns.map((column, i) => {
                    const shape = column.skeleton ?? 'line';

                    if (shape === 'none') {
                        return <span key={column.key} role="cell" />;
                    }

                    if (shape === 'pill') {
                        return (
                            <span
                                key={column.key}
                                role="cell"
                                className="cf-skel rounded-full"
                                style={{ width: 80, height: 24 }}
                            />
                        );
                    }

                    return (
                        <span
                            key={column.key}
                            role="cell"
                            className="cf-skel cf-skel--line"
                            style={{
                                width: SKELETON_WIDTHS[i % 2],
                                justifySelf:
                                    column.numeric || column.align === 'end'
                                        ? 'end'
                                        : undefined,
                            }}
                        />
                    );
                })}
                {rowActions && <span role="cell" />}
            </div>
        ));
    } else {
        body = shownRows.map((row) => {
            const id = getRowId(row);
            const canSelect = !canSelectRow || canSelectRow(row);
            const isSelected = selectable && canSelect && selection.has(id);
            const label = rowLabel ? rowLabel(row) : id;

            return (
                <div
                    key={id}
                    className={cn(
                        'cf-tr',
                        isSelected && 'is-selected',
                        rowError?.(row) && 'is-error',
                        rowClassName?.(row),
                    )}
                    role="row"
                    aria-selected={isSelected || undefined}
                    style={gridStyle}
                >
                    {selectable && (
                        <span role="cell">
                            {canSelect && (
                                <RowCheckbox
                                    checked={isSelected}
                                    label={`Select ${label}`}
                                    onChange={(on) => setRow(id, on)}
                                />
                            )}
                        </span>
                    )}
                    {columns.map((column) => (
                        <span
                            key={column.key}
                            role="cell"
                            className={cn(
                                column.numeric && 'cf-num',
                                !column.numeric &&
                                    column.align === 'end' &&
                                    'text-right',
                                typeof column.className === 'function'
                                    ? column.className(row)
                                    : column.className,
                            )}
                        >
                            {column.cell(row)}
                        </span>
                    ))}
                    {rowActions && (
                        <span role="cell" className="cf-rowacts">
                            {rowActions(row)}
                        </span>
                    )}
                </div>
            );
        });
    }

    const isEmpty = !loading && shownRows.length === 0;

    const table = isEmpty ? (
        <div
            className={cn('cf-table', className)}
            role="table"
            aria-label={ariaLabel}
        >
            <div role="row">
                <div role="cell" className="cf-empty-row">
                    <span className="cf-empty-ic cf-empty-ic--muted size-11">
                        <Icon icon={empty?.icon ?? Search} size={20} />
                    </span>
                    <span className="t-label-strong">
                        {empty?.title ?? 'Nothing here yet'}
                    </span>
                    {empty?.action}
                </div>
            </div>
        </div>
    ) : (
        <div
            className={cn('cf-table', className)}
            role="table"
            aria-label={ariaLabel}
            aria-busy={loading || undefined}
            style={minWidth !== undefined ? { minWidth } : undefined}
        >
            {showHeader && header}
            {body}
            {totals && !loading && (
                <div className="cf-tr is-total" role="row" style={gridStyle}>
                    {selectable && <span role="cell" />}
                    {columns.map((column) => (
                        <span
                            key={column.key}
                            role="cell"
                            className={cn(
                                column.numeric && 'cf-num',
                                !column.numeric &&
                                    column.align === 'end' &&
                                    'text-right',
                            )}
                        >
                            {totals[column.key]}
                        </span>
                    ))}
                    {rowActions && <span role="cell" />}
                </div>
            )}
        </div>
    );

    if (!scroll) {
        return table;
    }

    return (
        <TableWrap
            label={ariaLabel}
            style={maxHeight !== undefined ? { maxHeight } : undefined}
        >
            {table}
        </TableWrap>
    );
}
