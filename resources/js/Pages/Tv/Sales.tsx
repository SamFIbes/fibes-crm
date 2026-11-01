import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useI18n } from '@/lib/i18n';

type Deal = { id: number; label: string; amount: string; owner: string; big: boolean; fanfare: boolean };
type Feed = {
    totals: { count: number; amount: string };
    targets: { label: string; value: string; progress: string }[];
    leaders: { user_id: number; name: string; total: string; deals: number }[];
    deals: Deal[];
};

export default function TvSales({ feed: initial, token }: { feed: Feed; token: string }) {
    const { t } = useI18n();
    const [feed, setFeed] = useState(initial);
    const seen = useRef<number | null>(null);

    useEffect(() => {
        document.documentElement.classList.add('dark');

        return () => document.documentElement.classList.remove('dark');
    }, []);

    useEffect(() => {
        const timer = window.setInterval(async () => {
            const response = await fetch(`/api/v1/tv/sales?token=${encodeURIComponent(token)}`, { headers: { Accept: 'application/json' } });

            if (response.ok) {
                setFeed(await response.json());
            }
        }, 2000);

        return () => window.clearInterval(timer);
    }, [token]);

    useEffect(() => {
        const newest = feed.deals[0];

        if (!newest) {
            return;
        }

        if (seen.current === null) {
            seen.current = newest.id;

            return;
        }

        if (newest.id <= seen.current) {
            return;
        }

        const sound = newest.fanfare ? 'fanfare' : newest.big ? 'jackpot' : 'deal';
        const audio = new Audio(`/tv/sounds/${sound}.wav`);
        audio.play().catch(() => undefined);
        seen.current = newest.id;
    }, [feed]);

    return (
        <div data-testid="tv-board">
            <Head title={t('performance.tv')} />
            <main className="min-h-screen bg-background px-4 py-6 text-foreground">
                <div className="mx-auto flex max-w-5xl flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-sm text-muted">FIBES</p>
                        <h1 className="text-3xl font-semibold">{t('performance.tv')}</h1>
                    </div>
                    <button type="button" className="text-sm font-semibold" data-testid="tv-theme" onClick={() => document.documentElement.classList.toggle('dark')}>{t('performance.theme')}</button>
                </div>
                <section className="mx-auto mt-6 grid max-w-5xl gap-4 sm:grid-cols-2">
                    <article className="card p-5">
                        <p className="text-sm text-muted">{t('performance.month_total')}</p>
                        <p className="text-4xl font-semibold" dir="ltr">${feed.totals.amount}</p>
                    </article>
                    <article className="card p-5">
                        <p className="text-sm text-muted">{t('performance.deals')}</p>
                        <p className="text-4xl font-semibold" dir="ltr">{feed.totals.count}</p>
                    </article>
                </section>
                <section className="mx-auto mt-4 grid max-w-5xl gap-4 lg:grid-cols-2">
                    <article className="card p-5">
                        <h2 className="text-sm font-semibold">{t('performance.board')}</h2>
                        <ul className="mt-3 space-y-2">
                            {feed.leaders.map((row, index) => (
                                <li key={row.user_id} className="flex justify-between text-sm"><span><span dir="ltr">{index + 1}.</span> {row.name}</span><span dir="ltr">${row.total}</span></li>
                            ))}
                        </ul>
                    </article>
                    <article className="card p-5">
                        <h2 className="text-sm font-semibold">{t('performance.targets')}</h2>
                        <ul className="mt-3 space-y-2">
                            {feed.targets.map((row) => (
                                <li key={row.label} className="text-sm">{row.label}: <span dir="ltr">{row.progress} / {row.value}</span></li>
                            ))}
                        </ul>
                    </article>
                </section>
                <section className="mx-auto mt-4 max-w-5xl space-y-3">
                    {feed.deals.map((deal) => (
                        <article key={deal.id} className="card flex items-center justify-between p-4" data-testid="tv-deal" data-big={deal.big ? '1' : '0'}>
                            <div>
                                <p className="text-lg font-semibold">{deal.label}</p>
                                <p className="text-sm text-muted">{deal.owner}</p>
                            </div>
                            <p className="text-2xl font-semibold" dir="ltr">${deal.amount}</p>
                        </article>
                    ))}
                </section>
            </main>
        </div>
    );
}
