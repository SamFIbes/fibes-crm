import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { ClipboardCheck } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { bulkAllowed } from '@/lib/approvals';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Item = {
    id: number;
    type: string;
    name_en: string | null;
    name_ar: string | null;
    summary: string | null;
    status: string;
    subject_state: string | null;
    requester: string | null;
    mine: boolean;
    can_decide: boolean;
    low_risk: boolean;
    due_at: string | null;
    comment: string | null;
    step: number;
    steps: number;
};

export default function ApprovalsIndex({
    items,
    policies,
    can_decide,
}: {
    items: Item[];
    waiting: number;
    policies: { id: number; key: string; name_en: string; name_ar: string; timeout_hours: number; low_risk: boolean }[];
    can_decide: boolean;
    can_manage: boolean;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const [filter, setFilter] = useState<'all' | 'mine'>('all');
    const [picked, setPicked] = useState<number[]>([]);
    const shown = items.filter((item) => (filter === 'mine' ? item.mine : true));
    const openCount = shown.filter((item) => item.status === 'pending').length;
    const canBulk = bulkAllowed(shown.filter((item) => picked.includes(item.id)).map((item) => ({ low_risk: item.low_risk, selected: true })));

    return (
        <AppShell>
            <Head title={t('approvals.title')} />
            <div data-testid="approvals-page">
                <PageHeader title={t('approvals.title')} sub={t('approvals.subtitle')} />
                {page.props.flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <Badge tone={openCount > 0 ? 'warn' : 'neutral'}>{openCount}</Badge>
                    <Button type="button" size="sm" variant={filter === 'all' ? 'default' : 'outline'} onClick={() => setFilter('all')}>{t('approvals.all')}</Button>
                    <Button type="button" size="sm" variant={filter === 'mine' ? 'default' : 'outline'} onClick={() => setFilter('mine')}>{t('approvals.mine')}</Button>
                    {can_decide ? (
                        <Button type="button" size="sm" variant="outline" disabled={!canBulk} onClick={() => router.post('/approvals/bulk', { ids: picked }, { preserveScroll: true })}>{t('approvals.bulk')}</Button>
                    ) : null}
                </div>
                {shown.length === 0 ? <div className="card"><EmptyState icon={<ClipboardCheck className="size-5" />} title={t('approvals.empty')} body={t('approvals.subtitle')} /></div> : null}
                <div className="space-y-3">
                    {shown.map((item) => (
                        <article key={item.id} className="card p-4" data-testid="approval-row">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p className="text-xs text-faint">{locale === 'ar' ? item.name_ar : item.name_en}</p>
                                    <h2 className="text-lg font-semibold">{item.summary}</h2>
                                    <p className="text-sm text-muted">{item.requester} · {t('approvals.step', { step: item.step, steps: item.steps || 1 })}{item.due_at ? ` · ${item.due_at}` : ''}</p>
                                </div>
                                <Badge tone={item.status === 'approved' ? 'good' : item.status === 'rejected' ? 'bad' : 'warn'}>{t(`approvals.status_${item.status}`)}</Badge>
                            </div>
                            {item.comment ? <p className="mt-2 text-sm">{item.comment}</p> : null}
                            {can_decide && item.can_decide ? <Decision id={item.id} /> : null}
                            {item.low_risk && item.status === 'pending' ? (
                                <label className="mt-3 flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={picked.includes(item.id)} onChange={(event) => setPicked((current) => event.target.checked ? [...current, item.id] : current.filter((id) => id !== item.id))} />
                                    {t('approvals.bulk')}
                                </label>
                            ) : null}
                        </article>
                    ))}
                </div>
                {policies.length > 0 ? (
                    <section className="mt-8">
                        <h2 className="mb-2 text-sm font-semibold">{t('approvals.rules')}</h2>
                        <ul className="card divide-y divide-line">
                            {policies.map((policy) => (
                                <li key={policy.id} className="flex items-center justify-between px-4 py-3 text-sm">
                                    <span>{locale === 'ar' ? policy.name_ar : policy.name_en}</span>
                                    <span className="text-muted" dir="ltr">{t('approvals.hours', { hours: policy.timeout_hours })}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}
            </div>
        </AppShell>
    );
}

function Decision({ id }: { id: number }) {
    const { t } = useI18n();
    const form = useForm({ comment: '' });
    const send = (path: string) => {
        form.post(path, { preserveScroll: true, onSuccess: () => form.reset('comment') });
    };

    return (
        <form className="mt-3 flex flex-wrap items-end gap-2" onSubmit={(event: FormEvent) => event.preventDefault()}>
            <Field id={`comment-${id}`} label={t('approvals.comment')} error={form.errors.comment}>
                <Input id={`comment-${id}`} value={form.data.comment} onChange={(event) => form.setData('comment', event.target.value)} />
            </Field>
            <Button type="button" size="sm" onClick={() => send(`/approvals/${id}/approve`)}>{t('approvals.approve')}</Button>
            <Button type="button" size="sm" variant="outline" onClick={() => send(`/approvals/${id}/reject`)}>{t('approvals.reject')}</Button>
        </form>
    );
}
