import { describe, expect, it } from 'vitest';
import { displayMinutes, toMinutes } from './slaUnits';

describe('SLA display', () => {
    it('shows short responses in minutes and longer ones in hours', () => {
        expect(displayMinutes(15, 'respond')).toEqual({ value: 15, unit: 'minutes' });
        expect(displayMinutes(30, 'respond')).toEqual({ value: 30, unit: 'minutes' });
        expect(displayMinutes(120, 'respond')).toEqual({ value: 2, unit: 'hours' });
        expect(displayMinutes(480, 'respond')).toEqual({ value: 8, unit: 'hours' });
    });

    it('shows resolve targets in days and still stores minutes', () => {
        expect(displayMinutes(1440, 'resolve')).toEqual({ value: 1, unit: 'days' });
        expect(displayMinutes(2880, 'resolve')).toEqual({ value: 2, unit: 'days' });
        expect(toMinutes(2, 'days')).toBe(2880);
        expect(toMinutes(8, 'hours')).toBe(480);
    });
});
