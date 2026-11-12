import { Link } from '@inertiajs/react';
import { useId, useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';

// ─── Types ───────────────────────────────────────────────────────────────────

export type FollowUpItem = {
    id: number;
    title: string;
    meta: string | null;
    meta_ar: string | null;
    href: string;
    tone: 'bad' | 'warn';
    when: string | null;
    owner: string | null;
    status: string;
};

export type FollowUpQueue = {
    key: 'leads_overdue' | 'leads_due_soon' | 'leads_no_contact';
    href: string;
    count: number;
    items: FollowUpItem[];
};

export type FollowUpsPayload =
    | { queues: FollowUpQueue[]; due_soon_hours: number; stale_days: number }
    | { error: true }
    | null
    | undefined;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatDuration(when: string, now: Date, locale: string): string {
    const target = new Date(when);
    const diffMs = Math.abs(now.getTime() - target.getTime());
    const diffMins = Math.floor(diffMs / 60_000);

    const nf = (value: number, unit: Intl.NumberFormatOptions['unit']) =>
        new Intl.NumberFormat(locale === 'ar' ? 'ar' : 'en', {
            style: 'unit',
            unit,
            unitDisplay: 'short',
        }).format(value);

    if (diffMins < 60) {
        return nf(Math.max(1, diffMins), 'minute');
    }
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 48) {
        return nf(diffHours, 'hour');
    }
    return nf(Math.max(1, Math.floor(diffHours / 24)), 'day');
}

function isErrorPayload(data: FollowUpsPayload): data is { error: true } {
    return data !== null && data !== undefined && 'error' in data && data.error === true;
}

function isDataPayload(
    data: FollowUpsPayload,
): data is { queues: FollowUpQueue[]; due_soon_hours: number; stale_days: number } {
    return data !== null && data !== undefined && 'queues' in data;
}

// ─── Skeleton ─────────────────────────────────────────────────────────────────

export function LeadFollowUpsSkeleton(): React.ReactElement {
    const { t } = useI18n();
    return (
        <section aria-busy="true" data-testid="follow-ups-skeleton" className="mb-4">
            <span role="status" className="sr-only">
                {t('follow_ups.loading')}
            </span>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div className="h-40 rounded-md bg-well animate-pulse" aria-hidden="true" />
                <div className="h-40 rounded-md bg-well animate-pulse" aria-hidden="true" />
                <div className="h-40 rounded-md bg-well animate-pulse" aria-hidden="true" />
            </div>
        </section>
    );
}

// ─── Queue Article ────────────────────────────────────────────────────────────

interface QueueArticleProps {
    queue: FollowUpQueue;
    due_soon_hours: number;
    stale_days: number;
    now: Date;
    headingId: string;
}

