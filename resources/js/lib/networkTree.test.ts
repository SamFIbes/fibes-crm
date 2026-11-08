import { describe, expect, it } from 'vitest';
import { networkForest } from './networkTree';

describe('network inventory tree', () => {
    it('nests a PON under its OLT and keeps a missing parent at the root', () => {
        const forest = networkForest([
            { id: 2, parent_id: 1, name: 'OLT' },
            { id: 1, parent_id: null, name: 'Exchange' },
            { id: 3, parent_id: 2, name: 'PON' },
            { id: 9, parent_id: 404, name: 'Orphan' },
        ]);
        const exchange = forest.find((node) => node.id === 1);

        expect(exchange?.children[0]?.name).toBe('OLT');
        expect(exchange?.children[0]?.children[0]?.name).toBe('PON');
        expect(forest.map((node) => node.id).sort()).toEqual([1, 9]);
    });

    it('returns an empty forest when there are no nodes', () => {
        expect(networkForest([])).toEqual([]);
    });
});
