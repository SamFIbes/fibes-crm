import { describe, expect, it } from 'vitest';
import type { AttentionItem, Queue } from '@/components/dashboard/types';
import { formatPeriod, queueMax, queueShares, queueTotal, toneOf } from '@/lib/dashboard';

const item = (id: number, tone = 'warn'): AttentionItem => ({
    id,
    title: `Item ${id}`,
    meta: 'meta',
    meta_ar: 'ميتا',
    href: `/items/${id}`,
    tone,
});

const queue = (key: string, count: number, tones: string[] = []): Queue => ({
    key,
    href: `/queues/${key}`,
    count,
    items: tones.map((tone, index) => item(index + 1, tone)),
});

describe('queueTotal', () => {
    it('sums the counts of all queues', () => {
        expect(queueTotal([queue('a', 3), queue('b', 4), queue('c', 0)])).toBe(7);
    });

    it('returns 0 for no queues', () => {
        expect(queueTotal([])).toBe(0);
    });

    it('ignores negative counts', () => {
        expect(queueTotal([queue('a', -2), queue('b', 5)])).toBe(5);
    });
});

describe('queueShares', () => {
    it('returns only queues with a positive count', () => {
        const shares = queueShares([queue('a', 1), queue('b', 0), queue('c', 3)]);

        expect(shares.map((share) => share.key)).toEqual(['a', 'c']);
    });

    it('computes shares as count / total and they add up to 1', () => {
        const shares = queueShares([queue('a', 1), queue('b', 3)]);

        expect(shares[0]?.share).toBeCloseTo(0.25);
        expect(shares[1]?.share).toBeCloseTo(0.75);
        expect(shares.reduce((sum, share) => sum + share.share, 0)).toBeCloseTo(1);
    });

    it('returns an empty list when nothing is waiting', () => {
        expect(queueShares([queue('a', 0), queue('b', 0)])).toEqual([]);
        expect(queueShares([])).toEqual([]);
    });
});

describe('queueMax', () => {
    it('returns the largest count', () => {
        expect(queueMax([queue('a', 2), queue('b', 9), queue('c', 4)])).toBe(9);
    });

    it('returns 0 for no queues', () => {
        expect(queueMax([])).toBe(0);
    });
});

describe('toneOf', () => {
    it('is good when the queue is empty', () => {
        expect(toneOf(queue('a', 0, ['bad']))).toBe('good');
    });

    it('follows the tone of the first item', () => {
        expect(toneOf(queue('a', 2, ['warn', 'bad']))).toBe('warn');
        expect(toneOf(queue('a', 2, ['bad', 'warn']))).toBe('bad');
    });

    it('is neutral for other tones or when there are no items', () => {
        expect(toneOf(queue('a', 2, ['info']))).toBe('neutral');
        expect(toneOf(queue('a', 2))).toBe('neutral');
    });
});

describe('formatPeriod', () => {
    const format = (locale: string, date: Date) =>
        new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(date);

    it('formats valid dates for the locale', () => {
        const expected = `${format('en', new Date(Date.UTC(2025, 0, 5)))} – ${format('en', new Date(Date.UTC(2025, 0, 20)))}`;

        expect(formatPeriod('2025-01-05', '2025-01-20', 'en')).toBe(expected);
    });

    it('formats valid dates for Arabic', () => {
        const expected = `${format('ar', new Date(Date.UTC(2025, 5, 1)))} – ${format('ar', new Date(Date.UTC(2025, 5, 30)))}`;

        expect(formatPeriod('2025-06-01', '2025-06-30', 'ar')).toBe(expected);
    });

    it('returns the raw strings for unparseable input', () => {
        expect(formatPeriod('nope', '2025-01-20', 'en')).toBe('nope – 2025-01-20');
        expect(formatPeriod('2025-01-05', '', 'en')).toBe('2025-01-05 – ');
    });

    it('rejects dates that roll over', () => {
        expect(formatPeriod('2025-02-31', '2025-03-01', 'en')).toBe('2025-02-31 – 2025-03-01');
        expect(formatPeriod('2025-13-01', '2025-03-01', 'en')).toBe('2025-13-01 – 2025-03-01');
    });
});
