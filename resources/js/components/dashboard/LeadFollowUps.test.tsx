import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { LeadFollowUps, LeadFollowUpsSkeleton, type FollowUpQueue } from '@/components/dashboard/LeadFollowUps';
import { I18nProvider } from '@/lib/i18n';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

const translations = {
    follow_ups: {
        title: 'Leads to follow up',
        subtitle: 'Leads you can see that need a nudge.',
        queue_leads_overdue: 'Overdue',
        queue_leads_overdue_desc: 'The response clock has run out.',
        queue_leads_due_soon: 'Due in :hours hours',
        queue_leads_due_soon_desc: 'Runs out in the next :hours hours.',
        queue_leads_no_contact: 'No contact for :days days',
        queue_leads_no_contact_desc: 'Quiet for :days days.',
        count_sr: ':count leads',
        queue_empty: 'Nothing here right now.',
        all_clear: 'All caught up.',
        see_all: 'See all :count',
        unassigned: 'Unassigned',
        overdue_by: 'overdue by :time',
        due_in: 'due in :time',
        last_contact: 'last contact :time ago',
        never_contacted: 'never contacted',
        status_assigned: 'Assigned',
        status_contacted: 'Contacted',
        error: 'The follow-up queues could not load.',
        retry: 'Try again',
        loading: 'Loading leads to follow up',
    },
};

function wrap(node: ReactNode) {
    return render(
        <I18nProvider locale="en" dir="ltr" translations={translations as never}>
            {node}
        </I18nProvider>,
    );
}

const queues: FollowUpQueue[] = [
    {
        key: 'leads_overdue',
        href: '/leads?view=risk',
        count: 8,
        items: [
            { id: 1, title: 'Synthetic Lead', meta: 'Hamra', meta_ar: 'الحمرا', href: '/leads?lead=1', tone: 'bad', when: '2026-11-12T09:00:00+02:00', owner: 'Rana Demo', status: 'assigned' },
        ],
    },
    { key: 'leads_due_soon', href: '/leads?view=risk', count: 0, items: [] },
    {
        key: 'leads_no_contact',
        href: '/leads?view=mine',
        count: 1,
        items: [{ id: 2, title: 'Quiet Lead', meta: null, meta_ar: null, href: '/leads?lead=2', tone: 'warn', when: null, owner: null, status: 'contacted' }],
    },
];

describe('LeadFollowUps', () => {
    it('lists each queue with counts, links and clocks', () => {
        wrap(<LeadFollowUps data={{ queues, due_soon_hours: 4, stale_days: 7 }} now={new Date('2026-11-12T12:00:00+02:00')} />);

        const overdue = screen.getByTestId('queue-leads_overdue');
        expect(within(overdue).getByRole('heading', { name: 'Overdue' })).toBeInTheDocument();
        expect(within(overdue).getByText('8 leads')).toBeInTheDocument();
        const link = within(overdue).getByRole('link', { name: /Synthetic Lead/ });
        expect(link).toHaveAttribute('href', '/leads?lead=1');
        expect(link).toHaveTextContent('Hamra');
        expect(link).toHaveTextContent('overdue by 3 hr');
        expect(within(overdue).getByRole('link', { name: 'See all 8' })).toHaveAttribute('href', '/leads?view=risk');
    });

    it('shows per-queue empty text, unassigned owners and never-contacted leads', () => {
        wrap(<LeadFollowUps data={{ queues, due_soon_hours: 4, stale_days: 7 }} />);

        expect(within(screen.getByTestId('queue-leads_due_soon')).getByText('Nothing here right now.')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Due in 4 hours' })).toBeInTheDocument();
        const quiet = screen.getByRole('link', { name: /Quiet Lead/ });
        expect(quiet).toHaveTextContent('Unassigned');
        expect(quiet).toHaveTextContent('never contacted');
    });

    it('shows an all-clear message when nothing needs follow-up', () => {
        wrap(<LeadFollowUps data={{ queues: queues.map((queue) => ({ ...queue, count: 0, items: [] })), due_soon_hours: 4, stale_days: 7 }} />);

        expect(screen.getByRole('status')).toHaveTextContent('All caught up.');
    });

    it('shows error, loading and no-access states', async () => {
        const user = userEvent.setup();
        const retry = vi.fn();
        const errored = wrap(<LeadFollowUps data={{ error: true }} onRetry={retry} />);
        expect(screen.getByRole('alert')).toHaveTextContent('could not load');
        await user.click(screen.getByRole('button', { name: 'Try again' }));
        expect(retry).toHaveBeenCalledOnce();
        errored.unmount();

        const loading = wrap(<LeadFollowUpsSkeleton />);
        expect(screen.getByRole('status')).toHaveTextContent('Loading leads to follow up');
        loading.unmount();

        const none = wrap(<LeadFollowUps data={null} />);
        expect(none.container.innerHTML).toBe('');
    });
});
