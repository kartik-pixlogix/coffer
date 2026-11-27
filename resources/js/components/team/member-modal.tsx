import { router } from '@inertiajs/react';
import { ArrowRight, UserPlus, UserRoundPen } from 'lucide-react';
import { useState } from 'react';
import { AmountInput } from '@/components/ui/amount-input';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { DatePicker } from '@/components/ui/date-picker';
import { FieldError } from '@/components/ui/field';
import { Modal } from '@/components/ui/modal';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { Select } from '@/components/ui/select';
import { TextInput } from '@/components/ui/text-input';
import { formatDate, toIsoDate } from '@/lib/format';
import { store as addMember, update as updateMember } from '@/routes/team';
import type {
    CostType,
    PartnerOption,
    TeamMember,
    UserOption,
} from '@/types/team';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Null: "Add member". */
    member: TeamMember | null;
    partners: PartnerOption[];
    users: UserOption[];
    companyName: string;
    workspaceName: string;
    /** "Edit rates" (spec §2.2): without it the rate is read-only when editing. */
    canEditRates: boolean;
};

type Draft = {
    name: string;
    designation: string;
    costType: CostType;
    ratePaise: number | null;
    partnerId: string;
    userId: string | null;
    effectiveFrom: string | null;
    tdsSection: string;
    tdsMonthly: number | null;
};

/** TDS on a member's pay (D161), as in App\Enums\TdsSection. [VERIFY] with the CA. */
const TDS_OPTIONS = [
    { value: '', label: 'No TDS' },
    { value: '192', label: '192 · Salary (a monthly amount)' },
    { value: '194C_individual', label: '194C · Contractor (individual), 1%' },
    { value: '194J_professional', label: '194J · Professional fees, 10%' },
    { value: '194J_technical', label: '194J · Technical services, 2%' },
];

const COST = {
    hourly: {
        label: 'Hourly rate',
        suffix: '/ hour',
        help: 'Earnings = billable hours × rate, each month.',
    },
    fixed_monthly: {
        label: 'Monthly cost',
        suffix: '/ month',
        help: 'Charged as salary on the last day of each month.',
    },
} as const;

const start = (
    member: TeamMember | null,
    partners: PartnerOption[],
): Draft => ({
    name: member?.name ?? '',
    designation: member?.designation ?? '',
    costType: member?.costType ?? 'hourly',
    ratePaise: member?.ratePaise ?? null,
    partnerId: String(member?.partnerId ?? partners[0]?.id ?? ''),
    userId: member?.userId ? String(member.userId) : null,
    // Today, so a rate change can be saved straight away (A2); only sent when it changes.
    effectiveFrom: member ? toIsoDate(new Date()) : null,
    tdsSection: member?.tdsSection ?? '',
    tdsMonthly: member?.tdsMonthlyPaise ?? null,
});

/**
 * "Add member" (Team.dc.html modal, spec §7), also used to edit. The rate field's label,
 * suffix and help follow the cost type. Changing the rate of an existing member asks
 * when it takes effect, so past salaries stay as they were (D37).
 */
