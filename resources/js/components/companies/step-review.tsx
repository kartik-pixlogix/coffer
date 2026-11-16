import { Check, Lock, Mail } from 'lucide-react';
import { initialsFrom } from '@/components/ui/avatar';
import { TextButton } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Money } from '@/components/ui/money';
import { inviteCounts, overallBps } from '@/lib/company-wizard';
import type { WizardState } from '@/lib/company-wizard';
import { formatEquity, formatEquityShort, parseEquity } from '@/lib/equity';
import { fiscalYearLabel, formatPaiseDigits } from '@/lib/format';

const SYMBOLS: Record<string, string> = { INR: '₹', USD: '$', AED: 'AED' };

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** Step 3: Review & launch (CreateCompanySteps, "Review & launch"). */
export function StepReview({
    state,
    initials,
    onEdit,
}: {
    state: WizardState;
    initials: string;
    onEdit: (step: 1 | 2) => void;
}) {
    const jv = state.type === 'jv';
    const invites = inviteCounts(state);
    const partnerByKey = new Map(state.partners.map((p) => [p.key, p]));

    const inviteLine =
        invites.partners + invites.members === 0
            ? 'No invites go out yet. Invite people later from Settings › Members & invites.'
            : `Invites go to ${[
                  invites.partners > 0
                      ? plural(invites.partners, 'partner', 'partners')
                      : null,
                  invites.members > 0
                      ? plural(invites.members, 'team member', 'team members')
                      : null,
              ]
                  .filter(Boolean)
                  .join(
                      ' and ',
                  )}${!state.inviteMembers && state.members.length > 0 ? '; team members are added without an invite' : ''}`;

    const partnerLine = (key: string) => {
        const p = partnerByKey.get(key);

        if (!p) {
            return null;
        }

        return (
            <div key={p.key} className="cf-kv t-body">
                <span className="cf-row">
                    <span
                        className="cf-swatch"
                        style={{ background: `var(--partner-${p.colour})` }}
                    />
                    {p.name}
                    {p.role === 'admin' && (
                        <span className="cf-pill cf-pill--sm cf-pill--graphite">
                            Admin
                        </span>
                    )}
                </span>
                <span className="t-money-sm t-muted">
                    {jv
                        ? `${formatEquityShort(parseEquity(p.equity) ?? 0)} → ${formatEquity(overallBps(state, p))}%`
                        : `${formatEquity(parseEquity(p.equity) ?? 0)}%`}
                </span>
            </div>
        );
    };

    return (
        <div className="wiz-review">
            <section
                className="cf-card wiz-card wiz-card--review"
                aria-labelledby="rv-co"
            >
                <div className="cf-kv">
                    <h2 id="rv-co" className="cf-dot-title t-title">
                        Company
                    </h2>
                    <TextButton className="wiz-edit" onClick={() => onEdit(1)}>
                        Edit
                    </TextButton>
                </div>
                <div className="cf-row gap-3">
                    <span className="cf-switcher-ini">{initials}</span>
                    <span className="cf-stack">
                        <span className="t-label-strong">{state.name}</span>
                        <span className="t-caption t-muted">
                            {jv ? 'Joint venture' : 'Single company'} ·{' '}
                            {state.shortCode}
                        </span>
                    </span>
                </div>
                <div className="cf-kv t-body">
                    <span className="t-muted">Opening balance</span>
                    <span className="t-money">
                        {state.currency === 'INR' ? (
                            <Money paise={state.openingPaise ?? 0} />
                        ) : (
                            `${SYMBOLS[state.currency] ?? state.currency} ${formatPaiseDigits(state.openingPaise ?? 0)}`
                        )}
                    </span>
                </div>
                <div className="cf-kv t-body">
                    <span className="t-muted">Currency</span>
                    <span>
                        {state.currency} (
                        {SYMBOLS[state.currency] ?? state.currency})
                    </span>
                </div>
                <div className="cf-kv t-body">
                    <span className="t-muted">Financial year</span>
                    <span>April – March · {fiscalYearLabel(new Date())}</span>
                </div>
            </section>

            <section
                className="cf-card wiz-card wiz-card--review"
                aria-labelledby="rv-eq"
            >
                <div className="cf-kv">
                    <h2 id="rv-eq" className="cf-dot-title t-title">
                        {jv ? 'Sides & partners' : 'Partners'}
                    </h2>
                    <TextButton className="wiz-edit" onClick={() => onEdit(1)}>
                        Edit
                    </TextButton>
                </div>
                {jv
                    ? state.sides.map((side, index) => (
                          <div key={side.key} className="contents">
                              <div
                                  className={`cf-kv t-body font-medium ${index > 0 ? 'pt-1' : ''}`}
                              >
                                  <span>{side.name}</span>
                                  <span className="t-money-sm">
                                      {formatEquity(
                                          parseEquity(side.equity) ?? 0,
                                      )}
                                      %
                                  </span>
                              </div>
                              {state.partners
                                  .filter((p) => p.sideKey === side.key)
                                  .map((p) => partnerLine(p.key))}
                          </div>
                      ))
                    : state.partners.map((p) => partnerLine(p.key))}
            </section>

            <section
                className="cf-card wiz-card wiz-card--review"
                aria-labelledby="rv-tm"
            >
                <div className="cf-kv">
                    <h2 id="rv-tm" className="cf-dot-title t-title">
                        Team
                    </h2>
                    <TextButton className="wiz-edit" onClick={() => onEdit(2)}>
                        Edit
                    </TextButton>
                </div>
                {state.members.length === 0 && (
                    <p className="t-body t-muted m-0">
                        No team members yet. Add them later from Team.
                    </p>
                )}
                {state.members.map((m) => {
                    const partner = partnerByKey.get(m.partnerKey);

                    return (
                        <div key={m.key} className="cf-kv t-body">
                            <span className="cf-row">
                                <span className="cf-avatar cf-avatar--sm">
                                    {initialsFrom(m.name)}
                                    <span
                                        className="cf-avatar-dot"
                                        style={{
                                            background: `var(--partner-${partner?.colour ?? 1})`,
                                        }}
                                    />
                                </span>
                                <span className="cf-stack">
                                    <span>{m.name}</span>
                                    <span className="t-caption t-muted">
                                        {m.designation
                                            ? `${m.designation} · `
                                            : ''}
                                        bills to {partner?.name ?? '—'}
                                    </span>
                                </span>
                            </span>
                            <span className="t-money-sm">
                                ₹{formatPaiseDigits(m.ratePaise ?? 0)}
                                {m.costType === 'hourly'
                                    ? ' / hour'
                                    : ' / month'}
                            </span>
                        </div>
                    );
                })}
            </section>

            <section
                className="cf-card wiz-card wiz-card--review"
                aria-labelledby="rv-go"
            >
                <h2 id="rv-go" className="cf-dot-title t-title">
                    When you launch
                </h2>
                <ul className="t-body wiz-list">
                    <li className="cf-row items-start">
                        <Icon
                            icon={Check}
                            size={16}
                            strokeWidth={2}
                            className="invite-yes"
                        />
                        {jv
                            ? 'Equity checks pass: sides 100%, each side 100%'
                            : 'Equity checks pass: partners total 100%'}
                    </li>
                    <li className="cf-row items-start">
                        <Icon icon={Mail} size={16} />
                        {inviteLine}
                    </li>
                    <li className="cf-row items-start">
                        <Icon icon={Lock} size={16} />
                        An empty credentials vault is created for the company
                    </li>
                </ul>
            </section>
        </div>
    );
}
