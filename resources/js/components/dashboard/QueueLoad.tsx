import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import type { CSSProperties } from 'react';
import type { Queue } from '@/components/dashboard/types';
import { queueMax, queueShares, queueTotal } from '@/lib/dashboard';
import { useI18n } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const palette: readonly string[] = ['var(--brand-mint)', 'var(--brand-aqua)', 'var(--warn)', 'var(--bad)', 'var(--side-dim)'];

function colorAt(index: number): string {
    return palette[index % palette.length] ?? 'var(--brand-mint)';
}

export function QueueLoad({ queues }: { queues: Queue[] }) {
    const { t } = useI18n();
    const total = queueTotal(queues);
    const max = queueMax(queues);
    const shares = queueShares(queues);
    const topKey = max > 0 ? queues.find((queue) => queue.count === max)?.key : undefined;
    const colorOf = (key: string) => colorAt(Math.max(0, queues.findIndex((queue) => queue.key === key)));

    return (
        <div className="grid gap-4 lg:grid-cols-12">
            <div className="fd-deep lg:col-span-4" data-testid="queue-load">
                <h3 className="fd-deep-muted text-sm font-semibold">{t('dashboard.queue_load')}</h3>
                <p className="fd-display mt-4" dir="ltr">{total}</p>
                {total === 0 ? (
                    <div className="mt-6 flex items-start gap-3">
                        <span className="mt-1 inline-flex size-6 shrink-0 items-center justify-center rounded-full" style={{ background: 'var(--brand-mint)', color: 'var(--side)' }}>
                            <Check className="size-4" aria-hidden="true" />
                        </span>
                        <div>
                            <p className="font-semibold">{t('dashboard.all_clear')}</p>
                            <p className="fd-deep-muted mt-1 text-sm">{t('dashboard.all_clear_body')}</p>
                        </div>
                    </div>
                ) : (
                    <div className="mt-6 flex h-3 w-full overflow-hidden rounded-full" data-testid="queue-share-bar" aria-hidden="true">
                        {shares.map((share) => (
                            <span
                                key={share.key}
                                className="h-full border-e-2 last:border-e-0"
                                style={{ width: `${share.share * 100}%`, background: colorOf(share.key), borderColor: 'var(--side)' }}
                            />
                        ))}
                    </div>
                )}
                <ul className="mt-6 divide-y divide-side-line" data-testid="queue-legend">
                    {queues.map((queue, index) => (
                        <li key={queue.key} className="flex items-center gap-2 py-2 text-sm">
                            <span className="fd-dot" style={{ background: colorAt(index) }} aria-hidden="true" />
                            <span className="min-w-0 flex-1 truncate">{t(`dashboard.queue_${queue.key}`)}</span>
                            <span className="tabular font-semibold" dir="ltr">{queue.count}</span>
                        </li>
                    ))}
                </ul>
            </div>
            <figure className="card p-6 lg:col-span-8" data-testid="queue-chart">
                <h3 className="text-sm font-semibold">{t('dashboard.waiting_by_queue')}</h3>
                <figcaption className="sr-only">{t('dashboard.chart_desc', { count: total, queues: queues.length })}</figcaption>
                <ul className="mt-6 grid gap-2" style={{ gridTemplateColumns: `repeat(${Math.max(1, queues.length)}, minmax(0, 1fr))` }}>
                    {queues.map((queue, index) => {
                        const name = t(`dashboard.queue_${queue.key}`);
                        const scale = max > 0 && queue.count > 0 ? Math.max(0.04, queue.count / max) : 0;
                        const top = queue.key === topKey;

                        return (
                            <li key={queue.key} className="min-w-0">
                                <Link
                                    href={queue.href}
                                    aria-label={t('dashboard.bar_label', { queue: name, count: queue.count })}
                                    className="flex min-h-11 min-w-0 flex-col items-center gap-2 rounded-tile px-1 py-2 hover:bg-well focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <span className="tabular text-sm font-semibold" dir="ltr">{queue.count}</span>
                                    <span className="fd-track" aria-hidden="true">
                                        <span
                                            className={cn('fd-fill fd-grow', top && 'fd-fill--top')}
                                            data-top={top ? 'true' : 'false'}
                                            style={{ '--fd-scale': scale, '--i': index } as CSSProperties}
                                        />
                                    </span>
                                    <span className="fd-2xs block w-full truncate text-center text-faint">{t(`dashboard.short_${queue.key}`)}</span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </figure>
        </div>
    );
}
