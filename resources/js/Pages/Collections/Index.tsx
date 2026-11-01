import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type CaseRow = {
    id: number;
    customer_id: number;
    customer: string | null;
    customer_ar: string | null;
    stage: string;
    amount_due: string;
    bucket: string;
    promise: string | null;
    invoice_id: number | null;
};

type Renewal = {
    id: number;
    customer_id: number;
    customer: string | null;
    customer_ar: string | null;
    ends: string | null;
    auto_renew: boolean;
    price: string;
};

export default function CollectionsIndex({
    cases,
    renewals,
    can_suspend,
    can_write_off,
}: {
    cases: CaseRow[];
    renewals: Renewal[];
    can_suspend: boolean;
    can_request: boolean;
    can_write_off: boolean;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const promise = useForm({ date: '', amount: '' });

    return (
        <AppShell>
            <Head title={t('collections.title')} />
            <div data-testid="collections-page">
                <PageHeader title={t('collections.title')} sub={t('collections.subtitle')} actions={can_suspend ? <Button type="button" onClick={() => router.post('/collections/run')}>{t('collections.run')}</Button> : null} />
                {page.props.flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                {cases.length === 0 ? <div className="card"><EmptyState icon={<ClipboardList className="size-5" />} title={t('collections.empty')} body={t('collections.subtitle')} /></div> : (
                    <div className="space-y-3">
                        {cases.map((row) => (
                            <article key={row.id} className="card p-4" data-testid="collection-card" data-stage={row.stage}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 className="text-lg font-semibold">{locale === 'ar' ? row.customer_ar || row.customer : row.customer}</h2>
                                        <p className="text-sm text-muted" dir="ltr">${row.amount_due} · {row.bucket}</p>
                                        {row.promise ? <p className="text-sm" dir="ltr">{row.promise}</p> : null}
                                    </div>
                                    <Badge tone={row.stage === 'paid' ? 'good' : row.stage === 'suspended' || row.stage === 'broken' ? 'bad' : 'warn'}>{t(`collections.stage_${row.stage}`)}</Badge>
                                </div>
                                {can_suspend ? (
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); promise.post(`/collections/${row.id}/promise`); }}>
                                            <Field id={`date-${row.id}`} label={t('collections.promise')}><Input id={`date-${row.id}`} type="date" dir="ltr" value={promise.data.date} onChange={(event) => promise.setData('date', event.target.value)} /></Field>
                                            <Field id={`amount-${row.id}`} label={t('collections.amount')}><Input id={`amount-${row.id}`} dir="ltr" value={promise.data.amount} onChange={(event) => promise.setData('amount', event.target.value)} /></Field>
                                            <Button type="submit" size="sm" variant="outline">{t('collections.promise')}</Button>
                                        </form>
                                        <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/collections/${row.id}/suspend`)}>{t('collections.suspend')}</Button>
                                        <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/collections/${row.id}/terminate`)}>{t('collections.terminate')}</Button>
                                        <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/collections/${row.id}/reactivate`)}>{t('collections.reactivate')}</Button>
                                        {can_write_off && row.invoice_id ? <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/collections/${row.id}/write-off`)}>{t('collections.write_off')}</Button> : null}
                                    </div>
                                ) : null}
                            </article>
                        ))}
                    </div>
                )}
                <section className="card mt-6 p-4" data-testid="renewals">
                    <h2 className="text-sm font-semibold">{t('collections.renewals')}</h2>
                    {renewals.length === 0 ? <p className="mt-2 text-sm text-muted">{t('collections.empty')}</p> : renewals.map((row) => (
                        <p key={row.id} className="mt-2 text-sm" dir="ltr">{locale === 'ar' ? row.customer_ar || row.customer : row.customer} · {row.ends} · ${row.price}{row.auto_renew ? '' : ` · ${t('collections.renewal_task')}`}</p>
                    ))}
                </section>
            </div>
        </AppShell>
    );
}
