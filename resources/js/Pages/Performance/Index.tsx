import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Trophy } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Entry = { id: number; user_id: number; name: string; kind: string; amount: string; status: string; period: string | null };
type TargetRow = { id: number; metric: string; subject_type: string; value: string; progress: string; period: string };
type Leader = { user_id: number; name: string; total: string; deals: number };

export default function PerformanceIndex({
    entries,
    targets,
    leaders,
    period,
    teams,
    people,
    metrics,
    can_set,
    can_export,
    statement,
    tv_token,
}: {
    entries: Entry[];
    targets: TargetRow[];
    leaders: Leader[];
    period: string;
    teams: { id: number; name_en: string; name_ar: string }[];
    people: { id: number; name: string }[];
    metrics: string[];
    can_set: boolean;
    can_export: boolean;
    statement: string;
    tv_token: string | null;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const target = useForm({
        period: 'month',
        subject_type: 'user',
        subject_id: people[0] ? String(people[0].id) : '',
        subject_key: 'sales_agent',
        metric: 'mrc_added',
        value: '100.00',
        period_start: new Date().toISOString().slice(0, 10),
    });
    const adjustment = useForm({ user_id: people[0] ? String(people[0].id) : '', amount: '', reason: '' });

    return (
        <AppShell>
            <Head title={t('performance.title')} />
            <div data-testid="performance-page">
                <PageHeader
                    title={t('performance.title')}
                    sub={t('performance.subtitle')}
                    actions={can_set ? <Button type="button" onClick={() => router.post('/performance/tv-token')}>{t('performance.tv_link')}</Button> : null}
                />
                {page.props.flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                {tv_token ? <p className="mb-4 break-all rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line" data-testid="tv-token" dir="ltr">/tv/sales?token={tv_token}</p> : null}
                <section className="card mb-4 p-4" data-testid="performance-statement">
                    <h2 className="text-sm font-semibold">{t('performance.statement')}</h2>
                    <p className="mt-2 text-2xl font-semibold" dir="ltr">${statement}</p>
                    {can_export ? <a className="mt-2 inline-block text-sm font-semibold text-accent-ink" href="/performance/export">{t('performance.export')}</a> : null}
                </section>
                <section className="card mb-4 p-4" data-testid="performance-board">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold">{t('performance.board')}</h2>
                        <div className="flex gap-2">
                            {(['day', 'week', 'month'] as const).map((item) => (
                                <Button key={item} type="button" size="sm" variant={period === item ? 'default' : 'outline'} onClick={() => router.get('/performance', { period: item }, { preserveState: true })}>{t(`performance.period_${item}`)}</Button>
                            ))}
                        </div>
                    </div>
                    {leaders.length === 0 ? <p className="mt-3 text-sm text-muted">{t('performance.empty')}</p> : (
                        <ul className="mt-3 space-y-2">
                            {leaders.map((row, index) => (
                                <li key={row.user_id} className="flex items-center justify-between text-sm" data-testid="leader-row">
                                    <span><span dir="ltr">{index + 1}.</span> {row.name}</span>
                                    <span dir="ltr">${row.total}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {teams.length > 0 ? <p className="mt-3 text-xs text-muted">{teams.map((team) => locale === 'ar' ? team.name_ar : team.name_en).join(' · ')}</p> : null}
                </section>
                {can_set ? (
                    <form className="card mb-4 grid gap-3 p-4 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); target.post('/performance/targets'); }}>
                        <h2 className="text-sm font-semibold md:col-span-2">{t('performance.targets')}</h2>
                        <select className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={target.data.metric} onChange={(event) => target.setData('metric', event.target.value)} aria-label={t('performance.metric')}>
                            {metrics.map((metric) => <option key={metric} value={metric}>{t(`performance.metric_${metric}`)}</option>)}
                        </select>
                        <input className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" dir="ltr" value={target.data.value} onChange={(event) => target.setData('value', event.target.value)} aria-label={t('performance.value')} />
                        <select className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={target.data.subject_id} onChange={(event) => target.setData('subject_id', event.target.value)} aria-label={t('performance.person')}>
                            {people.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                        </select>
                        <Button type="submit">{t('performance.save_target')}</Button>
                    </form>
                ) : null}
                {targets.length > 0 ? (
                    <ul className="mb-4 space-y-2">
                        {targets.map((row) => (
                            <li key={row.id} className="card flex items-center justify-between px-4 py-3 text-sm" data-testid="target-row">
                                <span>{t(`performance.metric_${row.metric}`)}</span>
                                <span dir="ltr">{row.progress} / {row.value}</span>
                            </li>
                        ))}
                    </ul>
                ) : null}
                <form className="card mb-4 grid gap-3 p-4 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); adjustment.post('/performance/adjustments'); }}>
                    <h2 className="text-sm font-semibold md:col-span-2">{t('performance.adjustment')}</h2>
                    <select className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={adjustment.data.user_id} onChange={(event) => adjustment.setData('user_id', event.target.value)} aria-label={t('performance.person')}>
                        {people.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                    </select>
                    <input className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" dir="ltr" value={adjustment.data.amount} onChange={(event) => adjustment.setData('amount', event.target.value)} aria-label={t('performance.amount')} />
                    <input className="h-10 rounded-ctl border border-input bg-card px-2 text-sm md:col-span-2" value={adjustment.data.reason} onChange={(event) => adjustment.setData('reason', event.target.value)} aria-label={t('performance.reason')} />
                    <Button type="submit" variant="outline">{t('performance.ask')}</Button>
                </form>
                {entries.length === 0 ? <div className="card"><EmptyState icon={<Trophy className="size-5" />} title={t('performance.empty')} body={t('performance.subtitle')} /></div> : (
                    <div className="space-y-3">
                        {entries.map((row) => (
                            <article key={row.id} className="card flex items-center justify-between p-4" data-testid="commission-row">
                                <div>
                                    <p className="text-sm font-semibold">{row.name}</p>
                                    <p className="text-sm text-muted">{t(`performance.kind_${row.kind}`)}</p>
                                </div>
                                <p className="text-sm font-semibold" dir="ltr">${row.amount}</p>
                            </article>
                        ))}
                    </div>
                )}
            </div>
        </AppShell>
    );
}
