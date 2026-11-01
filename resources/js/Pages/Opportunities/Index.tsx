import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { Kanban } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { PageSkeleton } from '@/components/feedback/PageSkeleton';
import { Field } from '@/components/form/Field';
import { KanbanBoard } from '@/components/kanban/Board';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { CoverageMap } from '@/components/maps/CoverageMap';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Deal = {
    id: number;
    number: string | null;
    name: string | null;
    name_ar?: string | null;
    stage: string | null;
    stage_en?: string | null;
    stage_ar?: string | null;
    service: string | null;
    owner: string | null;
    mrc: string | null;
    mrc_lbp: string | null;
    currency: string;
    stale: boolean;
    probability: number;
    coverage_note?: string | null;
    latitude?: string | number | null;
    longitude?: string | number | null;
    account_no?: string | null;
    customer_status?: string | null;
};
type Stage = { id: number; key: string; name_en: string; name_ar: string; is_won: boolean; is_lost: boolean };
type Detail = Deal & {
    history: { id: number; stage: string | null; seconds: number }[];
    tasks: { id: number; title: string; due_at: string | null; status: string }[];
};

export default function OpportunitiesIndex({
    pipeline, pipelines, stages, deals, funnel, detail, view, reasons, people, can_edit, can_close,     can_reassign,
    tiles,
}: {
    pipeline: string;
    pipelines: { key: string; name_en: string; name_ar: string }[];
    stages: Stage[];
    deals: Deal[];
    funnel: { key: string; name_en: string; name_ar: string; count: number; conversion: number }[];
    detail: Detail | null;
    view: string;
    saved: string;
    reasons: { id: number; key: string; name_en: string; name_ar: string }[];
    areas: { id: number; name_en: string; name_ar: string }[];
    people: { id: number; name: string }[];
    services: string[];
    can_edit: boolean;
    can_close: boolean;
    can_reassign: boolean;
    tiles: string;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const [loading, setLoading] = useState(false);
    const move = useForm({ stage: '', coverage_note: '', loss_reason_id: '', override_reason: '', competitor: '' });
    const bulk = useForm({ ids: [] as number[], assignee_id: '' });

    const visit = (next: Record<string, string>) => {
        setLoading(true);
        router.get('/opportunities', { pipeline, view, ...next }, {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setLoading(false),
        });
    };

    const stageName = (stage: Stage) => (locale === 'ar' ? stage.name_ar : stage.name_en);
    const columns = stages.filter((stage) => !stage.is_lost).map((stage) => ({
        id: stage.key,
        title: stageName(stage),
        items: deals.filter((deal) => deal.stage === stage.key).map((deal) => ({ ...deal, id: String(deal.id) })),
    }));

    const sendMove = (event: FormEvent) => {
        event.preventDefault();
        if (!detail) return;
        move.post(`/opportunities/${detail.id}/stage`, { preserveScroll: true });
    };

    return (
        <AppShell>
            <Head title={t('opportunities.title')} />
            {loading ? <PageSkeleton /> : null}
            <div data-testid="opportunities-page">
                <PageHeader title={t('opportunities.title')} sub={t('opportunities.subtitle')} />
                {page.props.flash.status ? <p className="mb-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                <div className="mb-3 flex flex-wrap gap-2">
                    {pipelines.map((item) => (
                        <Button key={item.key} type="button" size="sm" variant={pipeline === item.key ? 'default' : 'outline'} onClick={() => visit({ pipeline: item.key })}>
                            {locale === 'ar' ? item.name_ar : item.name_en}
                        </Button>
                    ))}
                    <Button type="button" size="sm" variant={view === 'board' ? 'default' : 'outline'} onClick={() => visit({ view: 'board' })}>{t('opportunities.board')}</Button>
                    <Button type="button" size="sm" variant={view === 'list' ? 'default' : 'outline'} onClick={() => visit({ view: 'list' })}>{t('opportunities.list')}</Button>
                    {(['mine', 'won', 'lost', 'stale'] as const).map((item) => (
                        <Button key={item} type="button" size="sm" variant="outline" onClick={() => visit({ saved: item })}>{t(`opportunities.${item}`)}</Button>
                    ))}
                </div>
                <div className="mb-4 flex gap-2 overflow-x-auto text-sm" data-testid="funnel">
                    {funnel.map((item) => (
                        <span key={item.key} className="rounded-full bg-card px-3 py-1 ring-1 ring-line">
                            {locale === 'ar' ? item.name_ar : item.name_en} <span dir="ltr">{item.count}</span>
                        </span>
                    ))}
                </div>
                {view === 'list' ? (
                    deals.length === 0 ? <div className="card"><EmptyState icon={<Kanban className="size-5" />} title={t('opportunities.empty')} body={t('opportunities.subtitle')} /></div> : (
                        <ul className="card divide-y divide-line">
                            {deals.map((deal) => (
                                <li key={deal.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                                    <button type="button" className="text-start" onClick={() => visit({ deal: String(deal.id) })}>
                                        <span className="font-semibold">{locale === 'ar' ? deal.name_ar || deal.name : deal.name}</span>
                                        <span className="ms-2 text-xs text-muted" dir="ltr">{deal.number}</span>
                                    </button>
                                    <span className="flex items-center gap-2">
                                        {deal.stale ? <Badge tone="warn">{t('opportunities.stale')}</Badge> : null}
                                        <span dir="ltr">{deal.mrc ? `$${deal.mrc}` : ''}</span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )
                ) : (
                    <KanbanBoard
                        columns={columns}
                        empty={t('opportunities.empty')}
                        onMove={can_edit ? (id, stage) => router.post(`/opportunities/${id}/stage`, { stage }, { preserveScroll: true }) : undefined}
                        renderCard={(item) => (
                            <button type="button" className="w-full text-start" data-testid="opportunity-card" onClick={() => visit({ deal: item.id })}>
                                <p className="font-semibold">{locale === 'ar' ? item.name_ar || item.name : item.name}</p>
                                <p className="text-xs text-muted" dir="ltr">{item.number}{item.mrc ? ` · $${item.mrc}` : ''}{item.mrc_lbp ? ` · ${Number(item.mrc_lbp).toLocaleString()} LBP` : ''}</p>
                                {item.stale ? <Badge tone="warn">{t('opportunities.stale')}</Badge> : null}
                            </button>
                        )}
                    />
                )}
                {detail ? (
                    <section className="card mt-4 space-y-3 p-5" data-testid="opportunity-drawer">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="text-xl font-semibold">{locale === 'ar' ? detail.name_ar || detail.name : detail.name}</h2>
                                <p className="text-sm text-muted" dir="ltr">{detail.number} · {detail.account_no}</p>
                                {detail.customer_status === 'prospect' ? <Badge>{t('opportunities.prospect')}</Badge> : null}
                            </div>
                            {detail.stale ? <Badge tone="warn">{t('opportunities.stale')}</Badge> : null}
                        </div>
                        <p className="text-sm text-muted">{t('opportunities.quotes')}</p>
                        <p className="text-sm text-muted">{t('opportunities.surveys')}</p>
                        <p className="text-sm text-muted">{t('opportunities.files')}</p>
                        {can_edit ? (
                            <form className="grid gap-3 md:grid-cols-2" onSubmit={sendMove}>
                                <Field id="stage" label={t('opportunities.save')}>
                                    <select id="stage" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={move.data.stage} onChange={(event) => move.setData('stage', event.target.value)}>
                                        <option value="" />
                                        {stages.map((stage) => <option key={stage.id} value={stage.key}>{stageName(stage)}</option>)}
                                    </select>
                                </Field>
                                {move.errors.coverage_note ? <p className="text-sm text-bad md:col-span-2" role="alert">{move.errors.coverage_note}</p> : null}
                                {detail.latitude && detail.longitude ? (
                                    <div className="md:col-span-2">
                                        <CoverageMap tileUrl={tiles} height={220} pins={[{ id: detail.id, lat: Number(detail.latitude), lng: Number(detail.longitude), label: detail.name || detail.number || '' }]} />
                                    </div>
                                ) : null}
                                {can_close ? (
                                    <>
                                        <Field id="override" label={t('opportunities.override')} error={move.errors.override_reason}>
                                            <Input id="override" value={move.data.override_reason} onChange={(event) => move.setData('override_reason', event.target.value)} />
                                        </Field>
                                        <Field id="loss" label={t('opportunities.reason')} error={move.errors.loss_reason_id}>
                                            <select id="loss" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={move.data.loss_reason_id} onChange={(event) => move.setData('loss_reason_id', event.target.value)}>
                                                <option value="" />
                                                {reasons.map((reason) => <option key={reason.id} value={reason.id}>{locale === 'ar' ? reason.name_ar : reason.name_en}</option>)}
                                            </select>
                                        </Field>
                                    </>
                                ) : null}
                                <div><Button type="submit">{t('opportunities.save')}</Button></div>
                            </form>
                        ) : null}
                        {can_reassign ? (
                            <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => {
                                event.preventDefault();
                                router.post('/opportunities/reassign', { ids: [detail.id], assignee_id: bulk.data.assignee_id }, { preserveScroll: true });
                            }}>
                                <Field id="who" label={t('opportunities.reassign')}>
                                    <select id="who" className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={bulk.data.assignee_id} onChange={(event) => bulk.setData('assignee_id', event.target.value)}>
                                        <option value="" />
                                        {people.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                                    </select>
                                </Field>
                                <Button type="submit" variant="outline">{t('opportunities.reassign')}</Button>
                            </form>
                        ) : null}
                        <div>
                            <h3 className="text-sm font-semibold">{t('opportunities.history')}</h3>
                            <ul className="mt-1 text-sm text-muted">
                                {detail.history.map((row) => <li key={row.id} dir="ltr">{row.stage} · {row.seconds}s</li>)}
                            </ul>
                        </div>
                        <div>
                            <h3 className="text-sm font-semibold">{t('opportunities.tasks')}</h3>
                            {detail.tasks.length === 0 ? <p className="text-sm text-muted">{t('opportunities.empty')}</p> : (
                                <ul className="text-sm">{detail.tasks.map((task) => <li key={task.id}>{task.title} <span dir="ltr">{task.due_at}</span></li>)}</ul>
                            )}
                        </div>
                    </section>
                ) : null}
            </div>
        </AppShell>
    );
}
