import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Activity } from 'lucide-react';
import { useEffect } from 'react';
import { HomeChart, metricNumber } from '@/components/dashboard/HomeChart';
import { AppShell } from '@/components/shell/AppShell';
import { navGroups } from '@/components/shell/nav';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Tile = { key: string; value: string; href: string };

export default function Dashboard({
    home,
    tiles,
    choices,
    from,
    to,
    refresh,
}: {
    home: string;
    tiles: Tile[];
    choices: string[];
    from: string;
    to: string;
    refresh: number;
}) {
    const { t } = useI18n();
    const { auth } = usePage<SharedProps>().props;
    const name = auth.user?.name ?? '';
    const allowed = new Set(auth.nav ?? []);
    const links = navGroups.flatMap((group) => group.items).filter((item) => item.key !== 'dashboard' && allowed.has(item.key));
    const layout = useForm({ keys: tiles.map((tile) => tile.key), from, to });
    const points = tiles.flatMap((tile) => {
        const value = metricNumber(tile.value);

        return value === null ? [] : [{ label: t(`reports.key_${tile.key}`), value }];
    });

    useEffect(() => {
        const timer = window.setInterval(() => router.reload({ only: ['tiles'] }), Math.max(5, refresh) * 1000);

        return () => window.clearInterval(timer);
    }, [refresh]);

    return (
        <AppShell>
            <Head title={t('dashboard.title')} />
            <div data-testid="dashboard-page" data-role={home}>
                <section className="card mb-4 flex flex-wrap items-center gap-x-8 gap-y-4 px-5 py-4">
                    <div className="flex items-center gap-3">
                        <span className="grid size-10 place-items-center rounded-full bg-brand-gradient text-navy shadow-[var(--glow-mint)]">
                            <Activity className="size-4" />
                        </span>
                        <div>
                            <p className="text-2xs font-bold uppercase tracking-[0.16em] text-accent-ink">{t('dashboard.pulse_title')}</p>
                            <p className="text-sm text-muted">{t('dashboard.greeting', { name })} · {t(`reports.home_${home}`)}</p>
                        </div>
                    </div>
                    {tiles.length === 0 ? <p className="text-sm text-muted">{t('dashboard.pulse_empty')}</p> : null}
                    {tiles.slice(0, 3).map((tile) => (
                        <Link key={`pulse-${tile.key}`} href={tile.href} className="min-w-28">
                            <p className="text-2xs font-semibold uppercase tracking-[0.12em] text-muted">{t(`reports.key_${tile.key}`)}</p>
                            <p className="mt-1 text-2xl font-semibold tabular-nums" dir="ltr">{tile.value}</p>
                        </Link>
                    ))}
                </section>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="dashboard-tiles">
                    {tiles.map((tile, index) => (
                        <Link key={tile.key} href={tile.href} className="card relative overflow-hidden p-4" data-testid={`tile-${tile.key}`}>
                            <span className="kpi-orb" data-tone={String(index % 4)} />
                            <p className="relative text-sm text-muted">{t(`reports.key_${tile.key}`)}</p>
                            <p className="relative mt-2 text-3xl font-semibold tabular-nums" dir="ltr">{tile.value}</p>
                        </Link>
                    ))}
                </div>
                <section className="card mt-4 p-4 sm:p-5">
                    <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold">{t('dashboard.chart')}</h2>
                            <p className="text-xs text-muted" dir="ltr">{from} – {to}</p>
                        </div>
                        <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); router.get('/dashboard', { from: layout.data.from, to: layout.data.to }, { preserveState: true }); }}>
                            <label className="text-xs text-muted">{t('reports.from')}<input className="ms-2 h-9 rounded-ctl border border-[var(--glass-border)] bg-[var(--field-bg)] px-2 text-sm" type="date" value={layout.data.from} onChange={(event) => layout.setData('from', event.target.value)} /></label>
                            <label className="text-xs text-muted">{t('reports.to')}<input className="ms-2 h-9 rounded-ctl border border-[var(--glass-border)] bg-[var(--field-bg)] px-2 text-sm" type="date" value={layout.data.to} onChange={(event) => layout.setData('to', event.target.value)} /></label>
                            <Button type="submit" size="sm" variant="outline">{t('reports.apply')}</Button>
                        </form>
                    </div>
                    {points.length === 0 ? <p className="py-10 text-center text-sm text-muted">{t('dashboard.chart_empty')}</p> : <HomeChart points={points} />}
                </section>
                <section className="card mt-4 overflow-hidden" data-testid="dashboard-queue">
                    <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div>
                            <h2 className="flex items-center gap-2 text-sm font-semibold">
                                <span className="pulse-live size-2 rounded-full bg-mint" />
                                {t('dashboard.queue')}
                            </h2>
                            <p className="mt-1 text-xs text-muted">{t('dashboard.queue_body')}</p>
                        </div>
                        {allowed.has('tickets') ? (
                            <Link href="/tickets" className="text-sm font-semibold text-accent-ink">{t('dashboard.queue_open')}</Link>
                        ) : null}
                    </div>
                    <div className="overflow-x-auto border-t border-line">
                        <table className="w-full min-w-[640px] text-start">
                            <thead>
                                <tr className="text-2xs font-bold uppercase tracking-[0.1em] text-faint">
                                    <th className="px-4 py-3 text-start">{t('tickets.title')}</th>
                                    <th className="px-4 py-3 text-start">{t('tickets.customer')}</th>
                                    <th className="px-4 py-3 text-start">{t('tickets.subject')}</th>
                                    <th className="px-4 py-3 text-start">{t('tickets.priority')}</th>
                                    <th className="px-4 py-3 text-start">{t('tickets.status')}</th>
                                    <th className="px-4 py-3 text-start">{t('tickets.sla')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-sm text-muted">{t('dashboard.queue_empty')}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <details className="card mt-4 p-4">
                    <summary className="cursor-pointer text-sm font-semibold">{t('reports.layout')}</summary>
                    <form className="mt-3" onSubmit={(event) => { event.preventDefault(); layout.post('/dashboard/layout'); }}>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {choices.map((key) => (
                                <label key={key} className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={layout.data.keys.includes(key)} onChange={(event) => layout.setData('keys', event.target.checked ? [...layout.data.keys, key] : layout.data.keys.filter((item) => item !== key))} />
                                    {t(`reports.key_${key}`)}
                                </label>
                            ))}
                        </div>
                        <Button className="mt-3" type="submit" size="sm" variant="outline">{t('reports.save_layout')}</Button>
                    </form>
                </details>
                {links.length > 0 ? (
                    <div className="mt-4">
                        <h2 className="mb-2 text-2xs font-bold uppercase tracking-[0.14em] text-faint">{t('dashboard.desks')}</h2>
                        <div className="flex flex-wrap gap-2">
                            {links.map((item) => {
                                const Icon = item.icon;

                                return (
                                    <Link key={item.key} href={item.href} data-nav={item.key} className="card inline-flex items-center gap-2 px-3 py-2 text-sm font-medium">
                                        <Icon className="size-4 text-aqua" aria-hidden="true" />
                                        {t(`nav.${item.key}`)}
                                    </Link>
                                );
                            })}
                        </div>
                    </div>
                ) : null}
            </div>
        </AppShell>
    );
}
