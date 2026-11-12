import { useId } from 'react';
import {
    ChartCard,
    ColumnChart,
    LineChart,
    DonutChart,
    HBarChart,
    ChartEmpty,
    ChartSkeleton,
} from '@/components/charts/Charts';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';

export type SalesData = {
    from: string;
    to: string;
    kpis: {
        new_leads: number;
        new_opportunities: number;
        won: number;
        won_value: string;
        open: number;
        open_value: string;
    };
    weekly_leads: { week: string; count: number }[];
    weekly_opportunities: { week: string; opened: number; won: number; lost: number }[];
    monthly_won: { month: string; count: number; value: string }[];
    lead_sources: { channel: string; label: string; count: number }[];
    won_by_area: { area_id: number | null; label: string; count: number; value: string }[];
};

export type SalesPayload = SalesData | { error: true } | null | undefined;

// ─── Formatting helpers ────────────────────────────────────────────────────────

function useFormatters(locale: string) {
    const resolvedLocale = locale === 'ar' ? 'ar-LB' : 'en-US';

    const count = new Intl.NumberFormat(resolvedLocale);
    const compact = new Intl.NumberFormat(resolvedLocale, { notation: 'compact' });
    const moneyFmt = new Intl.NumberFormat(resolvedLocale, { maximumFractionDigits: 0 });

    function money(val: string | number): string {
        const n = typeof val === 'string' ? parseFloat(val) : val;
        return moneyFmt.format(isNaN(n) ? 0 : n);
    }

    function formatCount(n: number): string {
        return count.format(n);
    }

    function formatCompact(n: number): string {
        return compact.format(n);
    }

    function weekLabel(week: string): string {
        // 'YYYY-MM-DD' → 'MM-DD'
        return week.length >= 10 ? week.slice(5, 10) : week;
    }

    function monthLabel(month: string): string {
        // 'YYYY-MM' → short month name
        const parts = month.split('-');
        const y = parseInt(parts[0] ?? '2000', 10);
        const m = parseInt(parts[1] ?? '1', 10);
        const date = new Date(Date.UTC(y, m - 1, 1));
        return new Intl.DateTimeFormat(resolvedLocale, { month: 'short', timeZone: 'UTC' }).format(date);
    }

    return { money, formatCount, formatCompact, weekLabel, monthLabel };
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function allZero(values: number[]): boolean {
    return values.every(v => v === 0);
}

function isSeriesEmpty(data: { label: string; values: number[] }[]): boolean {
    return data.length === 0 || data.every(d => allZero(d.values));
}

function isDonutEmpty(data: { label: string; value: number }[]): boolean {
    return data.length === 0 || data.every(d => d.value === 0);
}

function isHBarEmpty(data: { label: string; value: number }[]): boolean {
    return data.length === 0 || data.every(d => d.value === 0);
}

// ─── Skeleton ─────────────────────────────────────────────────────────────────

export function SalesOverviewSkeleton() {
    const { t } = useI18n();
    return (
        <section aria-busy="true" className="mt-4" data-testid="sales-overview-skeleton">
            <span role="status" className="sr-only">
                {t('sales_dash.loading')}
            </span>
            <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
                {[0, 1, 2, 3].map(i => (
                    <div key={i} className="h-24 rounded-md bg-well animate-pulse" />
                ))}
            </div>
            <div className="grid gap-4 lg:grid-cols-2 mt-4">
                <ChartSkeleton />
                <ChartSkeleton />
            </div>
        </section>
    );
}

// ─── Main component ───────────────────────────────────────────────────────────

export function SalesOverview({
    data,
    loading,
    onRetry,
}: {
    data: SalesPayload;
    loading?: boolean;
    onRetry?: () => void;
}) {
    const { t, locale } = useI18n();
    const headingId = useId();
    const fmt = useFormatters(locale);

    const toggleLabels = {
        show: t('sales_dash.show_table'),
        hide: t('sales_dash.show_chart'),
    };

    if (loading) {
        return <SalesOverviewSkeleton />;
    }

    if (data == null) {
        return null;
    }

    if ('error' in data) {
        return (
            <section className="mt-4" data-testid="sales-overview">
                <div role="alert" className="card p-4">
                    <p>{t('sales_dash.error')}</p>
                    {onRetry && (
                        <Button type="button" variant="outline" size="sm" onClick={onRetry} className="mt-3">
                            {t('sales_dash.retry')}
                        </Button>
                    )}
                </div>
            </section>
        );
    }

    const { from, to, kpis, weekly_leads, weekly_opportunities, monthly_won, lead_sources, won_by_area } = data;

    // ── KPI cards ──────────────────────────────────────────────────────────────

    const kpiCards: Array<{
        key: string;
        label: string;
        value: string;
        hint: string;
    }> = [
        {
            key: 'new_leads',
            label: t('sales_dash.kpi_new_leads'),
            value: fmt.formatCount(kpis.new_leads),
            hint: t('sales_dash.kpi_new_leads_hint'),
        },
        {
            key: 'new_opportunities',
            label: t('sales_dash.kpi_new_opportunities'),
            value: fmt.formatCount(kpis.new_opportunities),
            hint: t('sales_dash.kpi_new_opportunities_hint'),
        },
        {
            key: 'won',
            label: t('sales_dash.kpi_won'),
            value: fmt.formatCount(kpis.won),
            hint: t('sales_dash.kpi_won_hint', { value: fmt.money(kpis.won_value) }),
        },
        {
            key: 'open',
            label: t('sales_dash.kpi_open'),
            value: fmt.formatCount(kpis.open),
            hint: t('sales_dash.kpi_open_hint', { value: fmt.money(kpis.open_value) }),
        },
    ];

    // ── Weekly leads chart data ────────────────────────────────────────────────

    const weeklyLeadsChartData: { label: string; values: number[] }[] = weekly_leads.map(w => ({
        label: fmt.weekLabel(w.week),
        values: [w.count],
    }));

    const weeklyLeadsTableRows: (string | number)[][] = weekly_leads.map(w => [
        w.week,
        w.count,
    ]);

    // ── Weekly opportunities chart data ───────────────────────────────────────

    const weeklyOppChartData: { label: string; values: number[] }[] = weekly_opportunities.map(w => ({
        label: fmt.weekLabel(w.week),
        values: [w.opened, w.won, w.lost],
    }));

    const weeklyOppTableRows: (string | number)[][] = weekly_opportunities.map(w => [
        w.week,
        w.opened,
        w.won,
        w.lost,
    ]);

    // ── Monthly won count chart data ───────────────────────────────────────────

    const monthlyWonCountData: { label: string; values: number[] }[] = monthly_won.map(m => ({
        label: fmt.monthLabel(m.month),
        values: [m.count],
    }));

    const monthlyWonCountTableRows: (string | number)[][] = monthly_won.map(m => [
        fmt.monthLabel(m.month),
        m.count,
    ]);

    // ── Monthly won value chart data ───────────────────────────────────────────

    const monthlyWonValueData: { label: string; values: number[] }[] = monthly_won.map(m => ({
        label: fmt.monthLabel(m.month),
        values: [parseFloat(m.value)],
    }));

    const monthlyWonValueTableRows: (string | number)[][] = monthly_won.map(m => [
        fmt.monthLabel(m.month),
        fmt.money(m.value),
    ]);

    // ── Lead sources chart data ────────────────────────────────────────────────

    // Keep the donut readable: the top seven sources, then everything else together.
    const topSources = lead_sources.slice(0, 7);
    const otherSources = lead_sources.slice(7).reduce((sum, s) => sum + s.count, 0);
    const leadSourcesData: { label: string; value: number }[] = [
        ...topSources.map(s => ({ label: s.label, value: s.count })),
        ...(otherSources > 0 ? [{ label: t('sales_dash.other_sources'), value: otherSources }] : []),
    ];

    const leadSourcesTableRows: (string | number)[][] = lead_sources.map(s => [
        s.label,
        s.count,
    ]);

    // ── Won by area chart data ─────────────────────────────────────────────────

    const wonByAreaData: { label: string; value: number }[] = won_by_area.map(a => ({
        label: a.area_id === null ? t('sales_dash.no_area') : a.label,
        value: parseFloat(a.value),
    }));

    const wonByAreaTableRows: (string | number)[][] = won_by_area.map(a => [
        a.area_id === null ? t('sales_dash.no_area') : a.label,
        a.count,
        fmt.money(a.value),
    ]);

    // ── Render ─────────────────────────────────────────────────────────────────

    return (
        <section aria-labelledby={headingId} className="mt-4" data-testid="sales-overview">
            <h2 id={headingId} className="text-base font-semibold">
                {t('sales_dash.title')}
            </h2>
            <p className="mt-1 text-sm text-muted">
                {t('sales_dash.period', { from, to })}
            </p>

            {/* KPI Cards */}
            <dl className="grid gap-4 grid-cols-2 lg:grid-cols-4 mt-4">
                {kpiCards.map(kpi => (
                    <div key={kpi.key} className="card p-4" data-testid={`sales-kpi-${kpi.key}`}>
                        <dt className="text-sm text-muted">{kpi.label}</dt>
                        <dd className="mt-2 text-2xl font-semibold" dir="ltr">{kpi.value}</dd>
                        <dd className="mt-1 text-2xs text-muted">{kpi.hint}</dd>
                    </div>
                ))}
            </dl>

            {/* Charts */}
            <div className="mt-4 grid gap-4 lg:grid-cols-2">

                {/* 1. Weekly leads */}
                <div data-testid="chart-weekly-leads">
                    <ChartCard
                        title={t('sales_dash.weekly_leads')}
                        description={t('sales_dash.weekly_leads_desc')}
                        table={{
                            columns: [t('sales_dash.col_week'), t('sales_dash.col_leads')],
                            rows: weeklyLeadsTableRows,
                        }}
                        toggleLabels={toggleLabels}
                    >
                        {isSeriesEmpty(weeklyLeadsChartData) ? (
                            <ChartEmpty message={t('sales_dash.empty')} />
                        ) : (
                            <ColumnChart
                                label={t('sales_dash.weekly_leads')}
                                data={weeklyLeadsChartData}
                                series={[{ name: t('sales_dash.col_leads'), className: 'fill-mint' }]}
                                formatValue={fmt.formatCompact}
                            />
                        )}
                    </ChartCard>
                </div>

                {/* 2. Weekly opportunities */}
                <div data-testid="chart-weekly-opportunities">
                    <ChartCard
                        title={t('sales_dash.weekly_opportunities')}
                        description={t('sales_dash.weekly_opportunities_desc')}
                        table={{
                            columns: [
                                t('sales_dash.col_week'),
                                t('sales_dash.col_opened'),
                                t('sales_dash.col_won'),
                                t('sales_dash.col_lost'),
                            ],
                            rows: weeklyOppTableRows,
                        }}
                        toggleLabels={toggleLabels}
                    >
                        {isSeriesEmpty(weeklyOppChartData) ? (
                            <ChartEmpty message={t('sales_dash.empty')} />
                        ) : (
                            <LineChart
                                label={t('sales_dash.weekly_opportunities')}
                                data={weeklyOppChartData}
                                series={[
                                    { name: t('sales_dash.col_opened'), className: 'fill-ink' },
                                    { name: t('sales_dash.col_won'), className: 'fill-good' },
                                    { name: t('sales_dash.col_lost'), className: 'fill-bad' },
                                ]}
                                formatValue={fmt.formatCompact}
                            />
                        )}
                    </ChartCard>
                </div>

                {/* 3. Monthly won count */}
                <div data-testid="chart-monthly-won">
                    <ChartCard
                        title={t('sales_dash.monthly_won')}
                        description={t('sales_dash.monthly_won_desc')}
                        table={{
                            columns: [t('sales_dash.col_month'), t('sales_dash.col_count')],
                            rows: monthlyWonCountTableRows,
                        }}
                        toggleLabels={toggleLabels}
                    >
                        {isSeriesEmpty(monthlyWonCountData) ? (
                            <ChartEmpty message={t('sales_dash.empty')} />
                        ) : (
                            <ColumnChart
                                label={t('sales_dash.monthly_won')}
                                data={monthlyWonCountData}
                                series={[{ name: t('sales_dash.col_count'), className: 'fill-ink' }]}
                                formatValue={fmt.formatCompact}
                            />
                        )}
                    </ChartCard>
                </div>

                {/* 4. Monthly won value */}
                <div data-testid="chart-monthly-won-value">
                    <ChartCard
                        title={t('sales_dash.monthly_won_value')}
                        description={t('sales_dash.monthly_won_value_desc')}
                        table={{
                            columns: [t('sales_dash.col_month'), t('sales_dash.col_value')],
                            rows: monthlyWonValueTableRows,
                        }}
                        toggleLabels={toggleLabels}
                    >
                        {isSeriesEmpty(monthlyWonValueData) ? (
                            <ChartEmpty message={t('sales_dash.empty')} />
                        ) : (
                            <ColumnChart
                                label={t('sales_dash.monthly_won_value')}
                                data={monthlyWonValueData}
                                series={[{ name: t('sales_dash.col_value'), className: 'fill-aqua' }]}
                                formatValue={v => fmt.money(v)}
                            />
                        )}
                    </ChartCard>
                </div>

                {/* 5. Lead sources */}
                <div data-testid="chart-lead-sources">
                    <ChartCard
                        title={t('sales_dash.lead_sources')}
                        description={t('sales_dash.lead_sources_desc')}
                        table={{
                            columns: [t('sales_dash.col_source'), t('sales_dash.col_leads')],
                            rows: leadSourcesTableRows,
                        }}
                        toggleLabels={toggleLabels}
                    >
                        {isDonutEmpty(leadSourcesData) ? (
                            <ChartEmpty message={t('sales_dash.empty')} />
                        ) : (
                            <DonutChart
                                label={t('sales_dash.lead_sources')}
                                data={leadSourcesData}
                                totalLabel={t('sales_dash.col_leads')}
                                formatValue={fmt.formatCompact}
                            />
                        )}
                    </ChartCard>
                </div>

                {/* 6. Won by area */}
                <div data-testid="chart-won-by-area">
                    <ChartCard
                        title={t('sales_dash.won_by_area')}
                        description={t('sales_dash.won_by_area_desc')}
                        table={{
                            columns: [
                                t('sales_dash.col_area'),
                                t('sales_dash.col_count'),
                                t('sales_dash.col_value'),
                            ],
                            rows: wonByAreaTableRows,
                        }}
                        toggleLabels={toggleLabels}
                    >
                        {isHBarEmpty(wonByAreaData) ? (
                            <ChartEmpty message={t('sales_dash.empty')} />
                        ) : (
                            <HBarChart
                                label={t('sales_dash.won_by_area')}
                                data={wonByAreaData}
                                formatValue={v => fmt.money(v)}
                            />
                        )}
                    </ChartCard>
                </div>

            </div>
        </section>
    );
}
