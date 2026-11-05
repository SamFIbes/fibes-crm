import { describe, expect, it } from 'vitest';
import { metricNumber } from './HomeChart';

describe('metricNumber', () => {
    it('reads the first number in a tile and ignores the rest of the label', () => {
        expect(metricNumber('1,248')).toBe(1248);
        expect(metricNumber('$284,750')).toBe(284750);
        expect(metricNumber('12.4%')).toBe(12.4);
        expect(metricNumber('2h 47m')).toBe(2);
        expect(metricNumber('—')).toBeNull();
    });
});
