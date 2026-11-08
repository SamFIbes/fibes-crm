import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState, type ComponentType, type ReactNode } from 'react';
import { AppShell } from '@/components/shell/AppShell';
import { navGroups } from '@/components/shell/nav';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Tile = { key: string; value: string; href: string };
type AttentionItem = { id: number; title: string | null; meta: string | null; meta_ar: string | null; href: string; tone: string; number?: string; when?: string | null };
type Queue = { key: string; href: string; count: number; items: AttentionItem[] };

export default function Dashboard({
    home,
    tiles,
    choices,
    from,
    to,
    refresh,
    attention,
}: {
    home: string;
    tiles: Tile[];
    choices: string[];
    from: string;
    to: string;
    refresh: number;
    attention: { queues: Queue[] };
}) {
    const { t, locale } = useI18n();
    const { auth } = usePage<SharedProps>().props;
    const name = auth.user?.name ?? '';
    const allowed = new Set(auth.nav ?? []);
    const links = navGroups.flatMap((group) => group.items).filter((item) => item.key !== 'dashboard' && allowed.has(item.key));
    const layout = useForm({ keys: tiles.map((tile) => tile.key), from, to });
    const [editing, setEditing] = useState(false);
    const [TileDrawer, setTileDrawer] = useState<ComponentType<{ open: boolean; onOpenChange: (open: boolean) => void; title: string; description?: string; children: ReactNode }> | null>(null);
    const queues = attention?.queues ?? [];
    const waiting = queues.reduce((sum, queue) => sum + queue.count, 0);

    useEffect(() => {
        const timer = window.setInterval(() => router.reload({ only: ['tiles', 'attention'] }), Math.max(5, refresh) * 1000);

        return () => window.clearInterval(timer);
    }, [refresh]);

    return (
        <AppShell>
            <Head title={t('dashboard.title')} />
            <div data-testid="dashboard-page" data-role={home}>
                <PageHeader
                    title={t('dashboard.greeting', { name })}
                    sub={t(`reports.home_${home}`)}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" variant="outline" onClick={() => {
                                if (TileDrawer) {
                                    setEditing(true);
                                    return;
                                }
                                void import('@/components/ui/drawer').then((mod) => {
                                    setTileDrawer(() => mod.Drawer);
                                    setEditing(true);
                                });
                            }}>{t('dashboard.edit_tiles')}</Button>
                            <Button type="button" onClick={() => router.get('/reports', { from, to })}>{t('reports.open')}</Button>
                        </div>
                    }
                />
                <section aria-labelledby="attention-heading" data-testid="attention-board">
                    <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
                        <h2 id="attention-heading" className="text-lg font-semibold">{t('dashboard.attention')}</h2>
                        <p className="text-sm text-muted">{queues.length === 0 ? t('dashboard.attention_unavailable') : waiting === 0 ? t('dashboard.attention_empty') : t('dashboard.attention_count', { count: waiting })}</p>
                    </div>
                    {queues.length > 0 ? (
                        <div className="grid gap-3 lg:grid-cols-2">
                            {queues.map((queue) => (
                                <article key={queue.key} className="card p-4" data-testid={`queue-${queue.key}`}>
                                    <div className="flex items-center justify-between gap-3">
                                        <h3 className="text-sm font-semibold">{t(`dashboard.queue_${queue.key}`)}</h3>
                                        <Link href={queue.href} className="inline-flex min-h-11 items-center text-sm font-semibold text-accent-ink">{queue.count}</Link>
                                    </div>
                                    {queue.items.length === 0 ? <p className="mt-3 text-sm text-muted">{t('dashboard.queue_clear')}</p> : (
                                        <ul className="mt-2 divide-y divide-line">
                                            {queue.items.map((item) => (
                                                <li key={`${queue.key}-${item.id}`}>
                                                    <Link href={item.href} className="flex min-h-11 items-center justify-between gap-3 py-2 text-sm">
                                                        <span className="min-w-0">
                                                            <span className="block truncate font-semibold">{item.title || t('dashboard.untitled')}</span>
                                                            <span className="block truncate text-muted">{locale === 'ar' ? item.meta_ar || item.meta : item.meta}{item.when ? ` · ${item.when}` : ''}{item.number ? ` · ${item.number}` : ''}</span>
                                                        </span>
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </article>
                            ))}
                        </div>
                    ) : null}
                </section>
                <form className="mb-4 mt-4 flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); router.get('/dashboard', { from: layout.data.from, to: layout.data.to }, { preserveState: true }); }}>
                    <label className="text-sm">{t('reports.from')}<input className="ms-2 h-11 rounded-ctl border border-input bg-card px-2" type="date" value={layout.data.from} onChange={(event) => layout.setData('from', event.target.value)} /></label>
                    <label className="text-sm">{t('reports.to')}<input className="ms-2 h-11 rounded-ctl border border-input bg-card px-2" type="date" value={layout.data.to} onChange={(event) => layout.setData('to', event.target.value)} /></label>
                    <Button type="submit" variant="outline">{t('reports.apply')}</Button>
                </form>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="dashboard-tiles">
                    {tiles.map((tile) => (
                        <Link key={tile.key} href={tile.href} className="card flex min-h-11 flex-col justify-center p-4" data-testid={`tile-${tile.key}`}>
                            <p className="text-sm text-muted">{t(`reports.key_${tile.key}`)}</p>
                            <p className="mt-2 text-2xl font-semibold" dir="ltr">{tile.value}</p>
                        </Link>
                    ))}
                </div>
                <details className="card mt-4 p-4">
                    <summary className="min-h-11 cursor-pointer text-sm font-semibold">{t('dashboard.sections')}</summary>
                    <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {links.map((item) => {
                            const Icon = item.icon;

                            return (
                                <Link key={item.key} href={item.href} data-nav={item.key} className="flex min-h-11 items-center gap-2 rounded-tile px-2 py-2 text-sm font-semibold hover:bg-well">
                                    <Icon className="size-4 text-text" aria-hidden="true" />
                                    {t(`nav.${item.key}`)}
                                </Link>
                            );
                        })}
                    </div>
                </details>
                {TileDrawer ? <TileDrawer open={editing} onOpenChange={setEditing} title={t('dashboard.edit_tiles')} description={t('reports.layout')}>
                    <form className="grid gap-3 p-6" onSubmit={(event) => { event.preventDefault(); layout.post('/dashboard/layout'); }}>
                        {choices.map((key) => (
                            <label key={key} className="flex min-h-11 items-center gap-3 text-sm">
                                <input type="checkbox" className="size-5" checked={layout.data.keys.includes(key)} onChange={(event) => layout.setData('keys', event.target.checked ? [...layout.data.keys, key] : layout.data.keys.filter((item) => item !== key))} />
                                {t(`reports.key_${key}`)}
                            </label>
                        ))}
                        <Button type="submit" variant="outline">{t('reports.save_layout')}</Button>
                    </form>
                </TileDrawer> : null}
            </div>
        </AppShell>
    );
}
