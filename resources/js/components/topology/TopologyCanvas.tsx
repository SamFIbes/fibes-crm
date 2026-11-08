import { Handle, Panel, Position, ReactFlow, useReactFlow, type Node, type NodeProps } from '@xyflow/react';
import {
    Activity,
    Banknote,
    Cable,
    Coins,
    CreditCard,
    Expand,
    FileText,
    Globe,
    Landmark,
    Mail,
    Map,
    Megaphone,
    MessageCircle,
    Minus,
    Phone,
    Plus,
    Radio,
    ScanLine,
    ScrollText,
    ShieldCheck,
    Smartphone,
    Wallet,
    Webhook,
    type LucideIcon,
} from 'lucide-react';
import { memo, useMemo } from 'react';
import '@xyflow/react/dist/style.css';
import { BrandMark } from '@/components/brand';
import { useI18n } from '@/lib/i18n';
import { useTheme } from '@/lib/theme';
import {
    HUB_HEIGHT,
    HUB_WIDTH,
    layoutTopology,
    PROVIDER_HEIGHT,
    PROVIDER_WIDTH,
    type HandleSide,
    type LayoutEdge,
    type LayoutNode,
    type ProviderStatus,
    type TopologyProvider,
} from '@/lib/providerTopology';
import { cn } from '@/lib/utils';

const ICONS: Record<string, LucideIcon> = {
    web_form: Globe,
    meta: Megaphone,
    turnstile: ShieldCheck,
    whish: Wallet,
    omt: Banknote,
    bank: Landmark,
    card: CreditCard,
    cheque: ScrollText,
    cash: Coins,
    whatsapp: MessageCircle,
    email: Mail,
    sms: Smartphone,
    telephony: Phone,
    krypton: Radio,
    fiberdesk: Cable,
    pdf: FileText,
    filescan: ScanLine,
    maps: Map,
    webhooks: Webhook,
    sentry: Activity,
};

const BORDER: Record<ProviderStatus, string> = {
    active: 'border-[#8fceb0]',
    recent: 'border-[#e7b56a]',
    error: 'border-[#e08b78]',
};

const DOT: Record<ProviderStatus, string> = {
    active: 'bg-[#1d8656]',
    recent: 'bg-[#e9953f]',
    error: 'bg-[#de5539]',
};

const SIDES: HandleSide[] = ['top', 'right', 'bottom', 'left'];

const POSITION: Record<HandleSide, Position> = {
    top: Position.Top,
    right: Position.Right,
    bottom: Position.Bottom,
    left: Position.Left,
};

type HubData = { label: string };
type ProviderData = { key: string; label: string; status: ProviderStatus; sample: boolean };
type HubFlowNode = Node<HubData, 'hub'>;
type ProviderFlowNode = Node<ProviderData, 'provider'>;

const HubNode = memo(function HubNode({ data }: NodeProps<HubFlowNode>) {
    return (
        <div
            className="flex items-center gap-2 rounded-full border border-accent bg-[color-mix(in_srgb,var(--accent)_14%,var(--card))] px-2.5 text-accent shadow-card"
            style={{ width: HUB_WIDTH, height: HUB_HEIGHT }}
            data-testid="topology-hub"
        >
            {SIDES.map((side) => (
                <Handle key={side} id={side} type="source" position={POSITION[side]} />
            ))}
            <BrandMark size={22} />
            <span className="truncate text-sm font-semibold">{data.label}</span>
        </div>
    );
});

const ProviderNode = memo(function ProviderNode({ data }: NodeProps<ProviderFlowNode>) {
    const { t } = useI18n();
    const Icon = ICONS[data.key] ?? Cable;

    return (
        <div
            className={cn('flex items-center gap-2 rounded-full border bg-card px-2 shadow-[0_8px_18px_-16px_rgb(14_15_45/0.55)]', BORDER[data.status])}
            style={{ width: PROVIDER_WIDTH, height: PROVIDER_HEIGHT }}
            data-status={data.status}
            data-sample={data.sample ? 'true' : 'false'}
            title={data.sample ? `${data.label} (${t('topology.sample_mark')})` : data.label}
        >
            {SIDES.map((side) => (
                <Handle key={side} id={side} type="target" position={POSITION[side]} />
            ))}
            <span className="grid size-6 shrink-0 place-items-center rounded-md bg-well text-text">
                <Icon className="size-3.5" aria-hidden />
            </span>
            <span className="min-w-0 flex-1 truncate text-[13px] font-medium">{data.label}</span>
            <span className={cn('size-2 shrink-0 rounded-full', DOT[data.status])} />
            {data.sample ? <span className="sr-only">{t('topology.sample_mark')}</span> : null}
        </div>
    );
});

