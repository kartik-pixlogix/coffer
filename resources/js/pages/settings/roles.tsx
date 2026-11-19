import { Head, router } from '@inertiajs/react';
import { Check, Lock, RotateCcw } from 'lucide-react';
import { Fragment, useState } from 'react';
import { SettingsShell } from '@/components/settings/settings-shell';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@/components/ui/icon';
import {
    reset as resetRoles,
    update as saveRoles,
} from '@/routes/settings/roles';
import { TableWrap } from '@/components/ui/table-wrap';

type Editable = 'partner' | 'member' | 'viewer';

type Row = {
    key: string;
    label: string;
    note: string | null;
    locked: boolean;
    group?: string;
};

type Props = {
    pages: Row[];
    actions: (Row & { group: string })[];
    matrix: Record<Editable, string[]>;
    canEdit: boolean;
};

const ROLES: { key: 'admin' | Editable; label: string }[] = [
    { key: 'admin', label: 'Admin' },
    { key: 'partner', label: 'Partner' },
    { key: 'member', label: 'Member' },
    { key: 'viewer', label: 'Viewer' },
];

type Matrix = Record<Editable, Set<string>>;

const toSets = (matrix: Props['matrix']): Matrix => ({
    partner: new Set(matrix.partner),
    member: new Set(matrix.member),
    viewer: new Set(matrix.viewer),
});

function MatrixRow({
    row,
    current,
    saved,
    canEdit,
    onToggle,
}: {
    row: Row;
    current: Matrix;
    saved: Matrix;
    canEdit: boolean;
    onToggle: (role: Editable, key: string) => void;
}) {
    return (
        <div className="cf-tr pm" role="row">
            <span role="cell" className="cf-stack">
                <span className="t-body">{row.label}</span>
                {row.note && (
                    <span className="t-caption t-muted">{row.note}</span>
                )}
            </span>
            {ROLES.map((role) => {
                const admin = role.key === 'admin';
                const on = admin || current[role.key as Editable].has(row.key);
                const changed =
                    !admin && on !== saved[role.key as Editable].has(row.key);
                const locked = admin || row.locked || !canEdit;

                return (
                    <span
                        key={role.key}
                        role="cell"
                        className={`pmc ${changed ? 'is-changed' : ''}`}
                    >
                        <label className="cf-check">
                            <input
                                className="cf-sr"
                                type="checkbox"
                                checked={on}
                                disabled={locked}
                                onChange={() =>
                                    onToggle(role.key as Editable, row.key)
                                }
                                aria-label={
                                    admin
                                        ? `${row.label} for Admin, always on`
                                        : `${row.label} for ${role.label}`
                                }
                            />
                            <span className="cf-check-box">
                                <Icon
                                    icon={Check}
                                    size={14}
                                    strokeWidth={2.5}
                                />
                            </span>
                        </label>
                    </span>
                );
            })}
        </div>
    );
}

/**
 * Settings › Roles & permissions (SettingsMembers.dc.html "Roles" tab, spec §2, D24):
 * pages and actions per role. The Admin column is always on; changed cells are
 * highlighted until saved; "Reset to defaults" restores the spec matrix.
 */
