import { Head, router, usePage } from '@inertiajs/react';
import { Download, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/shell/page-header';
import { Avatar } from '@/components/ui/avatar';
import { Button, ButtonLink } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { StatusPill } from '@/components/ui/status-pill';
import { formatDateTime } from '@/components/vault/home/vault-home.utils';
import { OffboardingCard } from '@/components/vault/offboarding/offboarding-card';
import { VaultGate } from '@/components/vault/vault-gate';
import { useCountUp } from '@/hooks/use-count-up';
import { cn } from '@/lib/utils';
import { index as vaultIndex } from '@/routes/vault';
import { check, exportMethod as exportReport } from '@/routes/vault/health';
import { edit } from '@/routes/vault/items';
import type {
    VaultHealthKey,
    VaultHealthList,
    VaultHealthProps,
    VaultHealthReport,
} from '@/types/vault';

/** Tile captions and ink (VaultHealth.dc.html). */
const TILES: Record<
    VaultHealthKey,
    { label: string; sub: string; ink: 't-neg' | 't-warn' | '' }
> = {
    weak: {
        label: 'Weak passwords',
        sub: 'Short or easy to guess',
        ink: 't-neg',
    },
    reused: {
        label: 'Reused passwords',
        sub: 'Same password, 2+ items',
        ink: 't-warn',
    },
    old: {
        label: 'Older than 180 days',
        sub: 'Not changed in 6 months',
        ink: 't-warn',
    },
    breached: {
        label: 'Found in a breach',
        sub: 'Change these first',
        ink: 't-neg',
    },
    expiring: {
        label: 'Expiring soon',
        sub: 'In the next 30 days',
        ink: 't-warn',
    },
    no2fa: { label: 'Without 2FA', sub: 'Password only', ink: '' },
};

const RING = 452.39;

function gradeLabel(grade: string | null): string {
    return grade === null
        ? 'No score yet'
        : grade.charAt(0).toUpperCase() + grade.slice(1);
}

/**
 * Security health (VaultHealth.dc.html, spec §14.4): the score, six filter tiles, the
 * fix-it lists with Rotate (edit with the generator open), Renew and Add 2FA, and the
 * rotate lists for people who left. Rotating raises the score on the next visit.
 */
export default function VaultHealthPage(props: VaultHealthProps) {
    const { vaultState, report } = props;

    return (
        <>
            <Head title="Security health" />
            <PageHeader
                kicker="Vault"
                title="Security health"
                crumbs={[
                    { title: 'Credentials Vault', href: vaultIndex().url },
                    { title: 'Security health' },
                ]}
                actions={
                    report && (
                        <HeaderActions
                            report={report}
                            breachChecks={props.breachChecks}
                        />
                    )
                }
            />
            <VaultGate vaultState={vaultState}>
                {report && <HealthBody {...props} report={report} />}
            </VaultGate>
        </>
    );
}

function HeaderActions({
    report,
    breachChecks,
}: {
    report: VaultHealthReport;
    breachChecks: boolean;
}) {
    const { auth } = usePage().props;
    const [running, setRunning] = useState(false);

    return (
        <>
            <span className="t-caption t-muted">
                {!breachChecks
                    ? 'Breach checks are off'
                    : report.checkedAt
                      ? `Checked ${formatDateTime(report.checkedAt, auth.user.timezone)}`
                      : 'Not checked against breach lists yet'}
            </span>
            {breachChecks && (
                <Button
                    variant="secondary"
                    size="lg"
                    loading={running}
                    onClick={() =>
                        router.post(
                            check().url,
                            {},
                            {
                                preserveScroll: true,
                                onStart: () => setRunning(true),
                                onFinish: () => setRunning(false),
                            },
                        )
                    }
                >
                    {running ? 'Checking' : 'Run check again'}
                </Button>
            )}
            <a
                href={exportReport().url}
                className="cf-btn cf-btn--muted cf-btn--lg"
                download
            >
                <Icon icon={Download} size={18} />
                Export report
            </a>
        </>
    );
}

function HealthBody({
    report,
    offboardings,
    filter: initialFilter,
    abilities,
}: VaultHealthProps & { report: VaultHealthReport }) {
    const { auth } = usePage().props;
    const [filter, setFilter] = useState<VaultHealthKey | null>(initialFilter);
    const score = useCountUp(report.score ?? 0);
    const lists = report.lists.filter(
        (l) => filter === null || l.key === filter,
    );
    const biggest =
        report.biggest === null
            ? null
            : report.lists.find((l) => l.key === report.biggest);

    return (
        <div className="cf-stack vhp-page">
            <section className="vhp-top">
                <article
                    className="cf-card cf-card--graphite vhp-score"
                    aria-label="Health score"
                >
                    <span
                        className={cn(
                            'cf-ring cf-ring--dark',
                            (report.score ?? 100) < 60 && 'is-warn',
                        )}
                        role="img"
                        aria-label={
                            report.score === null
                                ? 'No health score yet'
                                : `Health score ${report.score} out of 100`
                        }
                    >
                        <svg
                            width="168"
                            height="168"
                            viewBox="0 0 168 168"
                            aria-hidden="true"
                        >
                            <circle
                                className="cf-ring-track"
                                cx="84"
                                cy="84"
                                r="72"
                                strokeWidth="12"
                            />
                            <circle
                                className="cf-ring-bar"
                                cx="84"
                                cy="84"
                                r="72"
                                strokeWidth="12"
                                strokeDasharray={RING}
                                strokeDashoffset={
                                    Math.round(RING * (1 - score / 100) * 100) /
                                    100
                                }
                                transform="rotate(-90 84 84)"
                            />
                        </svg>
                        <span className="cf-ring-label vhp-ring-label">
                            <span className="t-figure-hero vhp-ring-n">
                                {report.score === null ? '—' : score}
                            </span>
                            <span className="t-caption vh-on-muted">
                                out of 100
                            </span>
                        </span>
                    </span>
                    <div className="cf-stack vhp-score-text">
                        <StatusPill tone="lime" className="vhp-grade">
                            {gradeLabel(report.grade)}
                        </StatusPill>
                        <h2 className="t-heading m-0">
                            {report.issues > 0
                                ? `${report.issues} ${report.issues === 1 ? 'thing' : 'things'} to fix across ${report.affected} ${report.affected === 1 ? 'item' : 'items'}`
                                : 'Nothing left to fix'}
                        </h2>
                        <p className="t-body vh-on-muted m-0">
                            {report.issues > 0 && biggest
                                ? `Fixing the ${biggest.title.toLocaleLowerCase('en-IN')} first gets you the biggest jump.`
                                : report.total === 0
                                  ? 'Add items to the vault to see their health.'
                                  : 'Every item is strong, unique, current and protected with 2FA.'}
                        </p>
                    </div>
                </article>
                <div
                    className="vhp-tiles"
                    role="group"
                    aria-label="Filter issues"
                >
                    {report.lists.map((l) => {
                        const n = report.counts[l.key];
                        const tile = TILES[l.key];

                        return (
                            <button
                                key={l.key}
                                type="button"
                                className="vhp-tile"
                                aria-pressed={filter === l.key}
                                onClick={() =>
                                    setFilter(filter === l.key ? null : l.key)
                                }
                            >
                                <span className="cf-dot-title t-label">
                                    {tile.label}
                                </span>
                                <span
                                    className={cn(
                                        'vhp-tile-n',
                                        n > 0 ? tile.ink : 't-pos',
                                    )}
                                >
                                    {n}
                                </span>
                                <span className="t-caption t-muted">
                                    {tile.sub}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </section>

            {filter !== null && (
                <div className="cf-row">
                    <span className="t-body t-muted">
                        Showing{' '}
                        {report.lists
                            .find((l) => l.key === filter)
                            ?.title.toLocaleLowerCase('en-IN')}
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setFilter(null)}
                    >
                        Show all issues
                    </Button>
                </div>
            )}

            {lists.map((list) => (
                <FixList key={list.key} list={list} />
            ))}

            <OffboardingCard
                offboardings={offboardings}
                canDismiss={abilities.admin}
                timeZone={auth.user.timezone}
            />
        </div>
    );
}

function FixList({ list }: { list: VaultHealthList }) {
    const open = list.rows.length;
    const tone = TILES[list.key].ink === 't-neg' ? 'neg' : 'warn';
    const href = (id: number) =>
        list.key === 'expiring' || list.key === 'no2fa'
            ? edit(id).url
            : edit(id, { query: { rotate: 1 } }).url;

    return (
        <section className="cf-card cf-stack vhp-list" aria-label={list.title}>
            <div className="cf-kv vhp-list-head">
                <h2 className="cf-dot-title t-title">{list.title}</h2>
                <StatusPill tone={open ? tone : 'pos'} size="sm">
                    {open ? `${open} to fix` : 'All fixed'}
                </StatusPill>
            </div>
            <p className="t-caption t-muted m-0">{list.why}</p>
            {list.rows.map((row) => (
                <div key={row.id} className="vhp-fx">
                    <Avatar
                        name={row.name}
                        initials={row.monogram}
                        tile
                        label={false}
                    />
                    <span className="cf-stack vhp-fx-text">
                        <a
                            href={vaultIndex({ query: { item: row.id } }).url}
                            className="t-label-strong cf-link vhp-fx-name"
                        >
                            {row.name}
                        </a>
                        <span className="t-caption t-muted">{row.detail}</span>
                    </span>
                    {row.canFix ? (
                        <ButtonLink
                            size="sm"
                            variant={
                                list.key === 'breached' ? 'danger' : 'secondary'
                            }
                            icon={
                                list.key === 'expiring' || list.key === 'no2fa'
                                    ? undefined
                                    : RefreshCw
                            }
                            href={href(row.id)}
                            aria-label={`${list.action} ${row.name}`}
                        >
                            {list.action}
                        </ButtonLink>
                    ) : (
                        <span className="t-caption t-muted">Ask a Manager</span>
                    )}
                </div>
            ))}
            {open === 0 && (
                <p className="t-caption t-muted m-0">Nothing here. Nice.</p>
            )}
        </section>
    );
}
