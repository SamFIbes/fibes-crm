import type { Queue } from '@/components/dashboard/types';

export type QueueTone = 'warn' | 'bad' | 'good' | 'neutral';

export type QueueShare = { key: string; count: number; share: number };

const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

export function queueTotal(queues: Queue[]): number {
    return queues.reduce((sum, queue) => sum + Math.max(0, queue.count), 0);
}

export function queueShares(queues: Queue[]): QueueShare[] {
    const total = queueTotal(queues);

    if (total === 0) {
        return [];
    }

    return queues
        .filter((queue) => queue.count > 0)
        .map((queue) => ({ key: queue.key, count: queue.count, share: queue.count / total }));
}

export function queueMax(queues: Queue[]): number {
    return queues.reduce((max, queue) => Math.max(max, queue.count), 0);
}

export function toneOf(queue: Queue): QueueTone {
    if (queue.count <= 0) {
        return 'good';
    }

    const tone = queue.items[0]?.tone;

    if (tone === 'warn' || tone === 'bad') {
        return tone;
    }

    return 'neutral';
}

function parseIsoDate(value: string): Date | null {
    const match = ISO_DATE.exec(value);

    if (!match) {
        return null;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const date = new Date(Date.UTC(year, month - 1, day));

    if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) {
        return null;
    }

    return date;
}

export function formatPeriod(from: string, to: string, locale: string): string {
    const start = parseIsoDate(from);
    const end = parseIsoDate(to);

    if (!start || !end) {
        return `${from} – ${to}`;
    }

    try {
        const formatter = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });

        return `${formatter.format(start)} – ${formatter.format(end)}`;
    } catch {
        return `${from} – ${to}`;
    }
}
