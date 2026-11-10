import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type ComponentType, type ReactNode } from 'react';
import { KpiStrip } from '@/components/dashboard/KpiStrip';
import { PeriodBar } from '@/components/dashboard/PeriodBar';
import { QueueList } from '@/components/dashboard/QueueList';
import { QueueLoad } from '@/components/dashboard/QueueLoad';
import { SectionGrid } from '@/components/dashboard/SectionGrid';
import type { Queue, Tile } from '@/components/dashboard/types';
import { AppShell } from '@/components/shell/AppShell';
import { navGroups } from '@/components/shell/nav';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { queueTotal } from '@/lib/dashboard';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type DrawerComponent = ComponentType<{
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    children: ReactNode;
}>;

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
    const { t } = useI18n();
    const { auth } = usePage<SharedProps>().props;
    const name = auth.user?.name ?? '';
    const allowed = new Set(auth.nav ?? []);
    const links = navGroups.flatMap((group) => group.items).filter((item) => item.key !== 'dashboard' && allowed.has(item.key));
    const layout = useForm({ keys: tiles.map((tile) => tile.key), from, to });
    const [editing, setEditing] = useState(false);
    const [TileDrawer, setTileDrawer] = useState<DrawerComponent | null>(null);
    const [reloading, setReloading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [recovered, setRecovered] = useState(false);
    const failedRef = useRef(false);
    const inFlight = useRef(0);
    const queues = attention?.queues ?? [];
    const waiting = queueTotal(queues);

    const markFailed = useCallback(() => {
        failedRef.current = true;
        setFailed(true);
        setRecovered(false);
    }, []);

    const markOk = useCallback(() => {
        // Screen readers hear "up to date" once after a failure clears, not on every poll.
        setRecovered(failedRef.current);
        failedRef.current = false;
        setFailed(false);
    }, []);

    const reload = useCallback(() => {
        router.reload({
            only: ['tiles', 'attention'],
            onStart: () => setReloading(true),
            onFinish: () => setReloading(false),
            onSuccess: markOk,
            onError: markFailed,
            // A 500/503 is not a validation error: Inertia reports it here. Show our
            // own "couldn't refresh" badge instead of its full-page error modal.
            onHttpException: () => {
                markFailed();

                return false;
            },
            onNetworkError: markFailed,
        });
    }, [markFailed, markOk]);

    // Count every visit, ours or the user's, so a poll never cancels a navigation.
    useEffect(() => {
        const offStart = router.on('start', () => {
            inFlight.current += 1;
        });
        const offFinish = router.on('finish', () => {
            inFlight.current = Math.max(0, inFlight.current - 1);
        });

        return () => {
            offStart();
            offFinish();
        };
    }, []);

    useEffect(() => {
        const tick = () => {
            if (document.hidden || inFlight.current > 0) {
                return;
            }

            reload();
        };
        const timer = window.setInterval(tick, Math.max(5, refresh) * 1000);

        return () => window.clearInterval(timer);
    }, [refresh, reload]);

    const chooseTiles = () => {
        if (TileDrawer) {
            setEditing(true);

            return;
        }

        void import('@/components/ui/drawer').then((mod) => {
            setTileDrawer(() => mod.Drawer);
            setEditing(true);
        });
    };

    return (
        <AppShell>
            <Head title={t('dashboard.title')} />
            <div data-testid="dashboard-page" data-role={home}>
                <PageHeader
                    title={t('dashboard.greeting', { name })}
                    sub={t(`reports.home_${home}`)}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            {queues.length > 0 ? (
                                <span className="inline-flex min-h-11 items-center gap-2 rounded-full border border-line bg-card px-4 text-sm font-semibold" data-testid="dashboard-live">
                                    <span className="fd-pulse" aria-hidden="true" />
                                    <span className="tabular">{t('dashboard.live_waiting', { count: waiting })}</span>
                                    {reloading ? <span className="font-normal text-muted">{t('dashboard.updating')}</span> : null}
                                </span>
                            ) : null}
                            {failed ? (
                                <span role="alert" className="inline-flex min-h-11 items-center gap-2 rounded-full bg-bad-bg ps-4 pe-1 text-sm font-semibold text-bad">
                                    <span>{t('dashboard.reload_failed')}</span>
                                    <Button type="button" variant="ghost" size="sm" onClick={reload}>{t('dashboard.retry')}</Button>
                                </span>
                            ) : null}
                            <Button type="button" variant="outline" onClick={chooseTiles}>{t('dashboard.edit_tiles')}</Button>
                            <Button type="button" onClick={() => router.get('/reports', { from, to })}>{t('reports.open')}</Button>
                        </div>
                    }
                />
                <p className="sr-only" aria-live="polite" data-testid="dashboard-status">
                    {recovered ? t('dashboard.up_to_date') : ''}
                </p>
                <PeriodBar
                    from={layout.data.from}
                    to={layout.data.to}
                    onChange={(field, value) => layout.setData(field, value)}
                    onSubmit={() => router.get('/dashboard', { from: layout.data.from, to: layout.data.to }, { preserveState: true })}
                />
                <KpiStrip tiles={tiles} onChooseTiles={chooseTiles} />
                <section aria-labelledby="attention-heading" aria-busy={reloading} data-testid="attention-board" className="mt-8">
                    <div className="mb-4 flex flex-wrap items-end justify-between gap-2">
                        <h2 id="attention-heading" className="text-lg font-semibold">{t('dashboard.attention')}</h2>
                        <p className="text-sm text-muted">
                            {queues.length === 0
                                ? t('dashboard.attention_unavailable')
                                : waiting === 0
                                  ? t('dashboard.attention_empty')
                                  : t('dashboard.attention_count', { count: waiting })}
                        </p>
                    </div>
                    {queues.length > 0 ? (
                        <>
                            <QueueLoad queues={queues} />
                            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                                {queues.map((queue) => (
                                    <QueueList key={queue.key} queue={queue} total={waiting} />
                                ))}
                            </div>
                        </>
                    ) : null}
                </section>
                <SectionGrid links={links.map((item) => ({ key: item.key, href: item.href, icon: item.icon }))} />
                {TileDrawer ? (
                    <TileDrawer open={editing} onOpenChange={setEditing} title={t('dashboard.edit_tiles')} description={t('reports.layout')}>
                        <form
                            className="grid gap-3 p-6"
                            onSubmit={(event) => {
                                event.preventDefault();
                                layout.post('/dashboard/layout');
                            }}
                        >
                            {choices.map((key) => (
                                <label key={key} className="flex min-h-11 items-center gap-3 text-sm">
                                    <input
                                        type="checkbox"
                                        className="size-5"
                                        style={{ accentColor: 'var(--accent)' }}
                                        checked={layout.data.keys.includes(key)}
                                        onChange={(event) =>
                                            layout.setData(
                                                'keys',
                                                event.target.checked ? [...layout.data.keys, key] : layout.data.keys.filter((item) => item !== key),
                                            )
                                        }
                                    />
                                    {t(`reports.key_${key}`)}
                                </label>
                            ))}
                            <Button type="submit" variant="outline" disabled={layout.processing}>
                                {layout.processing ? t('dashboard.saving') : t('reports.save_layout')}
                            </Button>
                        </form>
                    </TileDrawer>
                ) : null}
            </div>
        </AppShell>
    );
}
