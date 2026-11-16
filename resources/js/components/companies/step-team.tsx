import { Plus } from 'lucide-react';
import type { Dispatch, SetStateAction } from 'react';
import {
    GroupError,
    RemoveRow,
    RowSelect,
} from '@/components/companies/wizard-parts';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { Money } from '@/components/ui/money';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Toggle } from '@/components/ui/toggle';
import { fixedMonthlyPaise, newKey } from '@/lib/company-wizard';
import type { CostType, MemberRow, WizardState } from '@/lib/company-wizard';

type Props = {
    state: WizardState;
    setState: Dispatch<SetStateAction<WizardState>>;
    errors: Record<string, string>;
};

function updateMember(
    setState: Props['setState'],
    key: string,
    patch: Partial<MemberRow>,
) {
    setState((s) => ({
        ...s,
        members: s.members.map((m) => (m.key === key ? { ...m, ...patch } : m)),
    }));
}

/** Step 2: Team (CreateCompanySteps, "Team"). Optional; rows bill to a partner. */
export function StepTeam({ state, setState, errors }: Props) {
    const partnerOptions = state.partners.map((p) => ({
        value: p.key,
        label: p.name || 'New partner',
    }));
    const hourly = state.members.filter((m) => m.costType === 'hourly').length;
    const memberErrors = [
        ...new Set(
            Object.entries(errors)
                .filter(([f]) => f.startsWith('members'))
                .map(([, m]) => m),
        ),
    ];

    return (
        <div className="wiz-body">
            <section
                className="cf-card wiz-card wiz-card--team"
                aria-labelledby="tm-h"
            >
                <h2 id="tm-h" className="cf-dot-title t-title">
                    Team members
                </h2>
                <p className="t-body t-muted m-0">
                    People who log hours or are paid a salary. Each one bills to
                    a partner, so their cost lands on that partner’s P&amp;L.
                </p>
                <div className="cf-table-wrap">
                    <div className="wiz-team">
                        <div
                            className="t-caption t-muted wiz-row wiz-row--team"
                            aria-hidden="true"
                        >
                            <span>Name</span>
                            <span>Designation</span>
                            <span>Email (to invite)</span>
                            <span>Cost type</span>
                            <span>Rate</span>
                            <span>Partner</span>
                            <span />
                        </div>
                        {state.members.map((m) => {
                            const label = m.name || 'this member';

                            return (
                                <div
                                    key={m.key}
                                    className="wiz-row wiz-row--team"
                                >
                                    <input
                                        className="cf-input cf-input--sm"
                                        type="text"
                                        value={m.name}
                                        maxLength={120}
                                        placeholder="Full name"
                                        onChange={(e) =>
                                            updateMember(setState, m.key, {
                                                name: e.target.value,
                                            })
                                        }
                                        aria-label="Member name"
                                    />
                                    <input
                                        className="cf-input cf-input--sm"
                                        type="text"
                                        value={m.designation}
                                        maxLength={120}
                                        placeholder="e.g. Developer"
                                        onChange={(e) =>
                                            updateMember(setState, m.key, {
                                                designation: e.target.value,
                                            })
                                        }
                                        aria-label="Designation"
                                    />
                                    <input
                                        className="cf-input cf-input--sm"
                                        type="email"
                                        value={m.email}
                                        placeholder="Optional"
                                        onChange={(e) =>
                                            updateMember(setState, m.key, {
                                                email: e.target.value,
                                            })
                                        }
                                        aria-label={`Email for ${label}`}
                                    />
                                    <SegmentedControl<CostType>
                                        size="sm"
                                        aria-label={`Cost type for ${label}`}
                                        value={m.costType}
                                        options={[
                                            {
                                                value: 'hourly',
                                                label: 'Hourly',
                                            },
                                            {
                                                value: 'fixed_monthly',
                                                label: 'Fixed monthly',
                                            },
                                        ]}
                                        onValueChange={(costType) =>
                                            updateMember(setState, m.key, {
                                                costType,
                                            })
                                        }
                                    />
                                    <span className="wiz-rate">
                                        <AmountInput
                                            size="sm"
                                            align="right"
                                            wrapClassName="min-w-0 flex-1"
                                            aria-label={`${m.costType === 'hourly' ? 'Hourly rate' : 'Monthly cost'} for ${label}`}
                                            value={m.ratePaise}
                                            onValueChange={(ratePaise) =>
                                                updateMember(setState, m.key, {
                                                    ratePaise,
                                                })
                                            }
                                        />
                                        <span className="t-caption t-muted wiz-per">
                                            {m.costType === 'hourly'
                                                ? '/ hour'
                                                : '/ month'}
                                        </span>
                                    </span>
                                    <RowSelect
                                        value={m.partnerKey}
                                        options={partnerOptions}
                                        onChange={(partnerKey) =>
                                            updateMember(setState, m.key, {
                                                partnerKey,
                                            })
                                        }
                                        aria-label="Bills to partner"
                                    />
                                    <RemoveRow
                                        label={`Remove ${label}`}
                                        onClick={() =>
                                            setState((s) => ({
                                                ...s,
                                                members: s.members.filter(
                                                    (x) => x.key !== m.key,
                                                ),
                                            }))
                                        }
                                    />
                                </div>
                            );
                        })}
                    </div>
                </div>
                {memberErrors.map((message) => (
                    <GroupError key={message}>{message}</GroupError>
                ))}
                <div className="cf-kv flex-wrap">
                    <Button
                        variant="secondary"
                        size="sm"
                        icon={Plus}
                        onClick={() =>
                            setState((s) => ({
                                ...s,
                                members: [
                                    ...s.members,
                                    {
                                        key: newKey('m'),
                                        name: '',
                                        designation: '',
                                        email: '',
                                        costType: 'hourly',
                                        ratePaise: 0,
                                        partnerKey: s.partners[0].key,
                                    },
                                ],
                            }))
                        }
                    >
                        Add member
                    </Button>
                    <span className="t-caption t-muted">
                        You can add more later from Team, or import a CSV.
                    </span>
                </div>
            </section>
            <aside
                className="cf-card wiz-aside wiz-aside--team"
                aria-label="Team cost"
            >
                <h2 className="cf-dot-title t-title">Team cost</h2>
                <div className="cf-kv t-body">
                    <span className="t-muted">Fixed monthly</span>
                    <span className="t-money">
                        <Money paise={fixedMonthlyPaise(state)} /> / month
                    </span>
                </div>
                <div className="cf-kv t-body">
                    <span className="t-muted">Hourly rates</span>
                    <span className="t-money">
                        {hourly === 0
                            ? 'None'
                            : `${hourly} ${hourly === 1 ? 'member' : 'members'}, billed as logged`}
                    </span>
                </div>
                <div className="cf-divider" />
                <Toggle
                    checked={state.inviteMembers}
                    onCheckedChange={(inviteMembers) =>
                        setState((s) => ({ ...s, inviteMembers }))
                    }
                >
                    Email each member an invite when I launch
                </Toggle>
                <p className="t-caption t-muted m-0">
                    Members can log hours and add expenses. Partners approve
                    them. Only members with an email get an invite.
                </p>
            </aside>
        </div>
    );
}
