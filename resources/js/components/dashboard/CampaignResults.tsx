import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import { useId } from 'react';

// ─── Exported Types ───────────────────────────────────────────────────────────

export type CampaignRow = {
    id: number;
    name: string;
    channel: string | null;
    starts_on: string | null;
    ends_on: string | null;
    budget: string | null;
    currency: string | null;
    leads: number;
    opportunities: number;
    won: number;
    won_value: string;
    lead_to_opportunity: number | null;
    lead_to_won: number | null;
    cost_per_lead: string | null;
    cost_per_won: string | null;
};

export type CampaignTotals = {
    leads: number;
    opportunities: number;
    won: number;
    won_value: string;
    lead_to_opportunity: number | null;
    lead_to_won: number | null;
};

export type CampaignData = {
    from: string;
    to: string;
    rows: CampaignRow[];
    totals: CampaignTotals;
    uncampaigned: { leads: number; lead_to_won: number | null };
    manage_href: string | null;
};

export type CampaignPayload = CampaignData | { error: true } | null | undefined;

// ─── Skeleton ─────────────────────────────────────────────────────────────────

export function CampaignResultsSkeleton() {
    const { t } = useI18n();
    return (
        <section className="mt-4" aria-busy="true">
            <span role="status" className="sr-only">
                {t('campaign_results.loading')}
            </span>
            <div className="card p-4 animate-pulse motion-reduce:animate-none">
                <div className="h-5 w-48 rounded bg-well mb-2" />
                <div className="h-4 w-64 rounded bg-well mb-4" />
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-2">
                    {[0, 1, 2, 3].map((i) => (
                        <div key={i} className="rounded-md bg-well p-4">
                            <div className="h-3 w-20 rounded bg-card mb-2" />
                            <div className="h-6 w-12 rounded bg-card" />
                        </div>
                    ))}
                </div>
                <div className="mt-4 h-32 rounded-md bg-well" />
            </div>
        </section>
    );
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function useFormatters(locale: 'en' | 'ar') {
    const intlLocale = locale === 'ar' ? 'ar-LB' : 'en-US';

    function count(n: number): string {
        return new Intl.NumberFormat(intlLocale).format(n);
    }

    function pct(n: number | null): string {
        if (n === null) return '—';
        return new Intl.NumberFormat(intlLocale, {
            style: 'percent',
            maximumFractionDigits: 1,
        }).format(n / 100);
    }

    function money(value: string): string {
        const num = parseFloat(value);
        if (isNaN(num)) return value;
        const isWhole = num % 1 === 0;
        return new Intl.NumberFormat(intlLocale, {
            maximumFractionDigits: isWhole ? 0 : 2,
        }).format(num);
    }

    return { count, pct, money };
}

function formatCost(
    value: string | null,
    currency: string | null,
    money: (v: string) => string
): string {
    if (value === null) return '—';
    return `${money(value)} ${currency ?? ""}`.trim();
}

function buildDateRange(
    starts_on: string | null,
    ends_on: string | null
): string | null {
    if (!starts_on && !ends_on) return null;
    if (starts_on && ends_on) return `${starts_on} – ${ends_on}`;
    if (starts_on) return `${starts_on} –`;
    return `– ${ends_on}`;
}

function buildRowMeta(row: CampaignRow): string {
    const parts: string[] = [];
    if (row.channel) parts.push(row.channel);
    const range = buildDateRange(row.starts_on, row.ends_on);
    if (range) parts.push(range);
    return parts.join(' · ');
}

// ─── Main Component ───────────────────────────────────────────────────────────

export function CampaignResults({
    data,
    onRetry,
}: {
    data: CampaignPayload;
    onRetry?: () => void;
}) {
    const { locale, t } = useI18n();
    const { count, pct, money } = useFormatters(locale);
    const headingId = useId();

    if (data === null || data === undefined) return null;

    // ── Error state ──
    if ('error' in data) {
        return (
            <section className="mt-4" data-testid="campaign-results">
                <div role="alert" className="card p-4">
                    <p className="text-sm text-text mb-4">{t('campaign_results.error')}</p>
                    {onRetry && (
                        <Button variant="outline" size="sm" type="button" onClick={onRetry}>
                            {t('campaign_results.retry')}
                        </Button>
                    )}
                </div>
            </section>
        );
    }

    // ── Data state ──
    const { from, to, rows, totals, uncampaigned, manage_href } =
        data as CampaignData;

    const showCompare =
        rows.length > 0 && uncampaigned.leads > 0;

    const kpis = [
        {
            label: t('campaign_results.col_leads'),
            value: count(totals.leads),
        },
        {
            label: t('campaign_results.col_opportunities'),
            value: count(totals.opportunities),
        },
        {
            label: t('campaign_results.col_won'),
            value: count(totals.won),
        },
        {
            label: t('campaign_results.col_won_value'),
            value: money(totals.won_value),
        },
    ];

    return (
        <section
            aria-labelledby={headingId}
            className="card mt-4 p-4"
            data-testid="campaign-results"
        >
            {/* ── Header ── */}
            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2
                        id={headingId}
                        className="text-base font-semibold text-text"
                    >
                        {t('campaign_results.title')}
                    </h2>
                    <p className="text-sm text-muted">
                        {t('campaign_results.subtitle', { from, to })}
                    </p>
                </div>
                {manage_href && (
                    <Link
                        href={manage_href}
                        className="inline-flex min-h-11 items-center text-sm font-medium text-accent-ink underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2"
                    >
                        {t('campaign_results.manage')}
                    </Link>
                )}
            </div>

            {/* ── KPI tiles ── */}
            <div className="mt-4 grid grid-cols-2 gap-2 lg:grid-cols-4 lg:gap-4">
                {kpis.map((kpi) => (
                    <div key={kpi.label} className="rounded-md bg-well p-4">
                        <p className="text-sm text-muted">{kpi.label}</p>
                        <p className="mt-1 text-xl font-semibold tabular-nums text-text">
                            {kpi.value}
                        </p>
                    </div>
                ))}
            </div>

            {/* ── Compare paragraph ── */}
            {showCompare && (
                <p className="mt-4 text-sm text-muted">
                    {t('campaign_results.compare', {
                        pct: pct(totals.lead_to_won),
                        other: pct(uncampaigned.lead_to_won),
                        count: count(uncampaigned.leads),
                    })}
                </p>
            )}

            {/* ── Empty / Table ── */}
            {rows.length === 0 ? (
                <p
                    role="status"
                    className="mt-4 rounded-md bg-well p-4 text-sm text-muted"
                >
                    {t('campaign_results.empty')}
                </p>
            ) : (
                <div
                    className="mt-4 overflow-x-auto rounded-md focus-visible:outline-2 focus-visible:outline-offset-2"
                    tabIndex={0}
                    role="region"
                    aria-label={t('campaign_results.table')}
                >
                    <table className="w-full min-w-[720px] text-sm">
                        <caption className="sr-only">{t('campaign_results.title')}</caption>
                        <thead>
                            <tr className="border-b border-line">
                                <th
                                    scope="col"
                                    className="px-2 py-2 text-start font-medium text-muted"
                                >
                                    {t('campaign_results.col_campaign')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-2 py-2 text-end font-medium text-muted tabular-nums"
                                >
                                    {t('campaign_results.col_leads')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-2 py-2 text-end font-medium text-muted tabular-nums"
                                >
                                    {t('campaign_results.col_opportunities')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-2 py-2 text-end font-medium text-muted tabular-nums"
                                >
                                    {t('campaign_results.col_won')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-2 py-2 text-end font-medium text-muted tabular-nums"
                                >
                                    {t('campaign_results.col_won_value')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-2 py-2 text-end font-medium text-muted"
                                >
                                    {t('campaign_results.col_conversion')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-2 py-2 text-end font-medium text-muted tabular-nums"
                                >
                                    {t('campaign_results.col_cost_per_lead')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-2 py-2 text-end font-medium text-muted tabular-nums"
                                >
                                    {t('campaign_results.col_cost_per_won')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => {
                                const meta = buildRowMeta(row);
                                const barWidth = `${Math.min(100, row.lead_to_won ?? 0)}%`;
                                return (
                                    <tr
                                        key={row.id}
                                        data-testid={`campaign-${row.id}`}
                                        className="border-t border-line"
                                    >
                                        {/* Campaign name + meta */}
                                        <th
                                            scope="row"
                                            className="px-2 py-2 text-start font-medium text-text"
                                        >
                                            <span className="block">{row.name}</span>
                                            {meta && (
                                                <span className="block whitespace-nowrap text-2xs font-normal text-muted">
                                                    {meta}
                                                </span>
                                            )}
                                        </th>
                                        {/* Leads */}
                                        <td className="px-2 py-2 text-end tabular-nums text-text">
                                            {count(row.leads)}
                                        </td>
                                        {/* Opportunities */}
                                        <td className="px-2 py-2 text-end tabular-nums text-text">
                                            {count(row.opportunities)}
                                        </td>
                                        {/* Won */}
                                        <td className="px-2 py-2 text-end tabular-nums text-text">
                                            {count(row.won)}
                                        </td>
                                        {/* Won value */}
                                        <td className="px-2 py-2 text-end tabular-nums text-text">
                                            {money(row.won_value)}
                                        </td>
                                        {/* Conversion */}
                                        <td className="px-2 py-2 text-end">
                                            <span className="block font-semibold tabular-nums text-text">
                                                {pct(row.lead_to_won)}
                                            </span>
                                            <span className="block text-xs text-muted">
                                                {t('campaign_results.to_opportunity', {
                                                    pct: pct(row.lead_to_opportunity),
                                                })}
                                            </span>
                                            <div
                                                aria-hidden="true"
                                                className="mt-1 h-1 w-full rounded-full bg-well"
                                            >
                                                <div
                                                    className="h-1 rounded-full bg-good"
                                                    style={{ width: barWidth }}
                                                />
                                            </div>
                                        </td>
                                        {/* Cost per lead */}
                                        <td className="px-2 py-2 text-end tabular-nums text-text">
                                            {formatCost(row.cost_per_lead, row.currency, money)}
                                        </td>
                                        {/* Cost per won */}
                                        <td className="px-2 py-2 text-end tabular-nums text-text">
                                            {formatCost(row.cost_per_won, row.currency, money)}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                        <tfoot>
                            <tr className="border-t border-line">
                                <th
                                    scope="row"
                                    className="px-2 py-2 text-start font-semibold text-text"
                                >
                                    {t('campaign_results.total')}
                                </th>
                                <td className="px-2 py-2 text-end tabular-nums font-semibold text-text">
                                    {count(totals.leads)}
                                </td>
                                <td className="px-2 py-2 text-end tabular-nums font-semibold text-text">
                                    {count(totals.opportunities)}
                                </td>
                                <td className="px-2 py-2 text-end tabular-nums font-semibold text-text">
                                    {count(totals.won)}
                                </td>
                                <td className="px-2 py-2 text-end tabular-nums font-semibold text-text">
                                    {money(totals.won_value)}
                                </td>
                                <td className="px-2 py-2 text-end">
                                    <span className="block font-semibold tabular-nums text-text">
                                        {pct(totals.lead_to_won)}
                                    </span>
                                    <span className="block text-xs text-muted">
                                        {t('campaign_results.to_opportunity', {
                                            pct: pct(totals.lead_to_opportunity),
                                        })}
                                    </span>
                                </td>
                                <td className="px-2 py-2 text-end tabular-nums text-text">
                                    {'—'}
                                </td>
                                <td className="px-2 py-2 text-end tabular-nums text-text">
                                    {'—'}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}
        </section>
    );
}
