import { Head, router } from '@inertiajs/react';
import { Check, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PercentInput } from '@/components/companies/wizard-parts';
import { SettingsShell } from '@/components/settings/settings-shell';
import { initialsFrom } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { IconButton } from '@/components/ui/icon-button';
import {
    formatEquity,
    formatEquityShort,
    parseEquity,
    WHOLE,
} from '@/lib/equity';
import { cn } from '@/lib/utils';
import { update as saveSides } from '@/routes/settings/sides';
import { TableWrap } from '@/components/ui/table-wrap';

type Side = {
    id: number;
    name: string;
    equityBps: number;
    bankAccount: string | null;
    partners: { id: number; name: string; colour: number }[];
};

type Row = {
    key: string;
    id: number | null;
    name: string;
    equity: string;
    partners: Side['partners'];
    bank: string | null;
};

const toRows = (sides: Side[]): Row[] =>
    sides.map((s) => ({
        key: `s${s.id}`,
        id: s.id,
        name: s.name,
        equity: formatEquity(s.equityBps),
        partners: s.partners,
        bank: s.bankAccount,
    }));

/**
 * Settings › Partner sides (SettingsPartners.dc.html "Sides" tab, spec §16): each
 * side's name and share of the JV, totalling 100%. Empty sides can be removed.
 */
