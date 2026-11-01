import { describe, expect, it } from 'vitest';
import { mapPoint, whatsAppLink } from '@/lib/leadPlace';

describe('lead places', () => {
    it('reads a pin from a Google Maps link and a WhatsApp location paste', () => {
        expect(mapPoint('https://www.google.com/maps/@33.8938,35.5018,17z')).toEqual({ lat: '33.8938', lng: '35.5018' });
        expect(mapPoint('https://maps.google.com/?q=33.88,35.49')).toEqual({ lat: '33.88', lng: '35.49' });
        expect(mapPoint('no pin here')).toBeNull();
    });

    it('builds a WhatsApp chat link from an E.164 number', () => {
        expect(whatsAppLink('+961 3 123 456', 'Hello')).toBe('https://wa.me/9613123456?text=Hello');
    });
});