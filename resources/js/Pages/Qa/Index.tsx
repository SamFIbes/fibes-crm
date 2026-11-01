import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ClipboardCheck } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Evaluation = {
    id: number;
    agent_id: number;
    subject_type: string;
    subject_id: number;
    total: string;
    critical_fail: boolean;
    status: string;
    form_version: number;
    notes: string | null;
    href: string;
};

type Kpi = { value: string | null; hrefs: string[] };
type Calibration = { subject_type: string; subject_id: number; left: string; right: string; variance: string };

export default function QaIndex({
    evaluations,
    form,
    scripts,
    kpis,
    status_time,
    calibrations,
    from,
    to,
    agent_id,
    agents,
    can_score,
    can_revise,
    own,
}: {
    evaluations: Evaluation[];
    form: { id: number; version: number; name_en: string; name_ar: string } | null;
    scripts: { id: number; call_type: string; version: number; name_en: string; name_ar: string }[];
    kpis: Record<string, Kpi>;
    status_time: Record<string, number>;
    calibrations: Calibration[];
    from: string;
    to: string;
    agent_id: number;
    agents: { id: number; name: string }[];
    can_score: boolean;
    can_revise: boolean;
    own: number;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const status = useForm({ status: 'available' });
    const period = useForm({ from, to, agent: String(agent_id) });

    return (
        <AppShell>
            <Head title={t('qa.title')} />
            <div data-testid="qa-page">
                <PageHeader
                    title={t('qa.title')}
                    sub={t('qa.subtitle')}
                    actions={can_score ? <Button type="button" onClick={() => router.post('/qa/sample')}>{t('qa.sample')}</Button> : null}
                />
                {page.props.flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                <p className="mb-4 text-sm text-muted">{form ? (locale === 'ar' ? form.name_ar : form.name_en) : ''} · v{form?.version ?? 1}</p>
                {can_revise && form ? <Button type="button" className="mb-4" variant="outline" onClick={() => router.post('/qa/forms', { criteria: [{ key: 'greeting', label_en: 'Greeting', label_ar: 'الترحيب', weight: 40, critical: false }, { key: 'compliance', label_en: 'Compliance', label_ar: 'الالتزام', weight: 60, critical: true }] })}>{t('qa.revise')}</Button> : null}
                <form
                    className="mb-4 flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get('/qa', { from: period.data.from, to: period.data.to, agent: period.data.agent }, { preserveState: true });
                    }}
                >
                    <label className="text-sm">
                        {t('qa.from')}
                        <input className="ms-2 h-10 rounded-ctl border border-input bg-card px-2" type="date" value={period.data.from} onChange={(event) => period.setData('from', event.target.value)} />
                    </label>
                    <label className="text-sm">
                        {t('qa.to')}
                        <input className="ms-2 h-10 rounded-ctl border border-input bg-card px-2" type="date" value={period.data.to} onChange={(event) => period.setData('to', event.target.value)} />
                    </label>
                    {agents.length > 0 ? (
                        <label className="text-sm">
                            {t('qa.agent')}
                            <select className="ms-2 h-10 rounded-ctl border border-input bg-card px-2" value={period.data.agent} onChange={(event) => period.setData('agent', event.target.value)} aria-label={t('qa.agent')}>
                                {agents.map((agent) => <option key={agent.id} value={agent.id}>{agent.name}</option>)}
                            </select>
                        </label>
                    ) : null}
                    <Button type="submit" size="sm" variant="outline">{t('qa.apply')}</Button>
                </form>
                <section className="card mb-4 p-4" data-testid="qa-kpis">
                    <h2 className="text-sm font-semibold">{t('qa.kpis')}</h2>
                    <div className="mt-3 grid gap-2 sm:grid-cols-2">
                        {Object.entries(kpis).map(([key, row]) => (
                            <p key={key} className="text-sm">
                                {t(`qa.kpi_${key}`)}: <span dir="ltr">{row.value === null ? t('qa.na') : row.value}</span>
                                {row.hrefs.map((href) => (
                                    <a key={href} className="ms-2 font-semibold text-accent-ink" href={href} data-testid={`kpi-link-${key}`}>{t('qa.open')}</a>
                                ))}
                            </p>
                        ))}
                    </div>
                </section>
                <section className="card mb-4 p-4" data-testid="qa-status-time">
                    <h2 className="text-sm font-semibold">{t('qa.status_time')}</h2>
                    <div className="mt-3 grid gap-2 sm:grid-cols-2">
                        {Object.entries(status_time).map(([key, seconds]) => (
                            <p key={key} className="text-sm">{t(`qa.status_${key}`)}: <span dir="ltr">{seconds}</span></p>
                        ))}
                    </div>
                </section>
                {calibrations.length > 0 ? (
                    <section className="card mb-4 p-4" data-testid="qa-calibration">
                        <h2 className="text-sm font-semibold">{t('qa.calibration')}</h2>
                        {calibrations.map((row) => (
                            <p key={`${row.subject_type}-${row.subject_id}`} className="mt-2 text-sm">
                                {t(`qa.subject_${row.subject_type}`)} #{row.subject_id}: <span dir="ltr">{row.left}% / {row.right}%</span> · {t('qa.variance')} <span dir="ltr">{row.variance}</span>
                            </p>
                        ))}
                    </section>
                ) : null}
                <form className="mb-4 flex flex-wrap items-center gap-2" onSubmit={(event) => { event.preventDefault(); status.post('/qa/status'); }}>
                    <select className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={status.data.status} onChange={(event) => status.setData('status', event.target.value)} aria-label={t('qa.status')}>
                        {['available', 'on_call', 'break', 'training', 'offline', 'acw'].map((item) => <option key={item} value={item}>{t(`qa.status_${item}`)}</option>)}
                    </select>
                    <Button type="submit" size="sm" variant="outline">{t('qa.status')}</Button>
                </form>
                <section className="mb-4" data-testid="qa-scripts">
                    {scripts.map((script) => <p key={script.id} className="text-sm text-muted">{locale === 'ar' ? script.name_ar : script.name_en} · v{script.version}</p>)}
                </section>
                {evaluations.length === 0 ? <div className="card"><EmptyState icon={<ClipboardCheck className="size-5" />} title={t('qa.empty')} body={t('qa.subtitle')} /></div> : (
                    <div className="space-y-3">
                        {evaluations.map((row) => (
                            <article key={row.id} className="card p-4" data-testid="qa-row" data-status={row.status}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <a className="text-sm font-semibold text-accent-ink" href={row.href}>{t(`qa.subject_${row.subject_type}`)} #{row.subject_id}</a>
                                        <p className="text-sm" dir="ltr">{row.total}% · v{row.form_version}</p>
                                        {row.notes ? <p className="mt-1 text-sm text-muted">{t('qa.notes')}: {row.notes}</p> : null}
                                    </div>
                                    <Badge tone={row.critical_fail ? 'bad' : 'good'}>{row.critical_fail ? t('qa.critical') : t(`qa.status_${row.status}`)}</Badge>
                                </div>
                                {row.status === 'submitted' && row.agent_id === own ? <Button className="mt-3" type="button" size="sm" onClick={() => router.post(`/qa/${row.id}/acknowledge`)}>{t('qa.acknowledge')}</Button> : null}
                            </article>
                        ))}
                    </div>
                )}
            </div>
        </AppShell>
    );
}