const nodeTypes = { hub: HubNode, provider: ProviderNode };

function ZoomBar() {
    const { t } = useI18n();
    const { zoomIn, zoomOut, fitView } = useReactFlow();
    const item = 'grid size-9 place-items-center text-muted hover:bg-well hover:text-text';

    return (
        <div className="flex flex-col overflow-hidden rounded-xl bg-card shadow-card ring-1 ring-line">
            <button type="button" className={item} aria-label={t('topology.zoom_in')} onClick={() => void zoomIn({ duration: 180 })}>
                <Plus className="size-4" />
            </button>
            <button type="button" className={cn(item, 'border-y border-line')} aria-label={t('topology.zoom_out')} onClick={() => void zoomOut({ duration: 180 })}>
                <Minus className="size-4" />
            </button>
            <button type="button" className={item} aria-label={t('topology.fit')} onClick={() => void fitView({ padding: 0.16, duration: 180 })}>
                <Expand className="size-4" />
            </button>
        </div>
    );
}

function toFlowNode(node: LayoutNode): HubFlowNode | ProviderFlowNode {
    if (node.kind === 'hub') {
        return {
            id: node.id,
            type: 'hub',
            position: { x: node.x, y: node.y },
            data: { label: node.label },
            draggable: false,
            selectable: false,
            connectable: false,
            zIndex: 10,
            width: HUB_WIDTH,
            height: HUB_HEIGHT,
        };
    }

    return {
        id: node.id,
        type: 'provider',
        position: { x: node.x, y: node.y },
        data: {
            key: node.key,
            label: node.label,
            status: node.status ?? 'active',
            sample: node.sample,
        },
        draggable: false,
        selectable: false,
        connectable: false,
        zIndex: 2,
        width: PROVIDER_WIDTH,
        height: PROVIDER_HEIGHT,
    };
}

function toFlowEdge(edge: LayoutEdge) {
    return {
        id: edge.id,
        source: edge.source,
        target: edge.target,
        sourceHandle: edge.sourceHandle,
        targetHandle: edge.targetHandle,
        type: 'default' as const,
        style: {
            stroke: `var(--topo-${edge.status})`,
            strokeWidth: edge.status === 'error' ? 2 : 1.6,
        },
    };
}

export function TopologyCanvas({ providers }: { providers: TopologyProvider[] }) {
    const { t } = useI18n();
    const { theme } = useTheme();
    const graph = useMemo(() => {
        const placed = providers.map((provider) => {
            const key = `topology.p_${provider.key}`;
            const translated = t(key);

            return { ...provider, label: translated === key ? provider.key : translated };
        });

        return layoutTopology(placed, t('topology.hub'));
    }, [providers, t]);
    const nodes = useMemo(() => graph.nodes.map(toFlowNode), [graph.nodes]);
    const edges = useMemo(() => graph.edges.map(toFlowEdge), [graph.edges]);

    return (
        <div className="topology-flow h-full w-full" dir="ltr" data-testid="topology-canvas" role="application" aria-label={t('topology.canvas')}>
            <ReactFlow
                nodes={nodes}
                edges={edges}
                nodeTypes={nodeTypes}
                colorMode={theme === 'dark' ? 'dark' : 'light'}
                fitView
                fitViewOptions={{ padding: 0.16 }}
                minZoom={0.3}
                maxZoom={1.75}
                nodesDraggable={false}
                nodesConnectable={false}
                elementsSelectable={false}
                panOnScroll={false}
                zoomOnScroll
                zoomOnPinch
                zoomOnDoubleClick
                proOptions={{ hideAttribution: false }}
                className="!bg-transparent"
            >
                <Panel position="bottom-left" className="!m-4">
                    <ZoomBar />
                </Panel>
            </ReactFlow>
        </div>
    );
}
