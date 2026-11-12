import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import { useId, useState } from 'react';

export type FunnelStage = {
    id: number;
    key: string;
    label: string;
    sort: number;
    is_won: boolean;
    reached: number;
    now: number;
    now_value: string;
    conversion: number | null;
};

export type FunnelData = {
    pipeline_id: number | null;
    pipelines: { id: number; label: string }[];
    cohort: number;
    lost: number;
    win_rate: number | null;
    stages: FunnelStage[];
};

export type Leader = {
    user_id: number;
    name: string;
    deals: number;
    total: string;
    lost: number;
    win_rate: number | null;
    open: number;
    open_value: string;
};

export type LeaderboardData = { rows: Leader[] };

export type FunnelPayload =
    | { funnel: FunnelData | null; leaderboard: LeaderboardData | null }
    | { error: true }
    | null
    | undefined;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function formatCount(value: number, locale: string): string {
    return new Intl.NumberFormat(locale === 'ar' ? 'ar-LB' : 'en-US').format(value);
}

function formatPct(value: number, locale: string): string {
    return new Intl.NumberFormat(locale === 'ar' ? 'ar-LB' : 'en-US', {
        maximumFractionDigits: 1,
    }).format(value);
}

function formatMoney(value: string | number, locale: string, fractionDigits = 0): string {
    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (!isFinite(num)) return String(value);
    return new Intl.NumberFormat(locale === 'ar' ? 'ar-LB' : 'en-US', {
        maximumFractionDigits: fractionDigits,
    }).format(num);
}

function parseMoney(value: string): number {
    const n = parseFloat(value);
    return isFinite(n) ? n : 0;
}

// ---------------------------------------------------------------------------
// Skeleton
// ---------------------------------------------------------------------------

export function FunnelLeaderboardSkeleton(): React.ReactElement {
    const { t } = useI18n();
    return (
        <section aria-busy="true" data-testid="funnel-skeleton" className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <span role="status" className="sr-only">
                {t('funnel_dash.loading')}
            </span>
            <div aria-hidden="true" className="h-72 rounded-md bg-well animate-pulse" />
            <div aria-hidden="true" className="h-72 rounded-md bg-well animate-pulse" />
        </section>
    );
}

// ---------------------------------------------------------------------------
// Funnel card internals
// ---------------------------------------------------------------------------

interface FunnelCardProps {
    data: FunnelData;
    onPipeline?: (id: number) => void;
}

