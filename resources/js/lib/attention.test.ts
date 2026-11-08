import { describe, expect, it } from 'vitest';
import { attentionRank, formatRemaining, slaConfigured } from './attention';

const side = (state: string) => ({ state, remaining_seconds: state === 'breached' ? -60 : 600 });

describe('attention order', () => {
    it('puts a breached resolve clock ahead of an unassigned ticket', () => {
        const breached = attentionRank({ status: 'open', assignee: 'Nour', sla: { response: side('ok'), resolve: side('breached') } });
        const unassigned = attentionRank({ status: 'open', assignee: null, sla: { response: side('ok'), resolve: side('ok') } });

        expect(breached).toBeLessThan(unassigned);
    });

    it('does not invent an SLA when the clocks are missing', () => {
        expect(slaConfigured(null)).toBe(false);
        expect(attentionRank({ status: 'open', assignee: null, sla: null })).toBe(2);
    });

    it('formats a remaining clock without rounding it into a percent', () => {
        expect(formatRemaining(90)).toBe('1m');
        expect(formatRemaining(-3660)).toBe('-1h 1m');
    });
});
