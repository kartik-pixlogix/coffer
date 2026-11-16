import { describe, expect, it } from 'vite-plus/test';
import {
    addPartner,
    evenPartners,
    evenSides,
    fixedMonthlyPaise,
    initialState,
    inviteCounts,
    nextColour,
    overallBps,
    sideCheck,
    sidesCheck,
    singleCheck,
    stepForError,
    stepOneValid,
    suggestShortCode,
    toJv,
    toPayload,
} from './company-wizard';
import type { WizardState } from './company-wizard';

const vertexLabs = (): WizardState => {
    const state = addPartner(
        { ...initialState({ name: 'Aarav Mehta' }, 'Vertex Labs Pvt Ltd') },
        null,
    );
    state.partners[0].equity = '60.00';
    state.partners[1] = {
        ...state.partners[1],
        name: 'Ishita Rao',
        equity: '40.00',
    };

    return state;
};

describe('Single company (CreateCompanySingle)', () => {
    it('starts with the creator as Admin at 100%', () => {
        const state = initialState(
            { name: 'Aarav Mehta' },
            'Vertex Labs Pvt Ltd',
        );

        expect(state.partners).toHaveLength(1);
        expect(state.partners[0]).toMatchObject({
            name: 'Aarav Mehta',
            role: 'admin',
            colour: 1,
            equity: '100.00',
        });
        expect(state.shortCode).toBe('VL');
        expect(stepOneValid(state)).toBe(true);
    });

    it('needs exactly 100% and says the gap', () => {
        const state = vertexLabs();
        expect(singleCheck(state).ok).toBe(true);

        state.partners[1].equity = '35.00';
        expect(singleCheck(state)).toEqual({
            ok: false,
            total: 9500,
            message: 'Equity adds up to 95.00%. Add 5.00% so it totals 100%.',
        });
        expect(stepOneValid(state)).toBe(false);
    });

    it('splits evenly with the remainder on the first row', () => {
        const state = evenPartners(addPartner(vertexLabs(), null));

        expect(state.partners.map((p) => p.equity)).toEqual([
            '33.34',
            '33.33',
            '33.33',
        ]);
    });

    it('cycles colours 1 → 6 → 1 and gives new rows the next colour', () => {
        expect(nextColour(1)).toBe(2);
        expect(nextColour(6)).toBe(1);
        expect(addPartner(vertexLabs(), null).partners[2].colour).toBe(3);
    });

    it('suggests short codes from the name', () => {
        expect(suggestShortCode('Saarthi Portal JV')).toBe('SPJV');
        expect(suggestShortCode('Northstar Studio')).toBe('NS');
        expect(suggestShortCode('Kriya')).toBe('KRIY');
    });
});

describe('Joint venture (CreateCompanyJV)', () => {
    const saarthi = (): WizardState => {
        let state = toJv(
            initialState({ name: 'Aarav Mehta' }, 'Saarthi Portal JV'),
        );
        const [vertex, bluefin] = state.sides;
        state.sides[0].name = 'Vertex Labs';
        state.sides[1].name = 'Bluefin Infotech';
        state = addPartner(state, vertex.key);
        state = addPartner(state, bluefin.key);
        state = addPartner(state, bluefin.key);
        state.partners[0].equity = '60.00';
        state.partners[1] = {
            ...state.partners[1],
            name: 'Ishita Rao',
            equity: '40.00',
        };
        state.partners[2] = {
            ...state.partners[2],
            name: 'Kabir Shah',
            equity: '50.00',
        };
        state.partners[3] = {
            ...state.partners[3],
            name: 'Meera Iyer',
            equity: '50.00',
        };

        return state;
    };

    it('checks the sides and each side’s partners', () => {
        const state = saarthi();

        expect(sidesCheck(state).ok).toBe(true);
        expect(state.sides.every((s) => sideCheck(state, s).ok)).toBe(true);
        expect(stepOneValid(state)).toBe(true);

        state.partners[3].equity = '40.00';
        expect(sideCheck(state, state.sides[1]).message).toBe(
            'Bluefin Infotech partners add up to 90.00%. Add 10.00% so this side totals 100%.',
        );
        expect(stepOneValid(state)).toBe(false);
    });

    it('computes overall equity as side × within side', () => {
        const state = saarthi();

        expect(overallBps(state, state.partners[0])).toBe(3000);
        expect(overallBps(state, state.partners[3])).toBe(2500);
    });

    it('evens the sides', () => {
        const state = saarthi();
        state.sides[0].equity = '70.00';

        expect(evenSides(state).sides.map((s) => s.equity)).toEqual([
            '50.00',
            '50.00',
        ]);
    });

    it('sends side keys and equity as typed', () => {
        const payload = toPayload(saarthi(), 1);

        expect(payload.company).toMatchObject({
            type: 'jv',
            short_code: 'SPJV',
            opening_balance: '0.00',
        });
        expect(payload.sides).toHaveLength(2);
        expect(payload.partners[2].side_key).toBe(payload.sides[1].key);
    });
});

describe('Team step', () => {
    it('totals fixed monthly cost and counts invites', () => {
        const state = vertexLabs();
        state.partners[1].email = 'ishita@vertexlabs.in';
        state.members = [
            {
                key: 'm1',
                name: 'Rohan Desai',
                designation: 'Senior developer',
                email: 'rohan@vertexlabs.in',
                costType: 'fixed_monthly',
                ratePaise: 80_000_00,
                partnerKey: 'owner',
            },
            {
                key: 'm2',
                name: 'Dev Patel',
                designation: 'Developer',
                email: '',
                costType: 'hourly',
                ratePaise: 450_00,
                partnerKey: 'owner',
            },
        ];

        expect(fixedMonthlyPaise(state)).toBe(80_000_00);
        expect(inviteCounts(state)).toEqual({ partners: 1, members: 1 });
        expect(inviteCounts({ ...state, inviteMembers: false }).members).toBe(
            0,
        );
        expect(toPayload(state).members[0]).toMatchObject({
            rate: '80000.00',
            cost_type: 'fixed_monthly',
        });
    });

    it('maps server errors back to their step', () => {
        expect(stepForError('members.0.name')).toBe(2);
        expect(stepForError('company.short_code')).toBe(1);
        expect(stepForError('partners')).toBe(1);
    });
});
