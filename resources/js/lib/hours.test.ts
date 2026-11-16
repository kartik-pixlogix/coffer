import { describe, expect, it } from 'vitest';
import {
    distribute,
    formatCenti,
    parseHours,
    perDayLabel,
    SATURDAY,
    workingDays,
} from '@/lib/hours';

describe('parseHours', () => {
    it.each([
        ['2.5', 250],
        ['2:30', 250],
        ['57:20', 5733],
        ['57.33', 5733],
        ['8', 800],
        ['.75', 75],
        ['7:05', 708],
        ['0.255', 26],
    ])('%s → %i', (input, centi) => {
        expect(parseHours(input)).toBe(centi);
    });

    it.each(['', 'abc', '2:75', '-1', '1,5', '2.5h'])('rejects %s', (input) => {
        expect(parseHours(input)).toBeNull();
    });
});

describe('formatCenti', () => {
    it('matches the timesheet design', () => {
        expect(formatCenti(800)).toBe('8.0');
        expect(formatCenti(250)).toBe('2.5');
        expect(formatCenti(5733)).toBe('57.33');
        expect(formatCenti(215750)).toBe('2,157.5');
    });

    it('drops the decimal on Team cards', () => {
        expect(formatCenti(14200, 0)).toBe('142');
        expect(formatCenti(14250, 0)).toBe('142.5');
    });
});

describe('month mode', () => {
    const MON_FRI = 31;

    it('counts November 2026 working days', () => {
        expect(workingDays(2026, 10, MON_FRI)).toHaveLength(21);
        expect(workingDays(2026, 10, MON_FRI | SATURDAY)).toHaveLength(25);
        expect(workingDays(2026, 10, MON_FRI)[0]).toBe('2026-11-02');
    });

    it('shows the design preview', () => {
        expect(perDayLabel(5733, 21)).toBe('21 working days → 2.73 hrs/day');
    });

    it('distributes exactly, earlier days first', () => {
        expect(distribute(5733, 21)).toEqual(Array(21).fill(273));
        expect(distribute(1000, 3)).toEqual([334, 333, 333]);

        for (let total = 0; total < 3000; total += 37) {
            for (let days = 1; days <= 27; days++) {
                expect(distribute(total, days).reduce((a, b) => a + b, 0)).toBe(
                    total,
                );
            }
        }
    });
});
