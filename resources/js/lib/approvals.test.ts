import { describe, expect, it } from 'vitest';
import { bulkAllowed } from './approvals';

describe('bulk approval', () => {
    it('allows a group only when every selected request is low risk', () => {
        expect(bulkAllowed([
            { low_risk: true, selected: true },
            { low_risk: true, selected: true },
        ])).toBe(true);
        expect(bulkAllowed([
            { low_risk: true, selected: true },
            { low_risk: false, selected: true },
        ])).toBe(false);
        expect(bulkAllowed([{ low_risk: true, selected: false }])).toBe(false);
    });
});
