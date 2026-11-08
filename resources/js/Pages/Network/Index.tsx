import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';
import { Network } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import { networkForest, type NetTree } from '@/lib/networkTree';
import type { SharedProps } from '@/types';

type NodeRow = { id: number; parent_id: number | null; kind: string; name: string; area_en: string | null; area_ar: string | null; notes: string | null };

export default function NetworkIndex({ nodes, areas, kinds, can_manage }: { nodes: NodeRow[]; areas: { id: number; name_en: string; name_ar: string }[]; kinds: string[]; can_manage: boolean }) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const form = useForm({ parent_id: '', kind: 'olt', name: '', area_id: '', notes: '' });
    const forest = useMemo(() => networkForest(nodes), [nodes]);
    const [selectedId, setSelectedId] = useState<number | null>(forest[0]?.id ?? null);
    const selected = nodes.find((node) => node.id === selectedId) ?? null;
    const children = nodes.filter((node) => node.parent_id === selectedId);

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            parent_id: data.parent_id === '' ? null : Number(data.parent_id),
            area_id: data.area_id === '' ? null : Number(data.area_id),
        }));
        form.post('/network/nodes', { preserveScroll: true, onSuccess: () => form.reset('name', 'notes') });
    };

    return (
        <AppShell>
            <Head title={t('nodes.title')} />
            <PageHeader title={t('nodes.title')} sub={t('nodes.inventory')} />
            <p className="mb-4 text-sm text-muted">{t('nodes.not_live')}</p>
            {page.props.flash.status ? <p className="mb-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
            <div data-testid="network-page">
                {nodes.length === 0 ? <div className="card"><EmptyState icon={<Network className="size-5" />} title={t('nodes.empty')} body={t('nodes.inventory')} /></div> : (
                    <div className="grid items-start gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(240px,320px)]">
                        <div className="card p-3" data-testid="network-tree">
                            {forest.map((node) => <NodeBranch key={node.id} node={node} depth={0} selectedId={selectedId} onSelect={setSelectedId} />)}
                        </div>
                        <aside className="card p-4" data-testid="network-detail">
                            {selected ? (
                                <>
                                    <h2 className="text-lg font-semibold">{selected.name}</h2>
                                    <p className="mt-1 text-sm text-muted">{t(`nodes.kind_${selected.kind}`)}</p>
                                    <p className="mt-2 text-sm">{(locale === 'ar' ? selected.area_ar : selected.area_en) || t('nodes.no_area')}</p>
                                    <p className="mt-2 text-sm text-muted">{selected.notes || t('nodes.no_notes')}</p>
                                    <h3 className="mt-4 text-sm font-semibold">{t('nodes.children')}</h3>
                                    {children.length === 0 ? <p className="mt-1 text-sm text-muted">{t('nodes.no_children')}</p> : (
                                        <ul className="mt-1 text-sm">
                                            {children.map((child) => <li key={child.id}><button type="button" className="min-h-11 text-accent-ink" onClick={() => setSelectedId(child.id)}>{child.name}</button></li>)}
                                        </ul>
                                    )}
                                </>
                            ) : <p className="text-sm text-muted">{t('nodes.pick')}</p>}
                        </aside>
                    </div>
                )}
                {can_manage ? (
                    <form className="card mt-4 grid gap-3 p-5 md:grid-cols-2" onSubmit={save}>
                        <Field id="node-name" label={t('nodes.name')}><Input id="node-name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /></Field>
                        <Field id="node-kind" label={t('nodes.kind')}>
                            <select id="node-kind" className="h-11 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.kind} onChange={(event) => form.setData('kind', event.target.value)}>
                                {kinds.map((kind) => <option key={kind} value={kind}>{t(`nodes.kind_${kind}`)}</option>)}
                            </select>
                        </Field>
                        <Field id="node-parent" label={t('nodes.parent')}>
                            <select id="node-parent" className="h-11 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.parent_id} onChange={(event) => form.setData('parent_id', event.target.value)}>
                                <option value="">{t('filters.any')}</option>
                                {nodes.map((node) => <option key={node.id} value={node.id}>{node.name}</option>)}
                            </select>
                        </Field>
                        <Field id="node-area" label={t('customers.area')}>
                            <select id="node-area" className="h-11 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.area_id} onChange={(event) => form.setData('area_id', event.target.value)}>
                                <option value="">{t('filters.any')}</option>
                                {areas.map((area) => <option key={area.id} value={area.id}>{locale === 'ar' ? area.name_ar : area.name_en}</option>)}
                            </select>
                        </Field>
                        <div className="md:col-span-2"><Button type="submit">{t('nodes.add')}</Button></div>
                    </form>
                ) : null}
            </div>
        </AppShell>
    );
}

function NodeBranch({ node, depth, selectedId, onSelect }: { node: NetTree<NodeRow>; depth: number; selectedId: number | null; onSelect: (id: number) => void }) {
    const { t } = useI18n();

    return (
        <div style={{ paddingInlineStart: depth === 0 ? 0 : 16 }}>
            <button type="button" className="flex min-h-11 w-full items-center justify-between gap-2 rounded-tile px-2 text-start text-sm" aria-pressed={selectedId === node.id} onClick={() => onSelect(node.id)}>
                <span className="font-semibold">{node.name}</span>
                <span className="text-muted">{t(`nodes.kind_${node.kind}`)}</span>
            </button>
            {node.children.map((child) => <NodeBranch key={child.id} node={child} depth={depth + 1} selectedId={selectedId} onSelect={onSelect} />)}
        </div>
    );
}
