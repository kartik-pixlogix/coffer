import { describe, expect, it } from 'vite-plus/test';
import { joinNames } from './avatar-stack';
import { initialsFrom } from './avatar';

describe('initialsFrom', () => {
    it.each([
        ['Aarav Mehta', 'AM'],
        ['Saarthi Portal JV', 'SP'],
        ['vertex labs', 'VL'],
        ['  Ishita   Rao ', 'IR'],
        ['Razorpay', 'RA'],
        ['K', 'K'],
        ['', ''],
        ['(Bluefin) Infotech', 'BI'],
    ])('%s → %s', (name, initials) => {
        expect(initialsFrom(name)).toBe(initials);
    });
});

describe('joinNames', () => {
    it('lists names with a final "and"', () => {
        expect(joinNames([])).toBe('');
        expect(joinNames(['Aarav Mehta'])).toBe('Aarav Mehta');
        expect(joinNames(['Aarav Mehta', 'Kabir Shah'])).toBe(
            'Aarav Mehta and Kabir Shah',
        );
        expect(
            joinNames([
                'Aarav Mehta',
                'Ishita Rao',
                'Rohan Desai',
                'Sana Qureshi',
            ]),
        ).toBe('Aarav Mehta, Ishita Rao, Rohan Desai and Sana Qureshi');
    });
});
