import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { FunnelLeaderboard, FunnelLeaderboardSkeleton, type FunnelData, type LeaderboardData } from '@/components/dashboard/FunnelLeaderboard';
import { I18nProvider } from '@/lib/i18n';

const translations = {
    funnel_dash: {
        title: 'Funnel and leaderboard',
        funnel: 'Opportunity funnel',
        funnel_desc: ':count opportunities opened in the period',
        pipeline: 'Pipeline',
        now_in_stage: ':count in this stage now',
        conversion: ':pct% from the stage before',
        lost: ':count lost',
        win_rate: ':pct% reached won',
        col_stage: 'Stage',
        col_reached: 'Reached',
        col_now: 'In stage now',
        col_conversion: 'From stage before',
        leaderboard: 'Owner leaderboard',
        leaderboard_desc: 'Won deal value in the period',
        col_owner: 'Owner',
        col_deals: 'Won',
        col_total: 'Won value',
        col_win_rate: 'Win rate',
        col_open: 'Open now',
        unknown_owner: 'Unassigned',
        show_table: 'Show as table',
        show_chart: 'Show as chart',
        empty: 'No opportunities were opened in this period.',
        leaderboard_empty: 'No deals were won in this period.',
        error: 'The funnel and leaderboard could not load.',
        retry: 'Try again',
        loading: 'Loading the funnel and leaderboard',
    },
};

function wrap(node: ReactNode) {
    return render(
        <I18nProvider locale="en" dir="ltr" translations={translations as never}>
            {node}
        </I18nProvider>,
    );
}

const funnel: FunnelData = {
    pipeline_id: 1,
    pipelines: [
        { id: 1, label: 'Retail' },
        { id: 2, label: 'Business' },
    ],
    cohort: 6,
    lost: 2,
    win_rate: 16.7,
    stages: [
        { id: 11, key: 'feasibility', label: 'Feasibility', sort: 10, is_won: false, reached: 6, now: 1, now_value: '10.00', conversion: null },
        { id: 12, key: 'site_survey', label: 'Site survey', sort: 20, is_won: false, reached: 4, now: 1, now_value: '10.00', conversion: 66.7 },
        { id: 15, key: 'won', label: 'Won', sort: 50, is_won: true, reached: 1, now: 1, now_value: '10.00', conversion: 25 },
    ],
};

const leaderboard: LeaderboardData = {
    rows: [
        { user_id: 7, name: 'Karim Synthetic', deals: 1, total: '90.00', lost: 0, win_rate: null, open: 0, open_value: '0.00' },
        { user_id: 8, name: '', deals: 2, total: '60.00', lost: 2, win_rate: 33.3, open: 1, open_value: '33.00' },
    ],
};

describe('FunnelLeaderboard', () => {
    it('draws each stage with reached counts and conversion', () => {
        wrap(<FunnelLeaderboard data={{ funnel, leaderboard }} />);

        const survey = screen.getByTestId('funnel-stage-site_survey');
        expect(within(survey).getByText('Site survey')).toBeInTheDocument();
        expect(survey).toHaveTextContent('66.7% from the stage before');
        expect(screen.getByTestId('funnel-card')).toHaveTextContent('2 lost');
        expect(screen.getByTestId('funnel-card')).toHaveTextContent('16.7% reached won');
    });

    it('switches the pipeline and toggles a table view', async () => {
        const user = userEvent.setup();
        const onPipeline = vi.fn();
        wrap(<FunnelLeaderboard data={{ funnel, leaderboard }} onPipeline={onPipeline} />);

        await user.selectOptions(screen.getByLabelText('Pipeline'), '2');
        expect(onPipeline).toHaveBeenCalledWith(2);

        const card = screen.getByTestId('funnel-card');
        await user.click(within(card).getByRole('button', { name: 'Show as table' }));
        const table = within(card).getByRole('table');
        expect(within(table).getByRole('rowheader', { name: 'Won' })).toBeInTheDocument();
        expect(within(table).getAllByText('—')).toHaveLength(1);
    });

    it('ranks owners and names unassigned rows', () => {
        wrap(<FunnelLeaderboard data={{ funnel: null, leaderboard }} />);

        expect(screen.queryByTestId('funnel-card')).not.toBeInTheDocument();
        const second = screen.getByTestId('leader-8');
        expect(within(second).getByRole('rowheader')).toHaveTextContent('Unassigned');
        expect(second).toHaveTextContent('33.3%');
        expect(screen.getByTestId('leader-7')).toHaveTextContent('90');
    });

    it('shows empty, error and loading states', async () => {
        const user = userEvent.setup();
        const retry = vi.fn();
        const { unmount } = wrap(<FunnelLeaderboard data={{ funnel: { ...funnel, cohort: 0 }, leaderboard: { rows: [] } }} />);
        expect(screen.getByText('No opportunities were opened in this period.')).toBeInTheDocument();
        expect(screen.getByText('No deals were won in this period.')).toBeInTheDocument();
        unmount();

        const errored = wrap(<FunnelLeaderboard data={{ error: true }} onRetry={retry} />);
        expect(screen.getByRole('alert')).toHaveTextContent('could not load');
        await user.click(screen.getByRole('button', { name: 'Try again' }));
        expect(retry).toHaveBeenCalledOnce();
        errored.unmount();

        wrap(<FunnelLeaderboardSkeleton />);
        expect(screen.getByRole('status')).toHaveTextContent('Loading the funnel and leaderboard');
    });
});
