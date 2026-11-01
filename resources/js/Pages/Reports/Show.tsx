import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Row = { label: string; href: string };

export default function ReportShow({
    report,
    area,
    value,
    rows,
    from,
    to,
    can_export,
    drill,
}: {
    report: string;
    area: string;
    value: string;
    rows: Row[];
    from: string;
    to: string;
    can_export: boolean;
    drill: string;
}) {
    const { t } = useI18n();
    const page = usePage<SharedProps>();
    const schedule = useForm({ cadence: 'daily', hour: '8', weekday: '1' });

    return (
        <AppShell>
            <Head title={t(`reports.key_${report}`)} />
            <div data-testid="report-page">
                <PageHeader title={t(`reports.key_${report}`)} sub={t(`reports.area_${area}`)} actions={<a className="text-sm font-semibold text-accent-ink" href={drill}>{t('reports.drill')}</a>} />
                {page.props.flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                {page.props.flash.link ? <p className="mb-4 break-all text-sm" dir="ltr"><a className="font-semibold text-accent-ink" href={page.props.flash.link} data-testid="export-link">{t('reports.download')}</a></p> : null}
                <p className="mb-4 text-3xl font-semibold" dir="ltr" data-testid="report-value">{value}</p>
                {can_export ? (
                    <div className="mb-4 flex flex-wrap gap-2">
                        {(['csv', 'xlsx', 'pdf'] as const).map((format) => (
                            <Button key={format} type="button" size="sm" variant="outline" onClick={() => router.post(`/reports/${report}/export`, { format, from, to })}>{format.toUpperCase()}</Button>
                        ))}
                    </div>
                ) : null}
                <ul className="mb-4 space-y-2">
                    {rows.map((row) => (
                        <li key={`${row.href}-${row.label}`} className="card px-4 py-3 text-sm"><a className="font-semibold text-accent-ink" href={row.href} data-testid="report-row">{row.label}</a></li>
                    ))}
                </ul>
                <form className="card flex flex-wrap items-end gap-2 p-4" onSubmit={(event) => { event.preventDefault(); schedule.post(`/reports/${report}/schedule`); }}>
                    <h2 className="w-full text-sm font-semibold">{t('reports.schedule')}</h2>
                    <select className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={schedule.data.cadence} onChange={(event) => schedule.setData('cadence', event.target.value)} aria-label={t('reports.schedule')}>
                        <option value="daily">{t('reports.daily')}</option>
                        <option value="weekly">{t('reports.weekly')}</option>
                    </select>
                    <input className="h-10 w-20 rounded-ctl border border-input bg-card px-2 text-sm" dir="ltr" value={schedule.data.hour} onChange={(event) => schedule.setData('hour', event.target.value)} aria-label={t('reports.hour')} />
                    <Button type="submit" size="sm">{t('reports.save_schedule')}</Button>
                </form>
            </div>
        </AppShell>
    );
}
