import { Head, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    Check,
    Lock,
    Pencil,
    Plus,
    Tags,
} from 'lucide-react';
import { useId, useState } from 'react';
import { SettingsShell } from '@/components/settings/settings-shell';
import { Button } from '@/components/ui/button';
import type { DataTableColumn } from '@/components/ui/data-table';
import { DataTable } from '@/components/ui/data-table';
import { IconButton } from '@/components/ui/icon-button';
import { Modal } from '@/components/ui/modal';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { StatusPill } from '@/components/ui/status-pill';
import { TextInput } from '@/components/ui/text-input';
import { Tooltip } from '@/components/ui/tooltip';
import { useToast } from '@/components/ui/toast';
import {
    archive as toggleArchive,
    store as addCategory,
    update as renameCategory,
} from '@/routes/settings/categories';

type Kind = 'income' | 'expense';

type Category = {
    id: number;
    kind: Kind;
    name: string;
    system: boolean;
    archived: boolean;
    /** Records (income, expenses, journal entries) that use it. */
    records: number;
};

const KIND_LABEL: Record<Kind, string> = {
    income: 'Income categories',
    expense: 'Expense categories',
};

/**
 * Settings › Categories (spec §16, laid out like SettingsCompany.dc.html): income and
 * expense categories with add, rename and archive. Salary is a system category, locked.
 */
export default function Categories({ categories }: { categories: Category[] }) {
    const [editing, setEditing] = useState<Category | null>(null);
    const [adding, setAdding] = useState<Kind | null>(null);
    const toast = useToast();

    const archive = (c: Category) =>
        router.patch(
            toggleArchive(c.id).url,
            {},
            {
                preserveScroll: true,
                onError: (bag) =>
                    toast.error(bag.category ?? 'This category can’t change.'),
            },
        );

    const columns: DataTableColumn<Category>[] = [
        {
            key: 'name',
            header: 'Category',
            width: 'minmax(200px,1.6fr)',
            sortable: true,
            sortValue: (c) => c.name.toLowerCase(),
            cell: (c) => (
                <span className="cf-row t-label-strong">
                    {c.name}
                    {c.system && (
                        <StatusPill tone="neutral" icon={Lock} size="sm">
                            System
                        </StatusPill>
                    )}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            width: '130px',
            skeleton: 'pill',
            cell: (c) =>
                c.archived ? (
                    <StatusPill tone="neutral" icon={Archive}>
                        Archived
                    </StatusPill>
                ) : (
                    <StatusPill tone="pos" icon={Check}>
                        Active
                    </StatusPill>
                ),
        },
        {
            key: 'records',
            header: 'Entries',
            width: '100px',
            numeric: true,
            cell: (c) => (
                <span className="t-money-sm">
                    {c.records.toLocaleString('en-IN')}
                </span>
            ),
        },
    ];

    return (
        <SettingsShell
            tab="categories"
            title="Categories"
            crumb="Categories"
            actions={
                <Button
                    variant="primary"
                    size="lg"
                    disc={Plus}
                    onClick={() => setAdding('expense')}
                >
                    Add category
                </Button>
            }
        >
            <Head title="Categories" />

            {(['income', 'expense'] as const).map((kind) => {
                const rows = categories.filter((c) => c.kind === kind);
                const active = rows.filter((c) => !c.archived).length;

                return (
                    <section
                        key={kind}
                        className="cf-card flex flex-col gap-3.5"
                        aria-labelledby={`cat-${kind}`}
                    >
                        <div className="cf-kv">
                            <h2
                                id={`cat-${kind}`}
                                className="cf-dot-title t-title"
                            >
                                {KIND_LABEL[kind]}
                            </h2>
                            <span className="cf-row">
                                <span className="t-caption t-muted">
                                    {active} active
                                </span>
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    icon={Plus}
                                    onClick={() => setAdding(kind)}
                                >
                                    Add
                                </Button>
                            </span>
                        </div>
                        <DataTable
                            aria-label={KIND_LABEL[kind]}
                            rows={rows}
                            columns={columns}
                            getRowId={(c) => String(c.id)}
                            minWidth={520}
                            rowClassName={(c) =>
                                c.archived ? 'opacity-70' : undefined
                            }
                            empty={{ title: 'No categories yet', icon: Tags }}
                            rowActions={(c) =>
                                c.system ? (
                                    <span className="cf-rowacts">
                                        <Tooltip label="Salary runs post here, so it can’t be renamed or archived">
                                            <span
                                                className="cf-iconbtn cf-iconbtn--sm"
                                                tabIndex={0}
                                                aria-label={`${c.name} is a system category`}
                                            >
                                                <Lock
                                                    size={14}
                                                    strokeWidth={1.5}
                                                    aria-hidden="true"
                                                />
                                            </span>
                                        </Tooltip>
                                    </span>
                                ) : (
                                    <span className="cf-rowacts">
                                        <IconButton
                                            icon={Pencil}
                                            size="sm"
                                            aria-label={`Rename ${c.name}`}
                                            onClick={() => setEditing(c)}
                                        />
                                        <IconButton
                                            icon={
                                                c.archived
                                                    ? ArchiveRestore
                                                    : Archive
                                            }
                                            size="sm"
                                            aria-label={
                                                c.archived
                                                    ? `Restore ${c.name}`
                                                    : `Archive ${c.name}`
                                            }
                                            onClick={() => archive(c)}
                                        />
                                    </span>
                                )
                            }
                        />
                    </section>
                );
            })}

            <CategoryModal
                open={adding !== null || editing !== null}
                onOpenChange={(o) => {
                    if (!o) {
                        setAdding(null);
                        setEditing(null);
                    }
                }}
                category={editing}
                kind={editing?.kind ?? adding ?? 'expense'}
            />
        </SettingsShell>
    );
}

function CategoryModal({
    open,
    onOpenChange,
    category,
    kind: initialKind,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    category: Category | null;
    kind: Kind;
}) {
    const [name, setName] = useState('');
    const [kind, setKind] = useState<Kind>(initialKind);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [shownFor, setShownFor] = useState<string | null>(null);
    const kindLabel = useId();

    const key = open ? `${category?.id ?? 'new'}-${initialKind}` : null;

    if (key !== shownFor) {
        setShownFor(key);
        setName(category?.name ?? '');
        setKind(initialKind);
        setErrors({});
    }

    const save = () => {
        const options = {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => onOpenChange(false),
            onError: (bag: Record<string, string>) => setErrors(bag),
        };

        if (category) {
            router.put(renameCategory(category.id).url, { name }, options);
        } else {
            router.post(addCategory().url, { kind, name }, options);
        }
    };

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title={category ? 'Rename category' : 'Add category'}
            subtitle="Used by income and expenses, and their ledger accounts"
            icon={Tags}
            size="sm"
            dirty
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
                        disc={Check}
                        loading={saving}
                        disabled={name.trim() === ''}
                        onClick={save}
                    >
                        {category ? 'Save category' : 'Add category'}
                    </Button>
                </>
            }
        >
            {!category && (
                <div className="cf-field">
                    <span className="cf-label" id={kindLabel}>
                        Type
                    </span>
                    <SegmentedControl<Kind>
                        aria-labelledby={kindLabel}
                        value={kind}
                        onValueChange={setKind}
                        options={[
                            { value: 'expense', label: 'Expense' },
                            { value: 'income', label: 'Income' },
                        ]}
                    />
                </div>
            )}
            <TextInput
                id="cat-n"
                label="Name"
                value={name}
                maxLength={60}
                onChange={(e) => setName(e.target.value)}
                error={errors.name ?? errors.category}
            />
        </Modal>
    );
}