function FunnelCard({ data, onPipeline }: FunnelCardProps): React.ReactElement {
    const { t, locale } = useI18n();
    const [showTable, setShowTable] = useState(false);
    const selectId = useId();

    const { pipeline_id, pipelines, cohort, lost, win_rate, stages } = data;
    const maxReached = stages.length > 0 ? Math.max(stages[0]?.reached ?? 0, 1) : 1;

    const footerWinRate =
        win_rate !== null
            ? ' · ' + t('funnel_dash.win_rate', { pct: formatPct(win_rate, locale) })
            : '';

    const tableEl = (
        <table className={showTable ? 'mt-4 w-full text-sm' : 'sr-only'}>
            <caption className={showTable ? 'sr-only' : undefined}>
                {t('funnel_dash.funnel')}
            </caption>
            <thead>
                <tr>
                    <th scope="col" className="border-b border-line px-2 py-2 text-start">
                        {t('funnel_dash.col_stage')}
                    </th>
                    <th scope="col" className="border-b border-line px-2 py-2 text-end tabular-nums">
                        {t('funnel_dash.col_reached')}
                    </th>
                    <th scope="col" className="border-b border-line px-2 py-2 text-end tabular-nums">
                        {t('funnel_dash.col_now')}
                    </th>
                    <th scope="col" className="border-b border-line px-2 py-2 text-end tabular-nums">
                        {t('funnel_dash.col_conversion')}
                    </th>
                </tr>
            </thead>
            <tbody>
                {stages.map((stage) => (
                    <tr key={stage.id}>
                        <th scope="row" className="border-b border-line px-2 py-2 text-start font-normal">
                            {stage.label}
                        </th>
                        <td className="border-b border-line px-2 py-2 text-end tabular-nums" dir="ltr">
                            {formatCount(stage.reached, locale)}
                        </td>
                        <td className="border-b border-line px-2 py-2 text-end tabular-nums" dir="ltr">
                            {formatCount(stage.now, locale)}
                        </td>
                        <td className="border-b border-line px-2 py-2 text-end tabular-nums" dir="ltr">
                            {stage.conversion !== null ? formatPct(stage.conversion, locale) + '%' : '—'}
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );

    return (
        <div className="card min-w-0 p-4" data-testid="funnel-card">
            {/* Header */}
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 className="text-sm font-semibold text-text">{t('funnel_dash.funnel')}</h3>
                    <p className="text-2xs text-muted">
                        {t('funnel_dash.funnel_desc', { count: formatCount(cohort, locale) })}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {pipelines.length > 1 && (
                        <div>
                            <label htmlFor={selectId} className="sr-only">
                                {t('funnel_dash.pipeline')}
                            </label>
                            <select
                                id={selectId}
                                className="h-10 rounded-ctl border border-input bg-card px-2 text-sm text-text"
                                value={pipeline_id ?? ''}
                                onChange={(e) => {
                                    const val = Number(e.target.value);
                                    if (isFinite(val) && onPipeline) onPipeline(val);
                                }}
                            >
                                {pipelines.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    <Button
                        variant="outline"
                        size="sm"
                        type="button"
                        aria-pressed={showTable}
                        onClick={() => setShowTable((v) => !v)}
                    >
                        {showTable ? t('funnel_dash.show_chart') : t('funnel_dash.show_table')}
                    </Button>
                </div>
            </div>

            {/* Empty */}
            {cohort === 0 ? (
                <p role="status" className="mt-4 rounded-md bg-well p-4 text-sm text-muted">
                    {t('funnel_dash.empty')}
                </p>
            ) : (
                <>
                    {/* Chart view */}
                    {!showTable && (
                        <ol className="mt-4 flex flex-col gap-2" aria-label={t('funnel_dash.funnel')}>
                            {stages.map((stage) => {
                                const widthPct = (stage.reached / maxReached) * 100;
                                const barStyle: React.CSSProperties =
                                    stage.reached > 0
                                        ? { width: `max(${widthPct}%, 2px)` }
                                        : { width: '0%' };
                                const convText =
                                    stage.conversion !== null
                                        ? ' · ' +
                                          t('funnel_dash.conversion', {
                                              pct: formatPct(stage.conversion, locale),
                                          })
                                        : '';
                                return (
                                    <li key={stage.id} data-testid={`funnel-stage-${stage.key}`}>
                                        <div className="flex items-baseline justify-between gap-2">
                                            <span className="text-sm font-medium text-text">
                                                {stage.label}
                                            </span>
                                            <span className="tabular-nums text-sm text-text" dir="ltr">
                                                {formatCount(stage.reached, locale)}
                                            </span>
                                        </div>
                                        <div className="mt-1 h-3 rounded-full bg-well" aria-hidden="true">
                                            <div
                                                className={`h-3 rounded-full ${stage.is_won ? 'bg-aqua' : 'bg-mint'}`}
                                                style={barStyle}
                                            />
                                        </div>
                                        <p className="mt-0.5 text-2xs text-muted">
                                            {t('funnel_dash.now_in_stage', { count: formatCount(stage.now, locale) })}
                                            {convText}
                                        </p>
                                    </li>
                                );
                            })}
                        </ol>
                    )}

                    {/* Table (always rendered; sr-only when chart is shown) */}
                    {tableEl}

                    {/* Footer */}
                    <p className="mt-3 text-2xs text-muted">
                        {t('funnel_dash.lost', { count: formatCount(lost, locale) })}
                        {footerWinRate}
                    </p>
                </>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Leaderboard card internals
// ---------------------------------------------------------------------------

interface LeaderboardCardProps {
    data: LeaderboardData;
}

function LeaderboardCard({ data }: LeaderboardCardProps): React.ReactElement {
    const { t, locale } = useI18n();
    const { rows } = data;

    const maxTotal = rows.reduce((acc, r) => Math.max(acc, parseMoney(r.total)), 0);

    return (
        <div className="card min-w-0 p-4" data-testid="leaderboard-card">
            <h3 className="text-sm font-semibold text-text">{t('funnel_dash.leaderboard')}</h3>
            <p className="text-2xs text-muted">{t('funnel_dash.leaderboard_desc')}</p>

            {rows.length === 0 ? (
                <p role="status" className="mt-4 rounded-md bg-well p-4 text-sm text-muted">
                    {t('funnel_dash.leaderboard_empty')}
                </p>
            ) : (
                <div className="mt-4 overflow-x-auto" tabIndex={0} role="region" aria-label={t('funnel_dash.leaderboard')}>
                    <table className="w-full min-w-[480px] text-sm">
                        <caption className="sr-only">{t('funnel_dash.leaderboard')}</caption>
                        <thead>
                            <tr>
                                <th scope="col" className="border-b border-line px-2 py-2 text-start">
                                    {'#'}
                                </th>
                                <th scope="col" className="border-b border-line px-2 py-2 text-start">
                                    {t('funnel_dash.col_owner')}
                                </th>
                                <th scope="col" className="border-b border-line px-2 py-2 text-end">
                                    {t('funnel_dash.col_deals')}
                                </th>
                                <th scope="col" className="border-b border-line px-2 py-2 text-end">
                                    {t('funnel_dash.col_total')}
                                </th>
                                <th scope="col" className="border-b border-line px-2 py-2 text-end">
                                    {t('funnel_dash.col_win_rate')}
                                </th>
                                <th scope="col" className="border-b border-line px-2 py-2 text-end">
                                    {t('funnel_dash.col_open')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((leader, index) => {
                                const totalNum = parseMoney(leader.total);
                                const barWidthPct = maxTotal > 0 ? (totalNum / maxTotal) * 100 : 0;
                                const isTop = index === 0;
                                const displayName =
                                    leader.name.trim() !== ''
                                        ? leader.name
                                        : t('funnel_dash.unknown_owner');

                                return (
                                    <tr key={leader.user_id} data-testid={`leader-${leader.user_id}`}>
                                        <td
                                            className={`border-b border-line px-2 py-2 tabular-nums text-start${isTop ? ' font-semibold' : ''}`}
                                            dir="ltr"
                                        >
                                            {formatCount(index + 1, locale)}
                                        </td>
                                        <th
                                            scope="row"
                                            className="border-b border-line px-2 py-2 text-start font-normal"
                                        >
                                            <span className={isTop ? 'font-semibold' : ''}>{displayName}</span>
                                            {maxTotal > 0 && (
                                                <div
                                                    className="mt-1 h-1.5 rounded-full bg-mint"
                                                    style={{ width: `${barWidthPct}%` }}
                                                    aria-hidden="true"
                                                />
                                            )}
                                        </th>
                                        <td
                                            className="border-b border-line px-2 py-2 text-end tabular-nums"
                                            dir="ltr"
                                        >
                                            {formatCount(leader.deals, locale)}
                                        </td>
                                        <td
                                            className="border-b border-line px-2 py-2 text-end tabular-nums"
                                            dir="ltr"
                                        >
                                            {formatMoney(leader.total, locale, 0)}
                                        </td>
                                        <td
                                            className="border-b border-line px-2 py-2 text-end tabular-nums"
                                            dir="ltr"
                                        >
                                            {leader.win_rate !== null
                                                ? formatPct(leader.win_rate, locale) + '%'
                                                : '—'}
                                        </td>
                                        <td
                                            className="border-b border-line px-2 py-2 text-end tabular-nums"
                                            dir="ltr"
                                        >
                                            {formatCount(leader.open, locale)}
                                            <span className="text-2xs text-muted">
                                                {' · '}
                                                {formatMoney(leader.open_value, locale, 0)}
                                            </span>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

// ---------------------------------------------------------------------------
// Main export
// ---------------------------------------------------------------------------

interface FunnelLeaderboardProps {
    data: FunnelPayload;
    onPipeline?: (id: number) => void;
    onRetry?: () => void;
}

export function FunnelLeaderboard({
    data,
    onPipeline,
    onRetry,
}: FunnelLeaderboardProps): React.ReactElement | null {
    const { t } = useI18n();
    const headingId = useId();

    if (data === null || data === undefined) {
        return null;
    }

    if ('error' in data && data.error === true) {
        return (
            <section className="mt-4" data-testid="funnel-leaderboard">
                <div role="alert" className="card p-4">
                    <p className="text-sm text-bad">{t('funnel_dash.error')}</p>
                    {onRetry !== undefined && (
                        <Button
                            variant="outline"
                            size="sm"
                            type="button"
                            className="mt-2"
                            onClick={onRetry}
                        >
                            {t('funnel_dash.retry')}
                        </Button>
                    )}
                </div>
            </section>
        );
    }

    const { funnel, leaderboard } = data as {
        funnel: FunnelData | null;
        leaderboard: LeaderboardData | null;
    };

    return (
        <section aria-labelledby={headingId} className="mt-4" data-testid="funnel-leaderboard">
            <h2 id={headingId} className="text-base font-semibold text-text">
                {t('funnel_dash.title')}
            </h2>
            <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                {funnel !== null && (
                    <FunnelCard data={funnel} onPipeline={onPipeline} />
                )}
                {leaderboard !== null && (
                    <LeaderboardCard data={leaderboard} />
                )}
            </div>
        </section>
    );
}
