/*
 * State and live checks for the create-company wizard (spec §3.1,
 * CreateCompanySingle / CreateCompanyJV / CreateCompanySteps). Pure functions so they
 * can be unit tested; the server repeats every check (CompanyWizardRequest).
 */
import {
    WHOLE,
    evenSplit,
    formatEquity,
    gapMessage,
    isWhole,
    parseEquity,
    totalEquity,
} from '@/lib/equity';
import type { CompanyType } from '@/types';

export type PartnerRole = 'admin' | 'partner';
export type CostType = 'hourly' | 'fixed_monthly';

export type SideRow = { key: string; name: string; equity: string };

export type PartnerRow = {
    key: string;
    name: string;
    email: string;
    role: PartnerRole;
    /** 1–6 → var(--partner-N) */
    colour: number;
    /** Share of the company (Single) or of the side (JV), as typed: "60.00". */
    equity: string;
    sideKey: string | null;
};

export type MemberRow = {
    key: string;
    name: string;
    designation: string;
    email: string;
    costType: CostType;
    /** Integer paise per hour or per month. */
    ratePaise: number | null;
    partnerKey: string;
};

export type WizardState = {
    type: CompanyType;
    name: string;
    shortCode: string;
    openingPaise: number | null;
    currency: string;
    sides: SideRow[];
    /** The first row is the creating user, as Admin; it can't be removed. */
    partners: PartnerRow[];
    members: MemberRow[];
    inviteMembers: boolean;
};

export const PARTNER_COLOURS = 6;

let counter = 0;

/** A key for a new row (stable across renders, unique within the wizard). */
export function newKey(prefix: string): string {
    counter += 1;

    return `${prefix}${Date.now().toString(36)}${counter}`;
}

/** "VL" from "Vertex Labs Pvt Ltd", "SPJV" from "Saarthi Portal JV". */
export function suggestShortCode(name: string): string {
    const words = name
        .toUpperCase()
        .replace(/[^A-Z\s]/g, ' ')
        .split(/\s+/)
        .filter(Boolean)
        .filter((w) => !['PVT', 'LTD', 'LLP', 'INC', 'THE', 'AND'].includes(w));

    if (words.length === 0) {
        return '';
    }

    const code =
        words.length === 1
            ? words[0].slice(0, 4)
            : words
                  .slice(0, 4)
                  .map((w) => (w.length <= 2 ? w : w[0]))
                  .join('');

    return code.slice(0, 6);
}

export function initialState(owner: { name: string }, name = ''): WizardState {
    return {
        type: 'single',
        name,
        shortCode: suggestShortCode(name),
        openingPaise: 0,
        currency: 'INR',
        sides: [],
        partners: [
            {
                key: 'owner',
                name: owner.name,
                email: '',
                role: 'admin',
                colour: 1,
                equity: '100.00',
                sideKey: null,
            },
        ],
        members: [],
        inviteMembers: true,
    };
}

/** Switching to a JV: two sides at 50%, every partner on the first side. */
export function toJv(state: WizardState): WizardState {
    const sides: SideRow[] =
        state.sides.length >= 2
            ? state.sides
            : [
                  {
                      key: newKey('s'),
                      name: state.name || 'Side one',
                      equity: '50.00',
                  },
                  { key: newKey('s'), name: 'Side two', equity: '50.00' },
              ];

    return {
        ...state,
        type: 'jv',
        sides,
        partners: state.partners.map((p) => ({
            ...p,
            sideKey: sides.some((s) => s.key === p.sideKey)
                ? p.sideKey
                : sides[0].key,
        })),
    };
}

export function toSingle(state: WizardState): WizardState {
    return {
        ...state,
        type: 'single',
        partners: state.partners.map((p) => ({ ...p, sideKey: null })),
    };
}

/** Next colour in the cycle partner-1…6 (click to cycle). */
export const nextColour = (colour: number): number =>
    (colour % PARTNER_COLOURS) + 1;

export function addPartner(
    state: WizardState,
    sideKey: string | null,
): WizardState {
    return {
        ...state,
        partners: [
            ...state.partners,
            {
                key: newKey('p'),
                name: '',
                email: '',
                role: 'partner',
                colour: (state.partners.length % PARTNER_COLOURS) + 1,
                equity: '0.00',
                sideKey,
            },
        ],
    };
}

/** Even split across `keys` (all partners, one side's partners, or the sides). */
function splitEvenly<T extends { key: string; equity: string }>(
    rows: T[],
    keys: string[],
): T[] {
    const shares = evenSplit(keys.length);

    return rows.map((row) => {
        const index = keys.indexOf(row.key);

        return index === -1
            ? row
            : { ...row, equity: formatEquity(shares[index]) };
    });
}

export function evenPartners(
    state: WizardState,
    sideKey: string | null = null,
): WizardState {
    const keys = state.partners
        .filter((p) => sideKey === null || p.sideKey === sideKey)
        .map((p) => p.key);

    return { ...state, partners: splitEvenly(state.partners, keys) };
}

