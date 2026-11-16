import { Head, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    Check,
    FolderKanban,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { SettingsShell } from '@/components/settings/settings-shell';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import type { DataTableColumn } from '@/components/ui/data-table';
import { DataTable } from '@/components/ui/data-table';
import { IconButton } from '@/components/ui/icon-button';
import { Modal } from '@/components/ui/modal';
import { StatusPill } from '@/components/ui/status-pill';
import { TextInput } from '@/components/ui/text-input';
import { useToast } from '@/components/ui/toast';
import {
    destroy as deleteProject,
    status as toggleStatus,
    store as addProject,
    update as updateProject,
} from '@/routes/settings/projects';

type Project = {
    id: number;
    name: string;
    client: string | null;
    status: 'active' | 'archived';
    /** Timesheet entries and ledger entries that point at it. */
    records: number;
};

/**
 * Settings › Projects (spec §16, laid out like SettingsCompany.dc.html): name, client
 * and status. Projects in use can be archived (they stay on old records); unused
 * ones can be deleted.
 */
export default function Projects({ projects }: { projects: Project[] }) {
    const [editing, setEditing] = useState<Project | null>(null);
    const [open, setOpen] = useState(false);
    const [removing, setRemoving] = useState<Project | null>(null);
    const toast = useToast();

    const active = projects.filter((p) => p.status === 'active').length;

    const openModal = (project: Project | null) => {
        setEditing(project);
        setOpen(true);
    };

    const columns: DataTableColumn<Project>[] = [
        {
            key: 'name',
            header: 'Project',
            width: 'minmax(180px,1.4fr)',
            sortable: true,
            sortValue: (p) => p.name.toLowerCase(),
            cell: (p) => <span className="t-label-strong">{p.name}</span>,
        },
        {
            key: 'client',
            header: 'Client',
            width: 'minmax(160px,1.2fr)',
            sortable: true,
            sortValue: (p) => (p.client ?? '').toLowerCase(),
            cell: (p) =>
                p.client ? (
                    <span className="t-body">{p.client}</span>
                ) : (
                    <span className="t-body t-muted">No client</span>
                ),
        },
        {
            key: 'status',
            header: 'Status',
            width: '130px',
            skeleton: 'pill',
            cell: (p) =>
                p.status === 'active' ? (
                    <StatusPill tone="pos" icon={Check}>
                        Active
                    </StatusPill>
                ) : (
                    <StatusPill tone="neutral" icon={Archive}>
                        Archived
                    </StatusPill>
                ),
        },
        {
            key: 'records',
            header: 'Entries',
            width: '100px',
            numeric: true,
            cell: (p) => (
                <span className="t-money-sm">
                    {p.records.toLocaleString('en-IN')}
                </span>
            ),
        },
    ];

    return (
        <SettingsShell
            tab="projects"
            title="Projects"
            crumb="Projects"
            actions={
                <Button
                    variant="primary"
                    size="lg"
                    disc={Plus}
                    onClick={() => openModal(null)}
                >
                    Add project
                </Button>
            }
        >
            <Head title="Projects" />

            <section
                className="cf-card flex flex-col gap-3.5"
                aria-labelledby="pj-h"
            >
                <div className="cf-kv">
                    <h2 id="pj-h" className="cf-dot-title t-title">
                        Projects
                    </h2>
                    <span className="t-caption t-muted">
                        {active} active · used by timesheets, income and
                        expenses
                    </span>
                </div>
                <DataTable
                    aria-label="Projects"
                    rows={projects}
                    columns={columns}
                    getRowId={(p) => String(p.id)}
                    minWidth={640}
                    rowClassName={(p) =>
                        p.status === 'archived' ? 'opacity-70' : undefined
                    }
                    empty={{
                        title: 'No projects yet',
                        icon: FolderKanban,
                        action: (
                            <Button
                                variant="secondary"
                                size="sm"
                                icon={Plus}
                                onClick={() => openModal(null)}
                            >
                                Add project
                            </Button>
                        ),
                    }}
                    rowActions={(p) => (
                        <span className="cf-rowacts">
                            <IconButton
                                icon={Pencil}
                                size="sm"
                                aria-label={`Edit ${p.name}`}
                                onClick={() => openModal(p)}
                            />
                            <IconButton
                                icon={
                                    p.status === 'active'
                                        ? Archive
                                        : ArchiveRestore
                                }
                                size="sm"
                                aria-label={
                                    p.status === 'active'
                                        ? `Archive ${p.name}`
                                        : `Restore ${p.name}`
                                }
                                onClick={() =>
                                    router.patch(
                                        toggleStatus(p.id).url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            />
                            {p.records === 0 && (
                                <IconButton
                                    icon={Trash2}
                                    size="sm"
                                    aria-label={`Delete ${p.name}`}
                                    onClick={() => setRemoving(p)}
                                />
                            )}
                        </span>
                    )}
                />
            </section>

            <ProjectModal
                open={open}
                onOpenChange={setOpen}
                project={editing}
            />

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(o) => !o && setRemoving(null)}
                title={`Delete ${removing?.name ?? 'project'}?`}
                description="Nothing uses this project yet, so it goes for good."
                icon={Trash2}
                confirmLabel="Delete project"
                onConfirm={() => {
                    if (!removing) {
                        return;
                    }

                    router.delete(deleteProject(removing.id).url, {
                        preserveScroll: true,
                        onFinish: () => setRemoving(null),
                        onError: (bag) =>
                            toast.error(
                                bag.project ?? 'This project can’t be deleted.',
                            ),
                    });
                }}
            />
        </SettingsShell>
    );
}

function ProjectModal({
    open,
    onOpenChange,
    project,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    project: Project | null;
}) {
    const [name, setName] = useState('');
    const [client, setClient] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [shownFor, setShownFor] = useState<string | null>(null);

    // Each opening starts from the project's saved values.
    const key = open ? String(project?.id ?? 'new') : null;

    if (key !== shownFor) {
        setShownFor(key);
        setName(project?.name ?? '');
        setClient(project?.client ?? '');
        setErrors({});
    }

    const save = () => {
        const payload = { name, client: client.trim() || null };
        const options = {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => onOpenChange(false),
            onError: (bag: Record<string, string>) => setErrors(bag),
        };

        if (project) {
            router.put(updateProject(project.id).url, payload, options);
        } else {
            router.post(addProject().url, payload, options);
        }
    };

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title={project ? 'Edit project' : 'Add project'}
            subtitle="Used by timesheets, income and expenses"
            icon={FolderKanban}
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
                        {project ? 'Save project' : 'Add project'}
                    </Button>
                </>
            }
        >
            <TextInput
                id="pj-n"
                label="Name"
                value={name}
                maxLength={120}
                onChange={(e) => setName(e.target.value)}
                error={errors.name}
            />
            <TextInput
                id="pj-c"
                label="Client"
                value={client}
                maxLength={120}
                help="Optional. Who the work is for."
                onChange={(e) => setClient(e.target.value)}
                error={errors.client}
            />
        </Modal>
    );
}