export default function Roles({ pages, actions, matrix, canEdit }: Props) {
    const saved = toSets(matrix);
    const [current, setCurrent] = useState<Matrix>(() => toSets(matrix));
    const [lastSaved, setLastSaved] = useState(matrix);
    const [resetOpen, setResetOpen] = useState(false);
    const [saving, setSaving] = useState(false);

    // New server data (after save or reset): start from it again.
    if (lastSaved !== matrix) {
        setLastSaved(matrix);
        setCurrent(toSets(matrix));
    }

    const changes = (['partner', 'member', 'viewer'] as Editable[]).reduce(
        (count, role) => {
            const a = current[role];
            const b = saved[role];

            return (
                count +
                [...a].filter((k) => !b.has(k)).length +
                [...b].filter((k) => !a.has(k)).length
            );
        },
        0,
    );

    const toggle = (role: Editable, key: string) =>
        setCurrent((m) => {
            const next = new Set(m[role]);

            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }

            return { ...m, [role]: next };
        });

    const save = () =>
        router.put(
            saveRoles().url,
            {
                matrix: {
                    partner: [...current.partner],
                    member: [...current.member],
                    viewer: [...current.viewer],
                },
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
            },
        );

    const groups = [...new Set(actions.map((a) => a.group))];
    const header = (first: string) => (
        <div className="cf-tr cf-thead pm" role="row">
            <span role="columnheader">{first}</span>
            {ROLES.map((r) => (
                <span key={r.key} role="columnheader" className="text-center">
                    {r.label}
                </span>
            ))}
        </div>
    );

    return (
        <SettingsShell
            tab="roles"
            title="Roles"
            crumb="Roles & permissions"
            actions={
                canEdit && (
                    <>
                        {changes > 0 && (
                            <>
                                <span className="cf-pill cf-pill--warn">
                                    {changes === 1
                                        ? '1 unsaved change'
                                        : `${changes} unsaved changes`}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="lg"
                                    onClick={() => setCurrent(toSets(matrix))}
                                >
                                    Discard
                                </Button>
                            </>
                        )}
                        <Button
                            variant="ghost"
                            size="lg"
                            icon={RotateCcw}
                            onClick={() => setResetOpen(true)}
                        >
                            Reset to defaults
                        </Button>
                        <Button
                            variant="primary"
                            size="lg"
                            disc={Check}
                            loading={saving}
                            disabled={changes === 0}
                            onClick={save}
                        >
                            Save permissions
                        </Button>
                    </>
                )
            }
        >
            <Head title="Roles & permissions" />

            <section
                className="cf-alert cf-alert--info cf-alert--slim"
                aria-label="About permissions"
            >
                <div className="cf-alert-main">
                    <span className="cf-alert-ic members-alert-ic">
                        <Icon icon={Lock} size={18} />
                    </span>
                    <div>
                        <div className="cf-alert-title">
                            Admins always have full access
                        </div>
                        <div className="cf-alert-sub">
                            Tick what Partners, Members and Viewers can open and
                            do. Changes apply the next time they load a page.
                        </div>
                    </div>
                </div>
            </section>

            <div className="matrix-grid">
                <section
                    className="cf-card flex min-w-0 flex-col gap-1.5"
                    aria-labelledby="pg-h"
                >
                    <h2 id="pg-h" className="cf-dot-title t-title mb-1.5">
                        Pages
                    </h2>
                    <TableWrap label="Page access by role">
                        <div
                            className="cf-table min-w-[400px] gap-0.5"
                            role="table"
                            aria-label="Page access by role"
                        >
                            {header('Page')}
                            {pages.map((row) => (
                                <MatrixRow
                                    key={row.key}
                                    row={row}
                                    current={current}
                                    saved={saved}
                                    canEdit={canEdit}
                                    onToggle={toggle}
                                />
                            ))}
                        </div>
                    </TableWrap>
                </section>
                <section
                    className="cf-card flex min-w-0 flex-col gap-1.5"
                    aria-labelledby="ac-h"
                >
                    <h2 id="ac-h" className="cf-dot-title t-title mb-1.5">
                        Actions
                    </h2>
                    <TableWrap label="Actions by role">
                        <div
                            className="cf-table min-w-[400px] gap-0.5"
                            role="table"
                            aria-label="Actions by role"
                        >
                            {header('Action')}
                            {groups.map((group) => (
                                <Fragment key={group}>
                                    <div className="pmg" role="row">
                                        <span role="rowheader">{group}</span>
                                    </div>
                                    {actions
                                        .filter((a) => a.group === group)
                                        .map((row) => (
                                            <MatrixRow
                                                key={row.key}
                                                row={row}
                                                current={current}
                                                saved={saved}
                                                canEdit={canEdit}
                                                onToggle={toggle}
                                            />
                                        ))}
                                </Fragment>
                            ))}
                        </div>
                    </TableWrap>
                    <p className="t-caption t-muted mt-1.5 mb-0">
                        Members only see their own hours and expenses, and vault
                        items shared with them, even with a page ticked.
                    </p>
                </section>
            </div>

            <ConfirmDialog
                open={resetOpen}
                onOpenChange={setResetOpen}
                icon={RotateCcw}
                title="Reset permissions to the defaults?"
                description="Partners, Members and Viewers go back to the standard pages and actions. Any changes you made here are lost."
                confirmLabel="Reset to defaults"
                onConfirm={() => {
                    router.post(resetRoles().url, {}, { preserveScroll: true });
                    setResetOpen(false);
                }}
            />
        </SettingsShell>
    );
}