export function evenSides(state: WizardState): WizardState {
    return {
        ...state,
        sides: splitEvenly(
            state.sides,
            state.sides.map((s) => s.key),
        ),
    };
}

export type EquityCheck = {
    ok: boolean;
    total: number;
    /** "Equity adds up to 95.00%. Add 5.00% so it totals 100%." */
    message: string | null;
};

const check = (
    values: string[],
    subject?: string,
    target?: string,
): EquityCheck => {
    const total = totalEquity(values);
    const ok = isWhole(values);

    return {
        ok,
        total,
        message: ok ? null : gapMessage(total, subject, target),
    };
};

export function singleCheck(state: WizardState): EquityCheck {
    return check(state.partners.map((p) => p.equity));
}

export function sidesCheck(state: WizardState): EquityCheck {
    return check(
        state.sides.map((s) => s.equity),
        'Sides',
        'they total 100%',
    );
}

export function sideCheck(state: WizardState, side: SideRow): EquityCheck {
    const values = state.partners
        .filter((p) => p.sideKey === side.key)
        .map((p) => p.equity);

    return values.length === 0
        ? {
              ok: false,
              total: 0,
              message: `Add a partner to ${side.name || 'this side'}.`,
          }
        : check(
              values,
              `${side.name || 'This side'} partners`,
              'this side totals 100%',
          );
}

const SHORT_CODE = /^[A-Z]{2,6}$/;

/** Everything step 1 needs before "Next: team" (spec §3.1). */
export function stepOneValid(state: WizardState): boolean {
    const basics =
        state.name.trim() !== '' &&
        SHORT_CODE.test(state.shortCode) &&
        state.openingPaise !== null &&
        state.partners.every((p) => p.name.trim() !== '');

    if (!basics) {
        return false;
    }

    if (state.type === 'single') {
        return singleCheck(state).ok;
    }

    return (
        sidesCheck(state).ok &&
        state.sides.every((s) => s.name.trim() !== '' && sideCheck(state, s).ok)
    );
}

export function stepTwoValid(state: WizardState): boolean {
    return state.members.every(
        (m) =>
            m.name.trim() !== '' &&
            m.ratePaise !== null &&
            state.partners.some((p) => p.key === m.partnerKey),
    );
}

/** JV overall equity per partner in bps (may be fractional); Single = own share. */
export function overallBps(state: WizardState, partner: PartnerRow): number {
    const own = parseEquity(partner.equity) ?? 0;

    if (state.type === 'single') {
        return own;
    }

    const side = state.sides.find((s) => s.key === partner.sideKey);

    return ((parseEquity(side?.equity ?? '') ?? 0) * own) / WHOLE;
}

/** Fixed monthly total in paise (Team cost aside). */
export function fixedMonthlyPaise(state: WizardState): number {
    return state.members
        .filter((m) => m.costType === 'fixed_monthly')
        .reduce((sum, m) => sum + (m.ratePaise ?? 0), 0);
}

const rupees = (paise: number | null): string =>
    paise === null
        ? ''
        : `${Math.floor(paise / 100)}.${String(paise % 100).padStart(2, '0')}`;

/** The body the server expects (CompanyWizardRequest). */
export function toPayload(state: WizardState, step?: 1 | 2) {
    return {
        step,
        company: {
            type: state.type,
            name: state.name.trim(),
            short_code: state.shortCode,
            opening_balance: rupees(state.openingPaise),
            currency: state.currency,
        },
        sides:
            state.type === 'jv'
                ? state.sides.map((s) => ({
                      key: s.key,
                      name: s.name.trim(),
                      equity: s.equity,
                  }))
                : [],
        partners: state.partners.map((p) => ({
            key: p.key,
            name: p.name.trim(),
            email: p.email.trim() || null,
            role: p.role,
            colour: p.colour,
            equity: p.equity,
            side_key: state.type === 'jv' ? p.sideKey : null,
        })),
        members: state.members.map((m) => ({
            name: m.name.trim(),
            designation: m.designation.trim() || null,
            email: m.email.trim() || null,
            cost_type: m.costType,
            rate: rupees(m.ratePaise),
            partner_key: m.partnerKey,
        })),
        invite_members: state.inviteMembers,
    };
}

/** Who gets an invite at launch (D23): partners and, with the switch on, members with an email. */
export function inviteCounts(state: WizardState): {
    partners: number;
    members: number;
} {
    return {
        partners: state.partners.slice(1).filter((p) => p.email.trim() !== '')
            .length,
        members: state.inviteMembers
            ? state.members.filter((m) => m.email.trim() !== '').length
            : 0,
    };
}

/** The wizard step a server error belongs to, so Launch can jump back to it. */
export function stepForError(field: string): 1 | 2 {
    return field.startsWith('members') || field === 'invite_members' ? 2 : 1;
}
