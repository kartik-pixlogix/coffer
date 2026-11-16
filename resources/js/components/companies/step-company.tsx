import { Check, AlertTriangle, Plus } from 'lucide-react';
import type { Dispatch, SetStateAction } from 'react';
import {
    EquityBar,
    GroupError,
    PercentInput,
    RemoveRow,
    RowSelect,
    TotalPill,
} from '@/components/companies/wizard-parts';
import { AmountInput } from '@/components/ui/amount-input';
import { initialsFrom } from '@/components/ui/avatar';
import { Button, TextButton } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { TextInput } from '@/components/ui/text-input';
import {
    addPartner,
    evenPartners,
    evenSides,
    newKey,
    nextColour,
    overallBps,
    sideCheck,
    sidesCheck,
    singleCheck,
    toJv,
    toSingle,
} from '@/lib/company-wizard';
import type {
    PartnerRole,
    PartnerRow,
    WizardState,
} from '@/lib/company-wizard';
import { formatEquity, formatEquityShort, parseEquity } from '@/lib/equity';
import { fiscalYearStart, formatDate } from '@/lib/format';

const CURRENCY_LABELS: Record<string, string> = {
    INR: 'INR — Indian rupee (₹)',
    USD: 'USD — US dollar ($)',
    AED: 'AED — UAE dirham',
};

const ROLE_OPTIONS: { value: PartnerRole; label: string }[] = [
    { value: 'admin', label: 'Admin' },
    { value: 'partner', label: 'Partner' },
];

type Props = {
    state: WizardState;
    setState: Dispatch<SetStateAction<WizardState>>;
    errors: Record<string, string>;
    ownerEmail: string;
    currencies: string[];
};

function updatePartner(
    setState: Props['setState'],
    key: string,
    patch: Partial<PartnerRow>,
) {
    setState((s) => ({
        ...s,
        partners: s.partners.map((p) =>
            p.key === key ? { ...p, ...patch } : p,
        ),
    }));
}

function CompanyCard({ state, setState, errors, currencies }: Props) {
    const jv = state.type === 'jv';
    const fyStart = formatDate(fiscalYearStart(new Date(), 4));

    return (
        <section className="cf-card wiz-card" aria-labelledby="cc-co">
            <h2 id="cc-co" className="cf-dot-title t-title">
                Company
            </h2>
            <div className="cf-field">
                <span className="cf-label" id="cc-type">
                    Type
                </span>
                <SegmentedControl
                    aria-labelledby="cc-type"
                    className="max-w-[420px]"
                    value={state.type}
                    options={[
                        { value: 'single', label: 'Single company' },
                        { value: 'jv', label: 'Joint venture' },
                    ]}
                    onValueChange={(type) =>
                        setState((s) => (type === 'jv' ? toJv(s) : toSingle(s)))
                    }
                />
                {!jv && (
                    <span className="cf-help">
                        A single company has its own partners. A joint venture
                        pools two or more companies (sides).
                    </span>
                )}
            </div>
            <div className={jv ? 'wiz-grid-4' : 'wiz-grid-2'}>
                <TextInput
                    id="cc-name"
                    label="Company name"
                    fieldClassName={jv ? 'wiz-span-2' : undefined}
                    value={state.name}
                    maxLength={120}
                    onChange={(e) =>
                        setState((s) => ({ ...s, name: e.target.value }))
                    }
                    error={errors['company.name']}
                />
                <TextInput
                    id="cc-code"
                    label="Short code"
                    className="wiz-code"
                    value={state.shortCode}
                    maxLength={6}
                    onChange={(e) =>
                        setState((s) => ({
                            ...s,
                            shortCode: e.target.value
                                .toUpperCase()
                                .replace(/[^A-Z]/g, ''),
                        }))
                    }
                    help={
                        jv
                            ? undefined
                            : '2–6 letters, used on avatars and exports.'
                    }
                    error={errors['company.short_code']}
                />
                <AmountInput
                    id="cc-open"
                    label="Opening balance"
                    value={state.openingPaise}
                    onValueChange={(paise) =>
                        setState((s) => ({ ...s, openingPaise: paise }))
                    }
                    help={
                        jv
                            ? undefined
                            : `Cash in the company’s accounts on ${fyStart}. You can change it later in Settings.`
                    }
                    error={errors['company.opening_balance']}
                />
                <Select
                    id="cc-cur"
                    label="Currency"
                    value={state.currency}
                    onChange={(e) =>
                        setState((s) => ({ ...s, currency: e.target.value }))
                    }
                    options={currencies.map((code) => ({
                        value: code,
                        label: CURRENCY_LABELS[code] ?? code,
                    }))}
                    error={errors['company.currency']}
                />
            </div>
        </section>
    );
}

