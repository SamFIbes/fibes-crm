import { describe, expect, it } from 'vitest';
import { HUB_HEIGHT, HUB_WIDTH, layoutTopology, PROVIDER_HEIGHT, PROVIDER_WIDTH, statusCounts, type PlacedProvider } from './providerTopology';

function provider(key: string, status: PlacedProvider['status'] = 'active', sample = false): PlacedProvider {
    return { key, group: 'payments', status, sample, label: key };
}

describe('provider topology layout', () => {
    it('counts each status', () => {
        expect(statusCounts([provider('a', 'active'), provider('b', 'recent', true), provider('c', 'error'), provider('d', 'active')])).toEqual({
            active: 2,
            recent: 1,
            error: 1,
        });
    });

    it('keeps the hub in the middle and draws one edge per provider', () => {
        const providers = Array.from({ length: 20 }, (_, index) => provider(`p${index}`, index === 3 ? 'error' : 'active'));
        const { nodes, edges } = layoutTopology(providers, 'FIBES');
        const hub = nodes.find((node) => node.id === 'hub');

        expect(hub).toMatchObject({ x: -HUB_WIDTH / 2, y: -HUB_HEIGHT / 2, label: 'FIBES' });
        expect(nodes).toHaveLength(21);
        expect(edges).toHaveLength(20);
        expect(edges.every((edge) => edge.source === 'hub')).toBe(true);
        expect(new Set(edges.map((edge) => edge.target)).size).toBe(20);
    });

    it('does not stack the pills', () => {
        const providers = Array.from({ length: 20 }, (_, index) => provider(`p${index}`));
        const { nodes } = layoutTopology(providers, 'FIBES');
        const boxes = nodes.map((node) => ({
            id: node.id,
            x: node.x,
            y: node.y,
            w: node.kind === 'hub' ? HUB_WIDTH : PROVIDER_WIDTH,
            h: node.kind === 'hub' ? HUB_HEIGHT : PROVIDER_HEIGHT,
        }));

        for (let i = 0; i < boxes.length; i += 1) {
            for (let j = i + 1; j < boxes.length; j += 1) {
                const a = boxes[i];
                const b = boxes[j];
                const overlaps = a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
                expect(overlaps, `${a.id} overlaps ${b.id}`).toBe(false);
            }
        }
    });

    it('attaches the top provider to the hub’s top handle', () => {
        const { edges } = layoutTopology([provider('only')], 'FIBES');

        expect(edges[0]).toMatchObject({ sourceHandle: 'top', targetHandle: 'bottom', status: 'active' });
    });
});
