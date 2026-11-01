import { describe, expect, it } from 'vitest';
import { monthGrid, weekDates } from '@/lib/calendar';

describe('calendar dates', () => {
    it('starts the week on Sunday in UTC so a Beirut afternoon stays on the same day', () => {
        expect(weekDates('2026-11-04')).toEqual([
            '2026-11-01',
            '2026-11-02',
            '2026-11-03',
            '2026-11-04',
            '2026-11-05',
            '2026-11-06',
            '2026-11-07',
        ]);
    });

    it('builds a 42-day month that includes the leading and trailing days', () => {
        const days = monthGrid('2026-11-15');

        expect(days).toHaveLength(42);
        expect(days[0]).toBe('2026-11-01');
        expect(days[29]).toBe('2026-11-30');
        expect(days[30]).toBe('2026-12-01');
    });
});