function SinglePartners({ state, setState, errors, ownerEmail }: Props) {
    const total = singleCheck(state);

    return (
        <section
            className="cf-card wiz-card wiz-card--tight"
            aria-labelledby="cc-pt"
        >
            <div className="cf-kv flex-wrap">
                <h2 id="cc-pt" className="cf-dot-title t-title">
                    Partners &amp; equity
                </h2>
                <TotalPill ok={total.ok} label="Total:" total={total.total} />
            </div>
            <div
                className="t-caption t-muted wiz-row wiz-row--single"
                aria-hidden="true"
            >
                <span>Colour</span>
                <span>Name</span>
                <span>Email (to invite)</span>
                <span>Role</span>
                <span className="text-right">Equity</span>
                <span />
            </div>
            {state.partners.map((p, index) => {
                const owner = index === 0;
                const label = p.name || 'New partner';

                return (
                    <div key={p.key} className="wiz-row wiz-row--single">
                        <button
                            type="button"
                            className="cf-iconbtn"
                            title="Change colour"
                            aria-label={`Colour for ${label}: partner colour ${p.colour}. Change colour`}
                            onClick={() =>
                                updatePartner(setState, p.key, {
                                    colour: nextColour(p.colour),
                                })
                            }
                        >
                            <span
                                className="wiz-dot"
                                style={{
                                    background: `var(--partner-${p.colour})`,
                                }}
                            />
                        </button>
                        <input
                            className="cf-input cf-input--sm"
                            type="text"
                            value={p.name}
                            maxLength={120}
                            onChange={(e) =>
                                updatePartner(setState, p.key, {
                                    name: e.target.value,
                                })
                            }
                            aria-label={`Name of partner ${index + 1}`}
                            aria-invalid={
                                errors[`partners.${index}.name`]
                                    ? true
                                    : undefined
                            }
                        />
                        <input
                            className="cf-input cf-input--sm"
                            type="email"
                            value={owner ? ownerEmail : p.email}
                            readOnly={owner}
                            placeholder="Optional"
                            onChange={(e) =>
                                updatePartner(setState, p.key, {
                                    email: e.target.value,
                                })
                            }
                            aria-label={`Email for ${label}`}
                            aria-invalid={
                                errors[`partners.${index}.email`]
                                    ? true
                                    : undefined
                            }
                        />
                        <RowSelect
                            value={owner ? 'admin' : p.role}
                            options={
                                owner ? ROLE_OPTIONS.slice(0, 1) : ROLE_OPTIONS
                            }
                            onChange={(role) =>
                                updatePartner(setState, p.key, { role })
                            }
                            aria-label={`Role for ${label}`}
                        />
                        <PercentInput
                            value={p.equity}
                            invalid={!total.ok}
                            onChange={(equity) =>
                                updatePartner(setState, p.key, { equity })
                            }
                            aria-label={`Equity for ${label}`}
                        />
                        <RemoveRow
                            label={`Remove ${label}`}
                            disabled={owner || state.partners.length < 2}
                            onClick={() =>
                                setState((s) => ({
                                    ...s,
                                    partners: s.partners.filter(
                                        (x) => x.key !== p.key,
                                    ),
                                    members: s.members.map((m) =>
                                        m.partnerKey === p.key
                                            ? {
                                                  ...m,
                                                  partnerKey: s.partners[0].key,
                                              }
                                            : m,
                                    ),
                                }))
                            }
                        />
                    </div>
                );
            })}
            <RowErrors errors={errors} prefix="partners." />
            {!total.ok && total.message && (
                <GroupError>{total.message}</GroupError>
            )}
            <div className="cf-kv flex-wrap">
                <Button
                    variant="secondary"
                    size="sm"
                    icon={Plus}
                    onClick={() => setState((s) => addPartner(s, null))}
                >
                    Add partner
                </Button>
                <TextButton onClick={() => setState((s) => evenPartners(s))}>
                    Even split
                </TextButton>
            </div>
        </section>
    );
}

