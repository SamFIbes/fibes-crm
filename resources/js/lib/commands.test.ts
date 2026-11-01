import { describe, expect, it } from 'vitest';
import { filterCommands, type CommandItem } from '@/lib/commands';

const items: CommandItem[] = [
    { id: 'leads', label: 'Leads', group: 'navigation', keywords: 'customers', perform: () => undefined },
    { id: 'arabic', label: 'العملاء المحتملون', group: 'navigation', keywords: 'leads', perform: () => undefined },
    { id: 'dark', label: 'Switch to dark mode', group: 'preferences', perform: () => undefined },
];

describe('filterCommands', () => {
    it('returns every action when the query is empty', () => {
        expect(filterCommands(items, '  ')).toHaveLength(3);
    });

    it('matches labels and keywords in either language', () => {
        expect(filterCommands(items, 'lead').map((item) => item.id)).toEqual(['leads', 'arabic']);
        expect(filterCommands(items, 'محتمل').map((item) => item.id)).toEqual(['arabic']);
        expect(filterCommands(items, 'dark').map((item) => item.id)).toEqual(['dark']);
    });
});