export default function Sides({ sides }: { sides: Side[] }) {
    const [rows, setRows] = useState<Row[]>(() => toRows(sides));
    const [last, setLast] = useState(sides);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    if (last !== sides) {
        setLast(sides);
        setRows(toRows(sides));
    }

    const total = rows.reduce(
        (sum, r) => sum + (parseEquity(r.equity) ?? 0),
        0,
    );
    const ok =
        total === WHOLE && rows.every((r) => parseEquity(r.equity) !== null);
    const dirty =
        JSON.stringify(rows.map((r) => [r.id, r.name, r.equity])) !==
        JSON.stringify(toRows(sides).map((r) => [r.id, r.name, r.equity]));
    const partnerCount = rows.reduce((sum, r) => sum + r.partners.length, 0);
    const shownTotal = formatEquityShort(total).replace('%', '');

    const update = (key: string, patch: Partial<Row>) =>
        setRows((rs) =>
            rs.map((r) => (r.key === key ? { ...r, ...patch } : r)),
        );

    const save = () =>
        router.put(
            saveSides().url,
            {
                sides: rows.map((r) => ({
                    id: r.id,
                    name: r.name,
                    equity: r.equity,
                })),
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => setErrors({}),
                onError: setErrors,
            },
        );

    return (
        <SettingsShell
            tab="sides"
            title="Sides"
            crumb="Partner sides"
            actions={
                <>
                    {dirty && (
                        <Button
                            variant="ghost"
                            size="lg"
                            onClick={() => setRows(toRows(sides))}
                        >
                            Discard
                        </Button>
                    )}
                    <Button
                        variant="secondary"
                        size="lg"
                        icon={Plus}
                        onClick={() =>
                            setRows((rs) => [
                                ...rs,
                                {
                                    key: `n${Date.now()}`,
                                    id: null,
                                    name: 'New side',
                                    equity: '0.00',
                                    partners: [],
                                    bank: null,
                                },
                            ])
                        }
                    >
                        Add side
                    </Button>
                    <Button
                        variant="primary"
                        size="lg"
                        disc={Check}
                        loading={saving}
                        disabled={!dirty || !ok}
                        onClick={save}
                    >
                        Save sides
                    </Button>
                </>
            }
        >
            <Head title="Partner sides" />

            <section
                className="cf-card flex flex-col gap-3"
                aria-labelledby="sd-h"
            >
                <div className="cf-kv">
                    <h2 id="sd-h" className="cf-dot-title t-title">
                        Partner sides
                    </h2>
                    <span
                        className={cn(
                            'cf-pill',
                            ok ? 'cf-pill--pos' : 'cf-pill--neg',
                        )}
                    >
                        {ok ? 'Adds up to 100%' : `Adds up to ${shownTotal}%`}
                    </span>
                </div>
                <TableWrap label="Partner sides">
                    <div
                        className="cf-table min-w-[760px]"
                        role="table"
                        aria-label="Partner sides"
                    >
                        <div className="cf-tr cf-thead scols" role="row">
                            <span role="columnheader">Side</span>
                            <span role="columnheader">Equity</span>
                            <span role="columnheader">Partners</span>
                            <span role="columnheader">Bank account</span>
                            <span role="columnheader">
                                <span className="cf-sr">Actions</span>
                            </span>
                        </div>
                        {rows.map((row, index) => (
                            <div
                                key={row.key}
                                className="cf-tr scols"
                                role="row"
                            >
                                <span role="cell">
                                    <input
                                        className="cf-input cf-input--sm"
                                        type="text"
                                        value={row.name}
                                        maxLength={120}
                                        onChange={(e) =>
                                            update(row.key, {
                                                name: e.target.value,
                                            })
                                        }
                                        aria-label={`Name of side ${index + 1}`}
                                    />
                                </span>
                                <span role="cell" className="w-[110px]">
                                    <PercentInput
                                        value={row.equity}
                                        invalid={!ok}
                                        onChange={(equity) =>
                                            update(row.key, { equity })
                                        }
                                        aria-label={`Equity of ${row.name || `side ${index + 1}`}`}
                                    />
                                </span>
                                <span role="cell" className="cf-row gap-2">
                                    <span className="cf-avatars">
                                        {row.partners.map((p) => (
                                            <span
                                                key={p.id}
                                                className="cf-avatar cf-avatar--sm"
                                                title={p.name}
                                            >
                                                {initialsFrom(p.name)}
                                                <span
                                                    className="cf-avatar-dot"
                                                    style={{
                                                        background: `var(--partner-${p.colour})`,
                                                    }}
                                                />
                                            </span>
                                        ))}
                                    </span>
                                    <span className="t-body t-muted">
                                        {row.partners.length}{' '}
                                        {row.partners.length === 1
                                            ? 'partner'
                                            : 'partners'}
                                    </span>
                                </span>
                                <span
                                    role="cell"
                                    className="t-money-sm t-muted"
                                >
                                    {row.bank ?? 'Not set'}
                                </span>
                                <span role="cell" className="cf-rowacts">
                                    {row.partners.length === 0 &&
                                        rows.length > 2 && (
                                            <IconButton
                                                icon={Trash2}
                                                size="sm"
                                                aria-label={`Remove ${row.name || `side ${index + 1}`}`}
                                                onClick={() =>
                                                    setRows((rs) =>
                                                        rs.filter(
                                                            (r) =>
                                                                r.key !==
                                                                row.key,
                                                        ),
                                                    )
                                                }
                                            />
                                        )}
                                </span>
                            </div>
                        ))}
                        <div className="cf-tr is-total scols" role="row">
                            <span role="cell">Total</span>
                            <span
                                role="cell"
                                className={cn('t-money', !ok && 't-neg')}
                            >
                                {shownTotal}%
                            </span>
                            <span role="cell" className="t-body">
                                {partnerCount} partners
                            </span>
                            <span role="cell" />
                            <span role="cell" />
                        </div>
                    </div>
                </TableWrap>
                {!ok && (
                    <FieldError>
                        Side equity must add up to 100%. It’s {shownTotal}% now.
                    </FieldError>
                )}
                {errors.sides && ok && <FieldError>{errors.sides}</FieldError>}
            </section>

            <section className="cf-card cf-card--graphite flex flex-col gap-2">
                <h2 className="t-title m-0">How sides work</h2>
                <p className="t-body m-0 opacity-80">
                    Sides only exist in joint ventures. Each side owns a share
                    of the company, and its partners split that share between
                    them. Settlements move money between sides so each one holds
                    what it’s entitled to.
                </p>
            </section>
        </SettingsShell>
    );
}
