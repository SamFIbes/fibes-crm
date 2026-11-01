import { describe, expect, it } from 'vitest';
import { planLabel, type CatalogPlan } from './catalog';

const plan: CatalogPlan = {
    id: 1,
    product_id: 1,
    name: 'SMN-8MB UP TO 16MB',
    name_ar: '٨ ميغا حتى ١٦ ميغا',
    service_type: 'ftth',
    down_mbps: 8,
    up_mbps: 8,
    price: '35.00',
    price_lbp: '3133000',
};

describe('plan label', () => {
    it('joins the name, speed, and dollar price', () => {
        expect(planLabel(plan)).toBe('SMN-8MB UP TO 16MB · 8/8 · $35.00');
    });

    it('uses the Arabic name when asked', () => {
        expect(planLabel(plan, true)).toBe('٨ ميغا حتى ١٦ ميغا · 8/8 · $35.00');
    });
});
