import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { CampaignResults, CampaignResultsSkeleton, type CampaignData, type CampaignRow } from '@/components/dashboard/CampaignResults';
import { I18nProvider } from '@/lib/i18n';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

const translations = {
    campaign_results: {
        title: 'Campaign results',
        table: 'Campaign results table',
        subtitle: 'Leads created :from to :to',
        manage: 'Manage campaigns',
        col_campaign: 'Campaign',
        col_leads: 'Leads',
        col_opportunities: 'Opportunities',
        col_won: 'Won',
        col_won_value: 'Won value',
        col_conversion: 'Lead to won',
        col_cost_per_lead: 'Cost per lead',
        col_cost_per_won: 'Cost per win',
        to_opportunity: ':pct to an opportunity',
        compare: 'Campaign leads reached won :pct of the time, against :other for the :count leads with no campaign.',
        total: 'All campaigns',
        empty: 'No leads from a campaign in this period.',
        error: 'Campaign results could not load.',
        retry: 'Try again',
        loading: 'Loading campaign results',
    },
};

function wrap(node: ReactNode, locale: 'en' | 'ar' = 'en') {
    return render(
        <I18nProvider locale={locale} dir={locale === 'ar' ? 'rtl' : 'ltr'} translations={translations as never}>
            {node}
        </I18nProvider>,
    );
}

const spring: CampaignRow = {
    id: 3, name: 'Spring synthetic', channel: 'campaign', starts_on: '2026-10-01', ends_on: null, budget: '300.00', currency: 'USD',
    leads: 3, opportunities: 2, won: 2, won_value: '75.00', lead_to_opportunity: 66.7, lead_to_won: 66.7, cost_per_lead: '100.00', cost_per_won: '150.00',
};
const flyers: CampaignRow = {
    ...spring, id: 4, name: 'Flyers synthetic', budget: null, leads: 1, opportunities: 1, won: 0, won_value: '0.00', lead_to_opportunity: 100, lead_to_won: 0, cost_per_lead: null, cost_per_won: null,
};
const base: CampaignData = {
    from: '2026-11-01',
    to: '2026-11-12',
    rows: [spring, flyers],
    totals: { leads: 4, opportunities: 3, won: 2, won_value: '75.00', lead_to_opportunity: 75, lead_to_won: 50 },
    uncampaigned: { leads: 2, lead_to_won: 50 },
    manage_href: '/leads',
};

describe('CampaignResults', () => {
    it('shows totals, a comparison and one row per campaign', () => {
        wrap(<CampaignResults data={base} />);

        expect(screen.getByRole('heading', { name: 'Campaign results' })).toBeInTheDocument();
        expect(screen.getByText(/reached won 50% of the time, against 50% for the 2 leads/)).toBeInTheDocument();
        const row = screen.getByTestId('campaign-3');
        expect(within(row).getByRole('rowheader')).toHaveTextContent('Spring synthetic');
        expect(row).toHaveTextContent('66.7%');
        expect(row).toHaveTextContent('100 USD');
        expect(row).toHaveTextContent('150 USD');
        expect(screen.getByTestId('campaign-4')).toHaveTextContent('—');
        expect(screen.getByRole('link', { name: 'Manage campaigns' })).toHaveAttribute('href', '/leads');
        expect(screen.getByRole('region', { name: 'Campaign results table' })).toHaveAttribute('tabindex', '0');
    });

    it('hides the manage link without permission and shows the empty state', () => {
        wrap(<CampaignResults data={{ ...base, rows: [], manage_href: null }} />);

        expect(screen.queryByRole('link', { name: 'Manage campaigns' })).not.toBeInTheDocument();
        expect(screen.queryByText(/reached won/)).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent('No leads from a campaign in this period.');
    });

    it('uses Arabic digits throughout in Arabic', () => {
        wrap(<CampaignResults data={base} />, 'ar');

        expect(screen.getByTestId('campaign-3')).toHaveTextContent('٣');
        expect(screen.getByText(/reached won/).textContent).not.toMatch(/[0-9]/);
    });

    it('shows error, loading and no-access states', async () => {
        const user = userEvent.setup();
        const retry = vi.fn();
        const errored = wrap(<CampaignResults data={{ error: true }} onRetry={retry} />);
        expect(screen.getByRole('alert')).toHaveTextContent('could not load');
        await user.click(screen.getByRole('button', { name: 'Try again' }));
        expect(retry).toHaveBeenCalledOnce();
        errored.unmount();

        const loading = wrap(<CampaignResultsSkeleton />);
        expect(screen.getByRole('status')).toHaveTextContent('Loading campaign results');
        loading.unmount();

        expect(wrap(<CampaignResults data={undefined} />).container.innerHTML).toBe('');
    });
});
