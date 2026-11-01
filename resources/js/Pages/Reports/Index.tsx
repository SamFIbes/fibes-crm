import { Head, Link, router, useForm } from '@inertiajs/react';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';

type Report = { key: string; area: string; value: string; href: string };

export default function ReportsIndex({ reports, from, to }: { reports: Report[]; from: string; to: string }) {
    const { t } = useI18n();
    const range = useForm({ from, to });

    return (
        <AppShell>
            <Head title={t('reports.title')} />
            <div data-testid="reports-page">
                <PageHeader title={t('reports.title')} sub={t('reports.subtitle')} />
                <form className="mb-4 flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); router.get('/reports', range.data, { preserveState: true }); }}>
                    <label className="text-sm">{t('reports.from')}<input className="ms-2 h-10 rounded-ctl border border-input bg-card px-2" type="date" value={range.data.from} onChange={(event) => range.setData('from', event.target.value)} /></label>
                    <label className="text-sm">{t('reports.to')}<input className="ms-2 h-10 rounded-ctl border border-input bg-card px-2" type="date" value={range.data.to} onChange={(event) => range.setData('to', event.target.value)} /></label>
                    <Button type="submit" size="sm" variant="outline">{t('reports.apply')}</Button>
                </form>
                <div className="grid gap-3 sm:grid-cols-2">
                    {reports.map((report) => (
                        <Link key={report.key} href={report.href} className="card p-4" data-testid="report-card">
                            <p className="text-xs uppercase tracking-[0.12em] text-faint">{t(`reports.area_${report.area}`)}</p>
                            <p className="mt-1 text-sm font-semibold">{t(`reports.key_${report.key}`)}</p>
                            <p className="mt-2 text-xl font-semibold" dir="ltr">{report.value}</p>
                        </Link>
                    ))}
                </div>
            </div>
        </AppShell>
    );
}