/** Field errors from the server for rows (email taken, names missing). */
function RowErrors({
    errors,
    prefix,
}: {
    errors: Record<string, string>;
    prefix: string;
}) {
    const messages = [
        ...new Set(
            Object.entries(errors)
                .filter(([field]) => field.startsWith(prefix))
                .map(([, message]) => message),
        ),
    ];

    return messages.map((message) => (
        <GroupError key={message}>{message}</GroupError>
    ));
}

function JvSides({ state, setState, errors }: Props) {
    const total = sidesCheck(state);

    return (
        <section
            className="cf-card wiz-card wiz-card--tight"
            aria-labelledby="jv-sides"
        >
            <div className="cf-kv flex-wrap">
                <h2 id="jv-sides" className="cf-dot-title t-title">
                    Sides
                </h2>
                <TotalPill
                    ok={total.ok}
                    label="Sides total"
                    total={total.total}
                />
            </div>
            <p className="t-body t-muted m-0">
                Each side is a company in the venture. Its share decides how
                profit is split before it reaches that side’s partners.
            </p>
            <div
                className="t-caption t-muted wiz-row wiz-row--sides"
                aria-hidden="true"
            >
                <span>Side</span>
                <span className="text-right">Share of the JV</span>
                <span className="text-right">Partners</span>
                <span />
            </div>
            {state.sides.map((side, index) => {
                const count = state.partners.filter(
                    (p) => p.sideKey === side.key,
                ).length;

                return (
                    <div key={side.key} className="wiz-row wiz-row--sides">
                        <input
                            className="cf-input cf-input--sm"
                            type="text"
                            value={side.name}
                            maxLength={120}
                            onChange={(e) =>
                                setState((s) => ({
                                    ...s,
                                    sides: s.sides.map((x) =>
                                        x.key === side.key
                                            ? { ...x, name: e.target.value }
                                            : x,
                                    ),
                                }))
                            }
                            aria-label={`Name of side ${index + 1}`}
                        />
                        <PercentInput
                            value={side.equity}
                            invalid={!total.ok}
                            onChange={(equity) =>
                                setState((s) => ({
                                    ...s,
                                    sides: s.sides.map((x) =>
                                        x.key === side.key
                                            ? { ...x, equity }
                                            : x,
                                    ),
                                }))
                            }
                            aria-label={`Share of the JV for ${side.name || `side ${index + 1}`}`}
                        />
                        <span className="t-money text-right">{count}</span>
                        <RemoveRow
                            label={`Remove ${side.name || `side ${index + 1}`}`}
                            disabled={state.sides.length <= 2 || count > 0}
                            onClick={() =>
                                setState((s) => ({
                                    ...s,
                                    sides: s.sides.filter(
                                        (x) => x.key !== side.key,
                                    ),
                                }))
                            }
                        />
                    </div>
                );
            })}
            {errors.sides && <GroupError>{errors.sides}</GroupError>}
            {!total.ok && total.message && !errors.sides && (
                <GroupError>{total.message}</GroupError>
            )}
            <div className="cf-kv">
                <Button
                    variant="secondary"
                    size="sm"
                    icon={Plus}
                    onClick={() =>
                        setState((s) => ({
                            ...s,
                            sides: [
                                ...s.sides,
                                { key: newKey('s'), name: '', equity: '0.00' },
                            ],
                        }))
                    }
                >
                    Add side
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setState((s) => evenSides(s))}
                >
                    Even split
                </Button>
            </div>
        </section>
    );
}

