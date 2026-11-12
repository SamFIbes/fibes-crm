import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ColumnChart, DonutChart, niceMax } from '@/components/charts/Charts';
import { SalesOverview, type SalesData } from '@/components/dashboard/SalesOverview';
import { I18nProvider } from '@/lib/i18n';

const translations = {
    sales_dash: {
        title: 'Sales overview',
        period: ':from to :to',
        kpi_new_leads: 'New leads',
        kpi_new_leads_hint: 'Leads created in the period',
        kpi_new_opportunities: 'New opportunities',
        kpi_new_opportunities_hint: 'Opportunities opened in the period',
        kpi_won: 'Won',
        kpi_won_hint: ':value monthly value won in the period',
        kpi_open: 'Open',
        kpi_open_hint: ':value monthly value still open at the end of the period',
        weekly_leads: 'Incoming leads per week',
        weekly_leads_desc: 'Last 13 weeks',
        weekly_opportunities: 'Opportunities per week',
        weekly_opportunities_desc: 'Last 13 weeks',
        monthly_won: 'Won opportunities per month',
        monthly_won_desc: 'Last 12 months',
        monthly_won_value: 'Won value per month',
        monthly_won_value_desc: 'Last 12 months',
        lead_sources: 'Lead sources',
        lead_sources_desc: 'New leads by source',
        won_by_area: 'Won value by area',
        won_by_area_desc: 'Top 8 areas',
        no_area: 'No area',
        other_sources: 'Other sources',
        col_week: 'Week of',
        col_month: 'Month',
        col_source: 'Source',
        col_area: 'Area',
        col_leads: 'Leads',
        col_opened: 'Opened',
        col_won: 'Won',
        col_lost: 'Lost',
        col_count: 'Deals',
        col_value: 'Value',
        show_table: 'Show as table',
        show_chart: 'Show as chart',
        empty: 'Nothing in this period yet.',
        error: 'The sales charts could not load.',
        retry: 'Try again',
        loading: 'Loading sales charts',
    },
};

function wrap(node: ReactNode) {
    return render(
        <I18nProvider locale="en" dir="ltr" translations={translations as never}>
            {node}
        </I18nProvider>,
    );
}

const synthetic: SalesData = {
    from: '2026-11-01',
    to: '2026-11-12',
    kpis: { new_leads: 1234, new_opportunities: 12, won: 5, won_value: '650.00', open: 9, open_value: '420.50' },
    weekly_leads: [
        { week: '2026-11-02', count: 3 },
        { week: '2026-11-09', count: 7 },
    ],
    weekly_opportunities: [
        { week: '2026-11-02', opened: 2, won: 1, lost: 0 },
        { week: '2026-11-09', opened: 4, won: 2, lost: 1 },
    ],
    monthly_won: [
        { month: '2026-10', count: 3, value: '300.00' },
        { month: '2026-11', count: 5, value: '650.00' },
    ],
    lead_sources: [
        { channel: 'web', label: 'Website', count: 6 },
        { channel: 'call_center', label: 'Call center', count: 4 },
    ],
    won_by_area: [
        { area_id: 1, label: 'Synthetic Area', count: 2, value: '400.00' },
        { area_id: null, label: '', count: 1, value: '50.00' },
    ],
};

describe('SalesOverview', () => {
    it('shows the four period KPI cards with their values', () => {
        wrap(<SalesOverview data={synthetic} />);

        expect(screen.getByRole('heading', { name: 'Sales overview' })).toBeInTheDocument();
        expect(within(screen.getByTestId('sales-kpi-new_leads')).getByText('1,234')).toBeInTheDocument();
        expect(within(screen.getByTestId('sales-kpi-won')).getByText('650 monthly value won in the period')).toBeInTheDocument();
        expect(within(screen.getByTestId('sales-kpi-open')).getByText('9')).toBeInTheDocument();
    });

    it('swaps a chart for a visible table and back', async () => {
        const user = userEvent.setup();
        wrap(<SalesOverview data={synthetic} />);
        const card = screen.getByTestId('chart-won-by-area');
        const toggle = within(card).getByRole('button', { name: 'Show as table' });

        expect(toggle).toHaveAttribute('aria-pressed', 'false');
        await user.click(toggle);
        expect(within(card).getByRole('button', { name: 'Show as chart' })).toHaveAttribute('aria-pressed', 'true');
        const table = within(card).getByRole('table');
        expect(within(table).getByRole('rowheader', { name: 'No area' })).toBeInTheDocument();
        expect(within(table).getByText('400')).toBeInTheDocument();
    });

    it('gives every chart a text alternative', () => {
        wrap(<SalesOverview data={synthetic} />);

        expect(screen.getByRole('img', { name: 'Incoming leads per week' })).toBeInTheDocument();
        expect(screen.getByRole('img', { name: 'Opportunities per week' })).toBeInTheDocument();
        expect(screen.getByRole('group', { name: 'Lead sources' })).toBeInTheDocument();
        expect(screen.getAllByRole('table', { hidden: true }).length).toBeGreaterThanOrEqual(6);
    });

    it('shows an empty state when a chart has nothing to draw', () => {
        wrap(<SalesOverview data={{ ...synthetic, weekly_leads: [{ week: '2026-11-09', count: 0 }], lead_sources: [] }} />);

        expect(within(screen.getByTestId('chart-weekly-leads')).getByRole('status')).toHaveTextContent('Nothing in this period yet.');
        expect(within(screen.getByTestId('chart-lead-sources')).getByRole('status')).toHaveTextContent('Nothing in this period yet.');
    });

    it('shows the error state with a retry button', async () => {
        const user = userEvent.setup();
        const retry = vi.fn();
        wrap(<SalesOverview data={{ error: true }} onRetry={retry} />);

        expect(screen.getByRole('alert')).toHaveTextContent('The sales charts could not load.');
        await user.click(screen.getByRole('button', { name: 'Try again' }));
        expect(retry).toHaveBeenCalledOnce();
    });

    it('shows a loading skeleton and nothing when there is no access', () => {
        const { container, unmount } = wrap(<SalesOverview data={undefined} loading />);
        expect(screen.getByRole('status')).toHaveTextContent('Loading sales charts');
        unmount();
        const empty = wrap(<SalesOverview data={null} />);
        expect(empty.container.innerHTML).toBe('');
        expect(container).toBeTruthy();
    });
});

describe('chart helpers', () => {
    it('rounds the axis up to a friendly number', () => {
        expect(niceMax(0)).toBeGreaterThan(0);
        expect(niceMax(7)).toBe(10);
        expect(niceMax(42)).toBe(50);
        expect(niceMax(160)).toBe(200);
    });

    it('lists donut shares with one decimal', () => {
        wrap(<DonutChart label="Sources" totalLabel="Leads" data={[{ label: 'A', value: 1 }, { label: 'B', value: 2 }]} />);

        expect(screen.getByText('33.3%')).toBeInTheDocument();
        expect(screen.getByText('66.7%')).toBeInTheDocument();
    });

    it('draws bars with theme-aware colour classes Tailwind can see', () => {
        const { container } = wrap(<ColumnChart label="Bars" data={[{ label: 'A', values: [2] }]} series={[{ name: 'Deals', className: 'fill-ink' }]} />);

        expect(container.querySelector('rect')).toHaveClass('fill-text');
        expect(screen.getByRole('region', { name: 'Bars' })).toHaveAttribute('tabindex', '0');
    });
});
