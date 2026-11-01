import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Network } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type NodeRow = { id: number; parent_id: number | null; kind: string; name: string; area_en: string | null; area_ar: string | null; notes: string | null };

export default function NetworkIndex({ nodes, areas, kinds, can_manage }: { nodes: NodeRow[]; areas: { id: number; name_en: string; name_ar: string }[]; kinds: string[]; can_manage: boolean }) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const form = useForm({ parent_id: '', kind: 'olt', name: '', area_id: '', notes: '' });
    const nameOf = (id: number | null) => nodes.find((node) => node.id === id)?.name;

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
            <PageHeader title={t('nodes.title')} sub={t('nodes.subtitle')} />
            {page.props.flash.status ? <p className="mb-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
            <div data-testid="network-page">
                {nodes.length === 0 ? <div className="card"><EmptyState icon={<Network className="size-5" />} title={t('nodes.empty')} body={t('nodes.subtitle')} /></div> : (
                    <ul className="card divide-y divide-line">
                        {nodes.map((node) => (
                            <li key={node.id} className="px-5 py-3 text-sm">
                                <span className="font-semibold">{node.name}</span>
                                <span className="ms-2 text-muted">{t(`nodes.kind_${node.kind}`)}</span>
                                {node.parent_id ? <span className="ms-2 text-muted">{nameOf(node.parent_id)}</span> : null}
                                {(locale === 'ar' ? node.area_ar : node.area_en) ? <span className="ms-2">{locale === 'ar' ? node.area_ar : node.area_en}</span> : null}
                            </li>
                        ))}
                    </ul>
                )}
                {can_manage ? (
                    <form className="card mt-4 grid gap-3 p-5 md:grid-cols-2" onSubmit={save}>
                        <Field id="node-name" label={t('nodes.name')}><Input id="node-name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /></Field>
                        <Field id="node-kind" label={t('nodes.kind')}>
                            <select id="node-kind" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.kind} onChange={(event) => form.setData('kind', event.target.value)}>
                                {kinds.map((kind) => <option key={kind} value={kind}>{t(`nodes.kind_${kind}`)}</option>)}
                            </select>
                        </Field>
                        <Field id="node-parent" label={t('nodes.parent')}>
                            <select id="node-parent" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.parent_id} onChange={(event) => form.setData('parent_id', event.target.value)}>
                                <option value="">{t('filters.any')}</option>
                                {nodes.map((node) => <option key={node.id} value={node.id}>{node.name}</option>)}
                            </select>
                        </Field>
                        <Field id="node-area" label={t('customers.area')}>
                            <select id="node-area" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.area_id} onChange={(event) => form.setData('area_id', event.target.value)}>
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
