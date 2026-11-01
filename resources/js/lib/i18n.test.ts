import { describe, expect, it } from 'vitest';
import { directionFor, translate } from '@/lib/i18n';

describe('i18n', () => {
    it('maps Arabic to right-to-left and everything else to left-to-right', () => {
        expect(directionFor('ar')).toBe('rtl');
        expect(directionFor('en')).toBe('ltr');
        expect(directionFor('fr')).toBe('ltr');
    });

    it('reads nested keys and replaces tokens', () => {
        const tree = { dashboard: { greeting: 'Hello, :name' } };

        expect(translate(tree, 'dashboard.greeting', { name: 'Sam' })).toBe('Hello, Sam');
        expect(translate(tree, 'missing.key')).toBe('missing.key');
    });
});
