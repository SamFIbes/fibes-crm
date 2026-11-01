import { Head, useForm, usePage } from '@inertiajs/react';
import { HeartHandshake } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Row = {
    id: number;
    customer: string | null;
    customer_ar: string | null;
    stage: string;
    reason: string | null;
    score: number;
};

export default function RetentionIndex({
    cases,
    reasons,
    breakdown,
    report,
    can_offer,
    can_request,
}: {
    cases: Row[];
    reasons: string[];
    breakdown: Record<string, number>;
    report: { saved: number; closed: number; rate: string };
    can_offer: boolean;
    can_request: boolean;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const offer = useForm({ kind: 'free_month', months: '1', percent: '0', product_id: '' });
    const decline = useForm({ reason: reasons[0] ?? 'price' });

    return (
        <AppShell>
            <Head title={t('retention.title')} />
            <div data-testid="retention-page">
                <PageHeader title={t('retention.title')} sub={t('retention.subtitle')} />
                {page.props.flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                <p className="mb-4 text-sm" data-testid="save-rate" dir="ltr">{t('retention.save_rate')}: {report.rate}% ({report.saved}/{report.closed})</p>
                {cases.length === 0 ? <div className="card"><EmptyState icon={<HeartHandshake className="size-5" />} title={t('retention.empty')} body={t('retention.subtitle')} /></div> : (
                    <div className="space-y-3">
                        {cases.map((row) => (
                            <article key={row.id} className="card p-4" data-testid="retention-card" data-stage={row.stage}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 className="text-lg font-semibold">{locale === 'ar' ? row.customer_ar || row.customer : row.customer}</h2>
                                        <p className="text-sm text-muted" dir="ltr">{t('retention.score')}: {row.score}</p>
                                    </div>
                                    <Badge tone={row.stage === 'saved' ? 'good' : row.stage === 'declined' || row.stage === 'churned' ? 'bad' : 'warn'}>{t(`retention.stage_${row.stage}`)}</Badge>
                                </div>
                                {can_request && (row.stage === 'open' || row.stage === 'offered') ? (
                                    <div className="mt-3 space-y-3">
                                        <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); offer.post(`/retention/${row.id}/offer`); }}>
                                            <Field id={`kind-${row.id}`} label={t('retention.kind')}>
                                                <select id={`kind-${row.id}`} className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={offer.data.kind} onChange={(event) => offer.setData('kind', event.target.value)}>
                                                    <option value="free_month">{t('retention.free_month')}</option>
                                                    <option value="discount">{t('retention.discount')}</option>
                                                    <option value="upgrade">{t('retention.upgrade')}</option>
                                                </select>
                                            </Field>
                                            <Field id={`months-${row.id}`} label={t('retention.months')}><Input id={`months-${row.id}`} dir="ltr" value={offer.data.months} onChange={(event) => offer.setData('months', event.target.value)} /></Field>
                                            <Field id={`percent-${row.id}`} label={t('retention.percent')}><Input id={`percent-${row.id}`} dir="ltr" value={offer.data.percent} onChange={(event) => offer.setData('percent', event.target.value)} /></Field>
                                            {can_offer || can_request ? <Button type="submit" size="sm">{t('retention.offer')}</Button> : null}
                                        </form>
                                        <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); decline.post(`/retention/${row.id}/decline`); }}>
                                            <Field id={`reason-${row.id}`} label={t('retention.reason_label')}>
                                                <select id={`reason-${row.id}`} className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={decline.data.reason} onChange={(event) => decline.setData('reason', event.target.value)}>
                                                    {reasons.map((reason) => <option key={reason} value={reason}>{t(`retention.reason_${reason}`)}</option>)}
                                                </select>
                                            </Field>
                                            <Button type="submit" size="sm" variant="outline">{t('retention.decline')}</Button>
                                        </form>
                                    </div>
                                ) : null}
                            </article>
                        ))}
                    </div>
                )}
                <section className="card mt-6 p-4" data-testid="reason-breakdown">
                    <h2 className="text-sm font-semibold">{t('retention.breakdown')}</h2>
                    {Object.entries(breakdown).map(([reason, count]) => (
                        <p key={reason} className="mt-2 text-sm">{t(`retention.reason_${reason}`)} · {count}</p>
                    ))}
                </section>
            </div>
        </AppShell>
    );
}