function QueueArticle({
    queue,
    due_soon_hours,
    stale_days,
    now,
    headingId,
}: QueueArticleProps): React.ReactElement {
    const { t, locale } = useI18n();
    const { key, href, count, items } = queue;

    const pillTone =
        key === 'leads_overdue'
            ? 'bg-bad-bg text-bad'
            : 'bg-warn-bg text-warn';

    const replace = { hours: due_soon_hours, days: stale_days };

    return (
        <article
            className="card min-w-0 p-4"
            aria-labelledby={headingId}
            data-testid={`queue-${key}`}
        >
            {/* Header row */}
            <div className="flex justify-between gap-2">
                <h3 id={headingId} className="text-sm font-semibold text-text">
                    {t(`follow_ups.queue_${key}`, replace)}
                </h3>
                <span className={`rounded-full px-2 py-0.5 text-2xs font-semibold shrink-0 ${pillTone}`}>
                    <span aria-hidden="true">
                        {count}
                    </span>
                    <span className="sr-only">
                        {t('follow_ups.count_sr', { count })}
                    </span>
                </span>
            </div>

            {/* Description */}
            <p className="mt-1 text-2xs text-muted">
                {t(`follow_ups.queue_${key}_desc`, replace)}
            </p>

            {/* Items */}
            {items.length === 0 ? (
                <p className="mt-4 text-sm text-muted">{t('follow_ups.queue_empty')}</p>
            ) : (
                <ul className="mt-4 flex flex-col divide-y divide-line">
                    {items.map((item) => {
                        const metaLabel =
                            locale === 'ar'
                                ? (item.meta_ar ?? item.meta)
                                : item.meta;

                        const timeLabel = (() => {
                            if (!item.when) {
                                return key === 'leads_no_contact'
                                    ? t('follow_ups.never_contacted')
                                    : null;
                            }
                            const time = formatDuration(item.when, now, locale);
                            if (key === 'leads_overdue') {
                                return t('follow_ups.overdue_by', { time });
                            }
                            if (key === 'leads_due_soon') {
                                return t('follow_ups.due_in', { time });
                            }
                            return t('follow_ups.last_contact', { time });
                        })();

                        const timeNode =
                            item.when && timeLabel ? (
                                <time dateTime={item.when}>{timeLabel}</time>
                            ) : timeLabel ? (
                                <span>{timeLabel}</span>
                            ) : null;

                        const metaParts: React.ReactNode[] = [];
                        if (metaLabel) metaParts.push(metaLabel);
                        metaParts.push(item.owner ?? t('follow_ups.unassigned'));
                        metaParts.push(t(`follow_ups.status_${item.status}`));
                        if (timeNode) metaParts.push(timeNode);

                        const metaLine = metaParts.reduce<React.ReactNode[]>(
                            (acc, part, idx) =>
                                idx === 0
                                    ? [part]
                                    : [...acc, <span key={idx} aria-hidden="true"> · </span>, part],
                            [],
                        );

                        return (
                            <li key={item.id} className="py-2">
                                <Link
                                    href={item.href}
                                    className="block rounded-md px-2 py-2 -mx-2 hover:bg-well focus-visible:outline-2 focus-visible:outline-offset-2 min-h-11"
                                >
                                    <p className="text-sm font-medium truncate text-text">
                                        {item.title}
                                    </p>
                                    <p className="mt-0.5 text-2xs text-muted">
                                        {metaLine}
                                    </p>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}

            {/* Footer link */}
            {count > items.length && (
                <Link
                    href={href}
                    className="mt-2 inline-block text-sm font-semibold text-accent-ink"
                >
                    {t('follow_ups.see_all', { count })}
                </Link>
            )}
        </article>
    );
}

// ─── Main Component ───────────────────────────────────────────────────────────

interface LeadFollowUpsProps {
    data: FollowUpsPayload;
    onRetry?: () => void;
    now?: Date;
}

export function LeadFollowUps({
    data,
    onRetry,
    now: nowProp,
}: LeadFollowUpsProps): React.ReactElement | null {
    const { t } = useI18n();
    const sectionId = useId();
    const headingId = `${sectionId}-heading`;
    const now = useMemo(() => nowProp ?? new Date(), [nowProp]);

    if (data === null || data === undefined) {
        return null;
    }

    if (isErrorPayload(data)) {
        return (
            <section className="mb-4" data-testid="lead-follow-ups">
                <div role="alert" className="card p-4">
                    <p className="text-sm text-text">{t('follow_ups.error')}</p>
                    {onRetry && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="mt-2"
                            onClick={onRetry}
                        >
                            {t('follow_ups.retry')}
                        </Button>
                    )}
                </div>
            </section>
        );
    }

    if (!isDataPayload(data)) {
        return null;
    }

    const { queues, due_soon_hours, stale_days } = data;
    const allClear = queues.every((q) => q.count === 0);

    return (
        <section aria-labelledby={headingId} className="mb-4" data-testid="lead-follow-ups">
            <div className="flex flex-col gap-0.5">
                <h2 id={headingId} className="text-base font-semibold text-text">
                    {t('follow_ups.title')}
                </h2>
                <p className="text-sm text-muted">{t('follow_ups.subtitle')}</p>
            </div>

            {allClear ? (
                <p role="status" className="mt-4 card p-4 text-sm text-muted">
                    {t('follow_ups.all_clear')}
                </p>
            ) : (
                <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                    {queues.map((queue, idx) => (
                        <QueueArticle
                            key={queue.key}
                            queue={queue}
                            due_soon_hours={due_soon_hours}
                            stale_days={stale_days}
                            now={now}
                            headingId={`${sectionId}-queue-${idx}`}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}
