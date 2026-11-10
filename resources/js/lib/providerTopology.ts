export type ProviderStatus = 'active' | 'recent' | 'error';

export type TopologyProvider = {
    key: string;
    group: string;
    status: ProviderStatus;
    sample: boolean;
};

export type PlacedProvider = TopologyProvider & { label: string };

export type LayoutNode = {
    id: string;
    kind: 'hub' | 'provider';
    x: number;
    y: number;
    key: string;
    label: string;
    status: ProviderStatus | null;
    sample: boolean;
};

export type HandleSide = 'top' | 'right' | 'bottom' | 'left';

export type LayoutEdge = {
    id: string;
    source: 'hub';
    target: string;
    sourceHandle: HandleSide;
    targetHandle: HandleSide;
    status: ProviderStatus;
};

export const PROVIDER_WIDTH = 188;
export const PROVIDER_HEIGHT = 40;
export const HUB_WIDTH = 132;
export const HUB_HEIGHT = 44;

export function statusCounts(providers: TopologyProvider[]): Record<ProviderStatus, number> {
    return {
        active: providers.filter((provider) => provider.status === 'active').length,
        recent: providers.filter((provider) => provider.status === 'recent').length,
        error: providers.filter((provider) => provider.status === 'error').length,
    };
}

/**
 * Two rings when there are many providers, so the pills do not sit on top
 * of each other. Angles are presentation only.
 */
export function layoutTopology(providers: PlacedProvider[], hubLabel: string): { nodes: LayoutNode[]; edges: LayoutEdge[] } {
    const nodes: LayoutNode[] = [
        {
            id: 'hub',
            kind: 'hub',
            x: -HUB_WIDTH / 2,
            y: -HUB_HEIGHT / 2,
            key: 'hub',
            label: hubLabel,
            status: null,
            sample: false,
        },
    ];
    const edges: LayoutEdge[] = [];

    if (providers.length === 0) {
        return { nodes, edges };
    }

    if (providers.length <= 8) {
        placeRing(providers, 560, 340, -Math.PI / 2, nodes, edges);
    } else {
        const outer = providers.filter((_, index) => index % 2 === 0);
        const inner = providers.filter((_, index) => index % 2 === 1);
        placeRing(outer, 760, 470, -Math.PI / 2, nodes, edges);
        placeRing(inner, 470, 280, -Math.PI / 2 + Math.PI / Math.max(outer.length, 1), nodes, edges);
    }

    return { nodes, edges };
}

function placeRing(providers: PlacedProvider[], rx: number, ry: number, rotation: number, nodes: LayoutNode[], edges: LayoutEdge[]): void {
    providers.forEach((provider, index) => {
        const angle = rotation + (index / providers.length) * Math.PI * 2;
        const handles = handlesFor(angle);

        nodes.push({
            id: provider.key,
            kind: 'provider',
            x: Math.cos(angle) * rx - PROVIDER_WIDTH / 2,
            y: Math.sin(angle) * ry - PROVIDER_HEIGHT / 2,
            key: provider.key,
            label: provider.label,
            status: provider.status,
            sample: provider.sample,
        });
        edges.push({
            id: `hub-${provider.key}`,
            source: 'hub',
            target: provider.key,
            sourceHandle: handles.sourceHandle,
            targetHandle: handles.targetHandle,
            status: provider.status,
        });
    });
}

function handlesFor(angle: number): { sourceHandle: HandleSide; targetHandle: HandleSide } {
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);

    if (Math.abs(cos) >= Math.abs(sin)) {
        return cos >= 0
            ? { sourceHandle: 'right', targetHandle: 'left' }
            : { sourceHandle: 'left', targetHandle: 'right' };
    }

    return sin >= 0
        ? { sourceHandle: 'bottom', targetHandle: 'top' }
        : { sourceHandle: 'top', targetHandle: 'bottom' };
}
