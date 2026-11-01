import { describe, expect, it } from 'vitest';
import { needsSignOff, whatsAppAllowed } from './orders';

describe('order sign-off', () => {
    it('sends a termination and a price change through approval', () => {
        expect(needsSignOff('termination', false)).toBe(true);
        expect(needsSignOff('upgrade', true)).toBe(true);
        expect(needsSignOff('downgrade', true)).toBe(true);
    });

    it('lets a relocation and an unchanged upgrade go straight through', () => {
        expect(needsSignOff('relocation', false)).toBe(false);
        expect(needsSignOff('upgrade', false)).toBe(false);
    });

    it('only builds a WhatsApp link when the customer agreed and has a number', () => {
        expect(whatsAppAllowed(true, '+96170123456')).toBe(true);
        expect(whatsAppAllowed(false, '+96170123456')).toBe(false);
        expect(whatsAppAllowed(true, null)).toBe(false);
    });
});