function JvSideCard({
    state,
    setState,
    errors,
    ownerEmail,
    sideIndex,
}: Props & { sideIndex: number }) {
    const side = state.sides[sideIndex];
    const result = sideCheck(state, side);
    const sideName = side.name || `Side ${sideIndex + 1}`;
    const sideOptions = state.sides.map((s, i) => ({
        value: s.key,
        label: s.name || `Side ${i + 1}`,
    }));

    return (
        <section
            className="cf-card wiz-card wiz-card--tight"
            aria-label={`Partners in ${sideName}`}
        >
            <div className="cf-kv flex-wrap">
                <h2 className="cf-dot-title t-title">Partners in {sideName}</h2>
                <TotalPill
                    ok={result.ok}
                    label="Within side"
                    total={result.total}
                />
            </div>
            <div
                className="t-caption t-muted wiz-row wiz-row--jv"
                aria-hidden="true"
            >
                <span />
                <span>Partner</span>
                <span>Email (to invite)</span>
                <span>Role</span>
                <span>Side</span>
                <span className="text-right">% within side</span>
                <span />
            </div>
            {state.partners.map((p, index) => {
                if (p.sideKey !== side.key) {
                    return null;
                }

                const owner = index === 0;
                const label = p.name || 'New partner';

                return (
                    <div key={p.key} className="wiz-row wiz-row--jv">
                        <button
                            type="button"
                            className="cf-avatar wiz-avatar"
                            title="Change colour"
                            aria-label={`Colour for ${label}: partner colour ${p.colour}. Change colour`}
                            onClick={() =>
                                updatePartner(setState, p.key, {
                                    colour: nextColour(p.colour),
                                })
                            }
                        >
                            {initialsFrom(p.name || '?')}
                            <span
                                className="cf-avatar-dot"
                                style={{
                                    background: `var(--partner-${p.colour})`,
                                }}
                            />
                        </button>
                        <input
                            className="cf-input cf-input--sm"
                            type="text"
                            value={p.name}
                            maxLength={120}
                            onChange={(e) =>
                                updatePartner(setState, p.key, {
                                    name: e.target.value,
                                })
                            }
                            aria-label="Partner name"
                        />
                        <input
                            className="cf-input cf-input--sm"
                            type="email"
                            value={owner ? ownerEmail : p.email}
                            readOnly={owner}
                            placeholder="Optional"
                            onChange={(e) =>
                                updatePartner(setState, p.key, {
                                    email: e.target.value,
                                })
                            }
                            aria-label={`Email for ${label}`}
                        />
                        <RowSelect
                            value={owner ? 'admin' : p.role}
                            options={
                                owner ? ROLE_OPTIONS.slice(0, 1) : ROLE_OPTIONS
                            }
                            onChange={(role) =>
                                updatePartner(setState, p.key, { role })
                            }
                            aria-label={`Role for ${label}`}
                        />
                        <RowSelect
                            value={p.sideKey ?? ''}
                            options={sideOptions}
                            onChange={(sideKey) =>
                                updatePartner(setState, p.key, { sideKey })
                            }
                            aria-label={`Side for ${label}`}
                        />
                        <PercentInput
                            value={p.equity}
                            invalid={!result.ok}
                            onChange={(equity) =>
                                updatePartner(setState, p.key, { equity })
                            }
                            aria-label={`Share of ${sideName} for ${label}`}
                        />
                        <RemoveRow
                            label={`Remove ${label}`}
                            disabled={owner}
                            onClick={() =>
                                setState((s) => ({
                                    ...s,
                                    partners: s.partners.filter(
                                        (x) => x.key !== p.key,
                                    ),
                                    members: s.members.map((m) =>
                                        m.partnerKey === p.key
                                            ? {
                                                  ...m,
                                                  partnerKey: s.partners[0].key,
                                              }
                                            : m,
                                    ),
                                }))
                            }
                        />
                    </div>
                );
            })}
            {(errors[`sides.${sideIndex}`] ??
                (!result.ok && result.message)) && (
                <GroupError>
                    {errors[`sides.${sideIndex}`] ?? result.message}
                </GroupError>
            )}
            <div className="cf-kv">
                <Button
                    variant="secondary"
                    size="sm"
                    icon={Plus}
                    onClick={() => setState((s) => addPartner(s, side.key))}
                >
                    Add partner to {sideName}
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setState((s) => evenPartners(s, side.key))}
                >
                    Even split
                </Button>
            </div>
        </section>
    );
}

