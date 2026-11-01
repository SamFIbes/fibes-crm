import { describe, expect, it } from 'vitest';
import { discountNeedsApproval, quoteNeedsApproval } from './quotes';

describe('discount caps', () => {
    it('asks for approval when the percent is over the cap even if the dollars are not', () => {
        expect(discountNeedsApproval(12, 4.2, 5, 5)).toBe(true);
    });

    it('asks for approval when the dollars are over the cap even if the percent is not', () => {
        expect(discountNeedsApproval(4, 8, 5, 5)).toBe(true);
    });

    it('stays inside when both ceilings are met', () => {
        expect(discountNeedsApproval(5, 1.75, 5, 5)).toBe(false);
    });

    it('treats a missing limit as unlimited and a custom price as approval', () => {
        expect(discountNeedsApproval(20, 7, null, null)).toBe(false);
        expect(quoteNeedsApproval({ percent: 0, amount: 0, limitPercent: null, limitAmount: null, custom: true })).toBe(true);
    });
});
