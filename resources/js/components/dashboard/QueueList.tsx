import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { CSSProperties } from 'react';
import type { Queue } from '@/components/dashboard/types';
import { toneOf, type QueueTone } from '@/lib/dashboard';
import { useI18n } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const toneClass: Record<QueueTone, string> = {
    warn: 'bg-warn-bg text-warn',
    bad: 'bg-bad-bg text-bad',
    good: 'bg-good-bg text-good',
    neutral: 'bg-well text-muted',
};

export function QueueList({ queue, total }: { queue: Queue; total: number }) {
    const { t, locale, dir } = useI18n();
    const Chevron = dir === 'rtl' ? ChevronLeft : ChevronRight;
    const tone = toneOf(queue);
    const name = t(`dashboard.queue_${queue.key}`);
    const share = total > 0 ? Math.min(1, Math.max(0, queue.count) / total) : 0;

    return (
        <article className="card overflow-hidden" data-testid={`queue-${queue.key}`}>
            <div className="flex items-center justify-between gap-3 ps-4 pe-2">
                <h3 className="py-2 text-sm font-semibold">{name}</h3>
                <Link href={queue.href} aria-label={t('dashboard.bar_label', { queue: name, count: queue.count })} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    <span
                        data-testid={`queue-badge-${queue.key}`}
                        data-tone={tone}
                        className={cn('tabular inline-flex min-w-8 items-center justify-center rounded-full px-3 py-1 text-sm font-semibold', toneClass[tone])}
                    >
                        {queue.count}
                    </span>
                </Link>
            </div>
            <div className="fd-hairline" data-testid={`queue-share-${queue.key}`} aria-hidden="true">
                <span className={cn('fd-hairline__fill', `fd-hairline__fill--${tone}`)} style={{ '--fd-scale': share } as CSSProperties} />
            </div>
            {queue.items.length === 0 ? (
                <p className="flex min-h-12 items-center px-4 py-2 text-sm text-muted">{t('dashboard.queue_clear')}</p>
            ) : (
                <ul className="divide-y divide-line">
                    {queue.items.map((item) => {
                        const meta = locale === 'ar' ? item.meta_ar || item.meta : item.meta;
                        const line = [meta, item.when, item.number].filter(Boolean).join(' · ');

                        return (
                            <li key={`${queue.key}-${item.id}`}>
                                <Link
                                    href={item.href}
                                    className="flex min-h-12 items-center justify-between gap-3 px-4 py-2 text-sm hover:bg-well focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate font-semibold">{item.title || t('dashboard.untitled')}</span>
                                        {line ? <span className="block truncate text-muted">{line}</span> : null}
                                    </span>
                                    <Chevron className="size-4 shrink-0 text-faint" aria-hidden="true" />
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
        </article>
    );
}
