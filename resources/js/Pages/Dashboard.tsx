import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { AppShell } from '@/components/shell/AppShell';
import { navGroups } from '@/components/shell/nav';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
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

    useEffect(() => {
        const timer = window.setInterval(() => router.reload({ only: ['tiles'] }), Math.max(5, refresh) * 1000);

        return () => window.clearInterval(timer);
    }, [refresh]);

    return (
        <AppShell>
            <Head title={t('dashboard.title')} />
            <div data-testid="dashboard-page" data-role={home}>
                <PageHeader
                    title={t('dashboard.greeting', { name })}
                    sub={t(`reports.home_${home}`)}
                    actions={<Button type="button" onClick={() => router.get('/reports', { from, to })}>{t('reports.open')}</Button>}
                />
                <form className="mb-4 flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); router.get('/dashboard', { from: layout.data.from, to: layout.data.to }, { preserveState: true }); }}>
                    <label className="text-sm">{t('reports.from')}<input className="ms-2 h-10 rounded-ctl border border-input bg-card px-2" type="date" value={layout.data.from} onChange={(event) => layout.setData('from', event.target.value)} /></label>
                    <label className="text-sm">{t('reports.to')}<input className="ms-2 h-10 rounded-ctl border border-input bg-card px-2" type="date" value={layout.data.to} onChange={(event) => layout.setData('to', event.target.value)} /></label>
                    <Button type="submit" size="sm" variant="outline">{t('reports.apply')}</Button>
                </form>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="dashboard-tiles">
                    {tiles.map((tile) => (
                        <Link key={tile.key} href={tile.href} className="card p-4" data-testid={`tile-${tile.key}`}>
                            <p className="text-sm text-muted">{t(`reports.key_${tile.key}`)}</p>
                            <p className="mt-2 text-2xl font-semibold" dir="ltr">{tile.value}</p>
                        </Link>
                    ))}
                </div>
                <form className="card mt-4 p-4" onSubmit={(event) => { event.preventDefault(); layout.post('/dashboard/layout'); }}>
                    <h2 className="text-sm font-semibold">{t('reports.layout')}</h2>
                    <div className="mt-3 grid gap-2 sm:grid-cols-2">
                        {choices.map((key) => (
                            <label key={key} className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={layout.data.keys.includes(key)} onChange={(event) => layout.setData('keys', event.target.checked ? [...layout.data.keys, key] : layout.data.keys.filter((item) => item !== key))} />
                                {t(`reports.key_${key}`)}
                            </label>
                        ))}
                    </div>
                    <Button className="mt-3" type="submit" size="sm" variant="outline">{t('reports.save_layout')}</Button>
                </form>
                <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {links.map((item) => {
                        const Icon = item.icon;

                        return (
                            <Link key={item.key} href={item.href} data-nav={item.key} className="card p-4">
                                <Icon className="size-5 text-text" aria-hidden="true" />
                                <h3 className="mt-3 text-sm font-semibold">{t(`nav.${item.key}`)}</h3>
                            </Link>
                        );
                    })}
                </div>
            </div>
        </AppShell>
    );
}