function SingleAside({ state }: { state: WizardState }) {
    const label = state.partners
        .map(
            (p) =>
                `${p.name || 'New partner'} ${formatEquity(parseEquity(p.equity) ?? 0)}%`,
        )
        .join(', ');

    return (
        <aside className="cf-card wiz-aside" aria-label="Equity split">
            <h2 className="cf-dot-title t-title">Equity split</h2>
            <EquityBar
                label={label}
                segments={state.partners.map((p) => ({
                    key: p.key,
                    bps: parseEquity(p.equity) ?? 0,
                    colour: p.colour,
                }))}
            />
            <ul className="wiz-list">
                {state.partners.map((p) => (
                    <li key={p.key} className="cf-kv t-body">
                        <span className="cf-row">
                            <span
                                className="cf-swatch"
                                style={{
                                    background: `var(--partner-${p.colour})`,
                                }}
                            />
                            {p.name || 'New partner'}{' '}
                            <span className="t-muted">
                                · {p.role === 'admin' ? 'Admin' : 'Partner'}
                            </span>
                        </span>
                        <span className="t-money-sm">
                            {formatEquity(parseEquity(p.equity) ?? 0)}%
                        </span>
                    </li>
                ))}
            </ul>
            <div className="cf-divider" />
            <p className="t-body t-muted m-0">
                Equity sets each partner’s share of profit, the capital-account
                split and who owes whom at settlement. Admins can also change
                billing and settings.
            </p>
        </aside>
    );
}

function JvAside({ state }: { state: WizardState }) {
    const sides = sidesCheck(state);
    const rows = state.partners.map((p) => ({
        partner: p,
        side: state.sides.find((s) => s.key === p.sideKey)?.name ?? '',
        bps: overallBps(state, p),
    }));
    const checks = [
        { ok: sides.ok, text: `Sides total ${formatEquity(sides.total)}%` },
        ...state.sides.map((s, i) => {
            const result = sideCheck(state, s);

            return {
                ok: result.ok,
                text: `${s.name || `Side ${i + 1}`} partners total ${formatEquity(result.total)}%`,
            };
        }),
    ];

    return (
        <aside className="cf-card wiz-aside" aria-label="Overall equity">
            <h2 className="cf-dot-title t-title">Overall equity</h2>
            <EquityBar
                label={rows
                    .map(
                        (r) =>
                            `${r.partner.name || 'New partner'} ${formatEquityShort(r.bps)}`,
                    )
                    .join(', ')}
                segments={rows.map((r) => ({
                    key: r.partner.key,
                    bps: r.bps,
                    colour: r.partner.colour,
                }))}
            />
            <ul className="wiz-list">
                {rows.map((r) => (
                    <li key={r.partner.key} className="cf-kv t-body">
                        <span className="cf-row">
                            <span
                                className="cf-swatch"
                                style={{
                                    background: `var(--partner-${r.partner.colour})`,
                                }}
                            />
                            {r.partner.name || 'New partner'}{' '}
                            <span className="t-muted">· {r.side}</span>
                        </span>
                        <span className="t-money-sm">
                            {formatEquity(r.bps)}%
                        </span>
                    </li>
                ))}
            </ul>
            <div className="cf-divider" />
            <ul className="t-body wiz-list">
                {checks.map((c) => (
                    <li
                        key={c.text}
                        className="cf-row"
                        style={{
                            color: c.ok ? 'var(--positive)' : 'var(--negative)',
                        }}
                    >
                        <Icon
                            icon={c.ok ? Check : AlertTriangle}
                            size={16}
                            strokeWidth={2}
                        />
                        {c.text}
                    </li>
                ))}
            </ul>
            <p className="t-caption t-muted m-0">
                Overall equity = side share × % within side. Settlements compare
                what each side holds with what it’s entitled to.
            </p>
        </aside>
    );
}

/** Step 1: Company & partners (CreateCompanySingle / CreateCompanyJV). */
export function StepCompany(props: Props) {
    const jv = props.state.type === 'jv';

    return (
        <div className="wiz-body">
            <div className="wiz-form">
                <CompanyCard {...props} />
                {jv ? (
                    <>
                        <JvSides {...props} />
                        {props.state.sides.map((side, index) => (
                            <JvSideCard
                                key={side.key}
                                {...props}
                                sideIndex={index}
                            />
                        ))}
                    </>
                ) : (
                    <SinglePartners {...props} />
                )}
            </div>
            {jv ? (
                <JvAside state={props.state} />
            ) : (
                <SingleAside state={props.state} />
            )}
        </div>
    );
}
