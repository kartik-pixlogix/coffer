import { Head, Link } from '@inertiajs/react';
import {
    Banknote,
    Ellipsis,
    Pencil,
    Plus,
    Trash2,
    Upload,
    UserRoundCheck,
    UserRoundX,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/shell/page-header';
import { MemberConfirm } from '@/components/team/member-confirm';
import type { MemberConfirmState } from '@/components/team/member-confirm';
import { MemberModal } from '@/components/team/member-modal';
import { RunSalariesModal } from '@/components/team/run-salaries-modal';
import { initialsFrom, partnerColourVar } from '@/components/ui/avatar';
import type { PartnerColour } from '@/components/ui/avatar';
import { Button, ButtonLink } from '@/components/ui/button';
import { DropdownMenu } from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { IconButton } from '@/components/ui/icon-button';
import { Tab, TabPanel, Tabs, TabsList } from '@/components/ui/tabs';
import { formatInr } from '@/lib/format';
import { formatCenti } from '@/lib/hours';
import { firstName, monthLong, rateLabel } from '@/lib/team';
import { cn } from '@/lib/utils';
import { show as memberProfile } from '@/routes/team';
import { importMethod as importPage } from '@/routes/timesheets';
import type {
    PartnerOption,
    TeamCan,
    TeamMember,
    UserOption,
} from '@/types/team';

type Member = TeamMember & { billableCenti: number; earningsPaise: number };

type Props = {
    company: { name: string; workspace: string };
    /** First day of this month, "YYYY-MM-DD". */
    month: string;
    partners: PartnerOption[];
    members: Member[];
    abilities: TeamCan;
    users: UserOption[];
};

/**
 * Team (Team.dc.html, spec §7): members by the partner they bill to, with this month's
 * billable hours and earnings. Managers add, edit, deactivate and delete; members with
 * hours or salary history can only be deactivated.
 */
export default function TeamIndex({
    company,
    month,
    partners,
    members,
    abilities: can,
    users,
}: Props) {
    const [tab, setTab] = useState('all');
    const [editing, setEditing] = useState<TeamMember | null>(null);
    const [modalOpen, setModalOpen] = useState(false);
    const [confirm, setConfirm] = useState<MemberConfirmState>(null);
    const [salariesOpen, setSalariesOpen] = useState(false);

    const partnerOf = new Map(partners.map((p) => [p.id, p]));
    const shown =
        tab === 'all'
            ? members
            : members.filter((m) => String(m.partnerId) === tab);

    const openModal = (member: TeamMember | null) => {
        setEditing(member);
        setModalOpen(true);
    };

    return (
        <>
            <Head title="Team" />
            <PageHeader
                kicker="People"
                title="Team members"
                crumbs={[{ title: 'Team' }]}
                actions={
                    can.manage && (
                        <>
                            {can.runSalaries && (
                                <Button
                                    variant="ghost"
                                    size="lg"
                                    icon={Banknote}
                                    onClick={() => setSalariesOpen(true)}
                                >
                                    Run salaries
                                </Button>
                            )}
                            <ButtonLink
                                href={importPage().url}
                                variant="secondary"
                                size="lg"
                                icon={Upload}
                            >
                                Import CSV
                            </ButtonLink>
                            <Button
                                variant="primary"
                                size="lg"
                                disc={Plus}
                                onClick={() => openModal(null)}
                            >
                                Add member
                            </Button>
                        </>
                    )
                }
            />

            <Tabs value={tab} onValueChange={setTab} asChild>
                <section className="cf-card flex flex-col gap-3.5">
                    <div className="cf-kv flex-wrap gap-3">
                        <TabsList aria-label="Team by partner">
                            <Tab value="all" count={members.length}>
                                All
                            </Tab>
                            {partners.map((p) => (
                                <Tab
                                    key={p.id}
                                    value={String(p.id)}
                                    count={
                                        members.filter(
                                            (m) => m.partnerId === p.id,
                                        ).length
                                    }
                                >
                                    {firstName(p.name)}
                                </Tab>
                            ))}
                        </TabsList>
                        <span className="t-caption t-muted">
                            {monthLong(month)} · billable hours and earnings
                            this month
                        </span>
                    </div>
                    <TabPanel value={tab}>
                        {shown.length === 0 ? (
                            <EmptyState
                                icon={Users}
                                title={
                                    tab === 'all'
                                        ? 'No team members yet'
                                        : `No one bills to ${firstName(partnerOf.get(Number(tab))?.name ?? '')} yet`
                                }
                                description="Members log hours and cost money: an hourly rate or a fixed monthly cost."
                                actions={
                                    can.manage && (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            icon={Plus}
                                            onClick={() => openModal(null)}
                                        >
                                            Add member
                                        </Button>
                                    )
                                }
                            />
                        ) : (
                            <div className="team-grid">
                                {shown.map((m) => (
                                    <MemberCard
                                        key={m.id}
                                        member={m}
                                        partner={partnerOf.get(m.partnerId)}
                                        abilities={can}
                                        onEdit={() => openModal(m)}
                                        onStatus={() =>
                                            setConfirm({
                                                kind: 'status',
                                                member: m,
                                            })
                                        }
                                        onDelete={() =>
                                            setConfirm({
                                                kind: 'delete',
                                                member: m,
                                            })
                                        }
                                    />
                                ))}
                            </div>
                        )}
                    </TabPanel>
                </section>
            </Tabs>

            {can.manage && (
                <MemberModal
                    open={modalOpen}
                    onOpenChange={setModalOpen}
                    member={editing}
                    partners={partners}
                    users={users}
                    companyName={company.name}
                    workspaceName={company.workspace}
                    canEditRates={can.editRates}
                />
            )}

            {can.runSalaries && (
                <RunSalariesModal
                    open={salariesOpen}
                    onOpenChange={setSalariesOpen}
                    today={month}
                />
            )}

            <MemberConfirm confirm={confirm} onClose={() => setConfirm(null)} />
        </>
    );
}

function MemberCard({
    member: m,
    partner,
    abilities: can,
    onEdit,
    onStatus,
    onDelete,
}: {
    member: Member;
    partner: PartnerOption | undefined;
    abilities: TeamCan;
    onEdit: () => void;
    onStatus: () => void;
    onDelete: () => void;
}) {
    const dot = partner
        ? partnerColourVar(partner.colour as PartnerColour)
        : 'var(--line-strong)';

    return (
        <article
            className={cn(
                'cf-card cf-card--white cf-lift flex flex-col gap-3.5',
                !m.active && 'opacity-70',
            )}
        >
            <div className="cf-row items-start gap-3">
                <span className="cf-avatar cf-avatar--lg" aria-hidden="true">
                    {initialsFrom(m.name)}
                    <span
                        className="cf-avatar-dot"
                        style={{ background: dot, width: 12, height: 12 }}
                    />
                </span>
                <span className="cf-stack min-w-0 flex-1">
                    <Link
                        href={memberProfile(m.id).url}
                        className="t-label-strong team-name"
                    >
                        {m.name}
                    </Link>
                    <span className="t-caption t-muted">
                        {m.designation ?? 'Team member'}
                    </span>
                </span>
                <span className={cn('cf-pill', m.active && 'cf-pill--pos')}>
                    {m.active ? 'Active' : 'Inactive'}
                </span>
                {can.manage && (
                    <DropdownMenu
                        aria-label={`Actions for ${m.name}`}
                        align="end"
                        trigger={
                            <IconButton
                                icon={Ellipsis}
                                size="sm"
                                variant="bare"
                                aria-label={`Actions for ${m.name}`}
                            />
                        }
                        entries={[
                            { label: 'Edit', icon: Pencil, onSelect: onEdit },
                            m.active
                                ? {
                                      label: 'Deactivate',
                                      icon: UserRoundX,
                                      onSelect: onStatus,
                                  }
                                : {
                                      label: 'Reactivate',
                                      icon: UserRoundCheck,
                                      onSelect: onStatus,
                                  },
                            ...(m.hasHistory
                                ? []
                                : [
                                      {
                                          label: 'Delete',
                                          icon: Trash2,
                                          danger: true,
                                          onSelect: onDelete,
                                      },
                                  ]),
                        ]}
                    />
                )}
            </div>
            <span className="cf-pill cf-pill--outline self-start">
                <span
                    className="cf-swatch"
                    style={{ background: dot, width: 8, height: 8 }}
                />
                Bills to {firstName(partner?.name ?? '')}
            </span>
            <div className="grid grid-cols-3 gap-2">
                <div className="cf-stack">
                    <span className="t-caption t-muted">
                        {m.costType === 'hourly' ? 'Rate' : 'Monthly cost'}
                    </span>
                    <span className="t-money-sm text-[13px]">
                        {rateLabel(m)}
                    </span>
                </div>
                <div className="cf-stack">
                    <span className="t-caption t-muted">Billable</span>
                    <span className="t-money-sm text-[13px]">
                        {formatCenti(m.billableCenti, 0)} h
                    </span>
                </div>
                <div className="cf-stack">
                    <span className="t-caption t-muted">Earnings</span>
                    <span className="t-money-sm text-[13px]">
                        {formatInr(m.earningsPaise, { decimals: 'never' })}
                    </span>
                </div>
            </div>
        </article>
    );
}