export function MemberModal({
    open,
    onOpenChange,
    member,
    partners,
    users,
    companyName,
    workspaceName,
    canEditRates,
}: Props) {
    const [draft, setDraft] = useState<Draft>(() => start(member, partners));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [shownFor, setShownFor] = useState<string | null>(null);

    // Each opening starts from the member's saved values.
    const key = open ? String(member?.id ?? 'new') : null;

    if (key !== shownFor) {
        setShownFor(key);
        setDraft(start(member, partners));
        setErrors({});
    }

    const set = (patch: Partial<Draft>) =>
        setDraft((d) => ({ ...d, ...patch }));
    const cost = COST[draft.costType];
    const rateChanged =
        member !== null &&
        (draft.costType !== member.costType ||
            draft.ratePaise !== member.ratePaise);
    const rateLocked = member !== null && !canEditRates;
    // Why Save is off, said next to it (A2).
    const blocker =
        draft.name.trim() === ''
            ? 'Add a name to save'
            : (draft.ratePaise ?? 0) <= 0
              ? `Enter the ${cost.label.toLowerCase()} to save`
              : draft.partnerId === ''
                ? 'Pick the partner they bill to'
                : rateChanged && draft.effectiveFrom === null
                  ? 'Pick when the new rate starts'
                  : null;
    const canSave = blocker === null;

    const save = () => {
        const payload = {
            name: draft.name,
            designation: draft.designation.trim() || null,
            cost_type: draft.costType,
            rate_paise: draft.ratePaise,
            partner_id: Number(draft.partnerId),
            user_id: draft.userId ? Number(draft.userId) : null,
            effective_from: rateChanged ? draft.effectiveFrom : null,
            tds_section: draft.tdsSection || null,
            tds_monthly_paise:
                draft.tdsSection === '192' ? (draft.tdsMonthly ?? 0) : 0,
        };
        const options = {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => onOpenChange(false),
            onError: (bag: Record<string, string>) => setErrors(bag),
        };

        if (member) {
            router.put(updateMember(member.id).url, payload, options);
        } else {
            router.post(addMember().url, payload, options);
        }
    };

    return (
        <Modal
            open={open}
            onOpenChange={onOpenChange}
            title={member ? 'Edit member' : 'Add member'}
            subtitle={`${companyName} · their cost lands on the partner they bill to`}
            icon={member ? UserRoundPen : UserPlus}
            dirty
            footer={
                <>
                    {blocker && (
                        <span className="t-caption t-muted mr-auto self-center">
                            {blocker}
                        </span>
                    )}
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
                        disc={ArrowRight}
                        loading={saving}
                        disabled={!canSave}
                        onClick={save}
                    >
                        {member ? 'Save member' : 'Add member'}
                    </Button>
                </>
            }
        >
            <div className="wiz-grid-2">
                <TextInput
                    id="am-n"
                    label="Name"
                    placeholder="Priya Menon"
                    value={draft.name}
                    maxLength={120}
                    onChange={(e) => set({ name: e.target.value })}
                    error={errors.name}
                />
                <TextInput
                    id="am-d"
                    label="Designation"
                    placeholder="Frontend developer"
                    value={draft.designation}
                    maxLength={120}
                    onChange={(e) => set({ designation: e.target.value })}
                    error={errors.designation}
                />
            </div>
            <div className="cf-field">
                <span className="cf-label" id="am-ct">
                    Cost type
                </span>
                <SegmentedControl<CostType>
                    aria-labelledby="am-ct"
                    value={draft.costType}
                    disabled={rateLocked}
                    options={[
                        { value: 'hourly', label: 'Hourly' },
                        { value: 'fixed_monthly', label: 'Fixed monthly' },
                    ]}
                    onValueChange={(costType) =>
                        set({
                            costType,
                            // Back to the saved rate when switching back, else start empty.
                            ratePaise:
                                member?.costType === costType
                                    ? member.ratePaise
                                    : null,
                        })
                    }
                />
            </div>
            <div className="wiz-grid-2">
                <AmountInput
                    id="am-r"
                    label={cost.label}
                    suffix={cost.suffix}
                    placeholder={
                        draft.costType === 'hourly' ? '1,200' : '80,000'
                    }
                    value={draft.ratePaise}
                    onValueChange={(ratePaise) => set({ ratePaise })}
                    readOnly={rateLocked}
                    help={
                        rateLocked
                            ? 'Changing a rate needs the Edit rates permission.'
                            : cost.help
                    }
                    error={errors.rate_paise}
                />
                <Select
                    id="am-p"
                    label="Assigned partner"
                    value={draft.partnerId}
                    onChange={(e) => set({ partnerId: e.target.value })}
                    options={partners.map((p) => ({
                        value: String(p.id),
                        label: p.name,
                    }))}
                    error={errors.partner_id}
                />
            </div>
            <div className="wiz-grid-2">
                <Select
                    id="am-tds"
                    label="TDS on their pay"
                    value={draft.tdsSection}
                    onChange={(e) => set({ tdsSection: e.target.value })}
                    options={TDS_OPTIONS}
                    help="Held back from each salary run and owed to the government."
                    error={errors.tds_section}
                />
                {draft.tdsSection === '192' && (
                    <AmountInput
                        id="am-tdsm"
                        label="TDS each month"
                        value={draft.tdsMonthly}
                        onValueChange={(tdsMonthly) => set({ tdsMonthly })}
                        help="From their tax regime and declarations, as worked out with your CA."
                        error={errors.tds_monthly_paise}
                    />
                )}
            </div>
            {rateChanged && (
                <DatePicker
                    id="am-f"
                    label="New rate starts"
                    value={draft.effectiveFrom}
                    onValueChange={(effectiveFrom) => set({ effectiveFrom })}
                    placeholder={formatDate(toIsoDate(new Date()))}
                    help="Salaries already run keep the old rate."
                    error={errors.effective_from}
                />
            )}
            <Combobox
                id="am-u"
                label="Linked user account"
                listLabel="People"
                noun="person"
                searchIcon
                placeholder={`Search people in ${workspaceName}`}
                value={draft.userId}
                onValueChange={(value) => set({ userId: value })}
                options={users.map((u) => ({
                    value: String(u.id),
                    label: u.name,
                    meta: u.email,
                    leading: <Avatar name={u.name} size="sm" />,
                }))}
                help="Optional. Linking lets them log their own hours and expenses. No account yet? Invite them from Settings → Members."
                error={errors.user_id}
            />
            {errors.member && <FieldError>{errors.member}</FieldError>}
        </Modal>
    );
}
