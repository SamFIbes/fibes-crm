export type NetNode = { id: number; parent_id: number | null };
export type NetTree<T extends NetNode> = T & { children: NetTree<T>[] };

export function networkForest<T extends NetNode>(nodes: T[]): NetTree<T>[] {
    const byId = new Map<number, NetTree<T>>();

    for (const node of nodes) {
        byId.set(node.id, { ...node, children: [] });
    }

    const roots: NetTree<T>[] = [];

    for (const node of byId.values()) {
        const parent = node.parent_id === null ? undefined : byId.get(node.parent_id);

        if (parent) {
            parent.children.push(node);
        } else {
            roots.push(node);
        }
    }

    return roots;
}
