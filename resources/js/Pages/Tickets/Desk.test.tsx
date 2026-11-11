import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { I18nProvider } from '@/lib/i18n';
import { ThemeProvider } from '@/lib/theme';
import TicketsDesk from '@/Pages/Tickets/Desk';

const translations = {
    a11y: { loading: 'Loading', skip: 'Skip to content', main: 'Main' },
    nav: {
        close_menu: 'Close',
        open_menu: 'Open menu',
        collapse: 'Collapse sidebar',
        expand: 'Expand sidebar',
        workspace: 'Workspace',
        money: 'Money',
        field: 'Field',
        admin_group: 'Admin',
        sales: 'Sales',
        dashboard: 'Dashboard',
        customers: 'Customers',
        tickets: 'Tickets',
        support_desk: 'Support desk',
    },
    theme: { switch_to_light: 'Switch to light mode', switch_to_dark: 'Switch to dark mode', toggle: 'Toggle color theme' },
    language: { en: 'English', ar: 'Arabic', switch: 'Language' },
    user_menu: { account: 'Account security', logout: 'Log out', signed_in: 'Signed in' },
    command: { placeholder: 'Search actions', empty: 'No matching actions', navigation: 'Navigation', preferences: 'Preferences', account: 'Account', hint: 'Command palette', theme: 'Theme', language: 'Language' },
    filters: { any: 'Any' },
    customers: {
        area: 'Area',
        service_type: 'Service',
        account: 'Account',
        phone: 'Phone',
        email: 'Email',
        whatsapp: 'WhatsApp',
        fiber: 'Fiber',
    },
    tickets: {
        title: 'Tickets',
        desk_title: 'Support desk',
        desk_subtitle: 'Reply to customers and watch waiting times.',
        tab_inbox: 'Inbox',
        tab_queue: 'Reply queue',
        tab_insights: 'Insights',
        search: 'Search number or subject',
        status: 'Status',
        priority: 'Priority',
        channel: 'Channel',
        category: 'Category',
        sla: 'SLA',
        mine: 'My tickets',
        awaiting: 'Awaiting reply',
        suggestions: 'Suggested replies',
        use_suggestion: 'Use this reply',
        view_customer: 'View customer',
        open_profile: 'Open customer record',
        volume: 'Volume',
        response_times: 'Response times',
        avg_response: 'Average first response',
        opened: 'Opened',
        breached: 'Breached',
        minutes: 'minutes',
        queue_empty: 'Nothing is waiting for a reply.',
        inbox_empty: 'No tickets in the inbox.',
        insights_empty: 'No ticket activity in this period.',
        load_error: 'The desk could not be refreshed.',
        retry: 'Try again',
        reply: 'Reply',
        public: 'Customer-facing',
        internal: 'Internal',
        response: 'Response',
        resolve: 'Resolve',
        paused: 'Paused',
        breached_status: 'Breached',
        met: 'Met',
        warning: 'At risk',
        status_new: 'New',
        status_open: 'Open',
        status_pending_customer: 'Pending customer',
        status_escalated: 'With technician',
        status_resolved: 'Resolved',
        status_closed: 'Closed',
        status_reopened: 'Reopened',
        channel_phone: 'Phone',
        channel_whatsapp: 'WhatsApp',
        channel_walk_in: 'Walk-in',
        channel_email: 'Email',
        channel_field: 'Field',
        back_to_inbox: 'Back to inbox',
        pick_ticket: 'Select a ticket to read and reply.',
        escalate: 'Move up',
        deescalate: 'Send back',
        automatic: 'Automatic',
    },
};

const ticket = {
    id: 11,
    number: 'TK-000011',
    subject: 'No light on the ONT',
    status: 'new',
    priority: 'P1',
    channel: 'phone',
    assignee: 'Ahmad',
    customer: { id: 4, name_en: 'Rami Khalil', name_ar: 'رامي خليل', account_no: 'A-1001' },
    area: { name_en: 'Achrafieh', name_ar: 'الأشرفية' },
    category: { id: 1, key: 'no_internet', name_en: 'No internet', name_ar: 'لا إنترنت' },
    sla: {
        response: { state: 'ok', remaining_seconds: 600 },
        resolve: { state: 'ok', remaining_seconds: 3600 },
    },
    last_message: { body: 'The ONT is dark.', at: '2026-11-12T08:00:00+02:00', visibility: 'public', author: 'Ahmad' },
    awaiting_reply: true,
    suggestions: [
        { id: 'ack', label: 'Acknowledge', body: 'Hello Rami Khalil, we have TK-000011.' },
        { id: 'next', label: 'Next step', body: 'Hello Rami Khalil, please check the lights on the box.' },
    ],
    messages: [
        { id: 1, body: 'The ONT is dark.', visibility: 'public', author: 'Ahmad', at: '2026-11-12T08:00:00+02:00' },
    ],
};

const insights = {
    period: { from: '2026-10-30', to: '2026-11-12', timezone: 'Asia/Beirut' },
    totals: { opened: 1, awaiting_reply: 1, avg_first_response_minutes: null, sla_breached: 0 },
    volume: [
        { date: '2026-11-11', count: 0 },
        { date: '2026-11-12', count: 1 },
    ],
    response_times: [
        { date: '2026-11-11', avg_minutes: null, responded: 0 },
        { date: '2026-11-12', avg_minutes: null, responded: 0 },
    ],
};

const emptyFilters = {
    q: '',
    status: '',
    priority: '',
    assignee_id: null,
    area_id: null,
    service_type: '',
    sla: '',
    mine: false,
    category_id: null,
    channel: '',
};

const { post, page } = vi.hoisted(() => ({
    post: vi.fn(),
    page: {
        url: '/tickets/desk',
        props: {
            locale: 'en',
            dir: 'ltr',
            flash: { status: null as string | null },
            auth: {
                user: { id: 1, name: 'Ahmad', email: 'ahmad@fibes.test', locale: 'en', two_factor_enabled: false, role: 'super_admin' },
                nav: ['dashboard', 'customers', 'tickets', 'support_desk'],
                abilities: { tickets_create: true, customers_create: true, tasks_create: false, calls: false },
                view_as: null,
            },
            security: { must_change_password: false, two_factor_grace_days: null },
            app: { name: 'FIBES' },
            notifications: { unread: 0, items: [] },
        },
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: string }) => <a href={href}>{children}</a>,
    usePage: () => page,
    router: { get: vi.fn(), post: vi.fn() },
    useForm: (initial: { body: string; visibility: string }) => {
        const [data, setData] = useState(initial);

        return {
            data,
            setData: (key: string, value: string) => {
                if (typeof key === 'object') {
                    setData(() => key as typeof initial);
                    return;
                }
                setData((current) => ({ ...current, [key]: value }));
            },
            post,
            processing: false,
            errors: {},
        };
    },
}));

function renderDesk(overrides: Record<string, unknown> = {}) {
    return render(
        <I18nProvider locale="en" dir="ltr" translations={translations}>
            <ThemeProvider>
            <TicketsDesk
                inbox={[ticket]}
                filters={emptyFilters}
                categories={[{ id: 1, name_en: 'No internet', name_ar: 'لا إنترنت' }]}
                areas={[{ id: 1, name_en: 'Achrafieh', name_ar: 'الأشرفية' }]}
                queue={[ticket]}
                selected={ticket}
                customer={{
                    id: 4,
                    account_no: 'A-1001',
                    type: 'individual',
                    status: 'active',
                    name_en: 'Rami Khalil',
                    name_ar: 'رامي خليل',
                    phones: ['03 123 456'],
                    open_tickets: 1,
                }}
                insights={insights}
                can_reply
                {...overrides}
            />
            </ThemeProvider>
        </I18nProvider>,
    );
}

describe('Tickets desk', () => {
    it('shows inbox, queue and insights states', async () => {
        const user = userEvent.setup();
        renderDesk();

        expect(screen.getByRole('heading', { name: 'Support desk' })).toBeInTheDocument();
        expect(screen.getAllByText('TK-000011').length).toBeGreaterThan(0);
        expect(screen.getAllByText('The ONT is dark.').length).toBeGreaterThan(0);

        await user.click(screen.getByRole('tab', { name: /Reply queue/ }));
        expect(screen.getAllByText('Suggested replies').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Hello Rami Khalil, we have TK-000011.').length).toBeGreaterThan(0);

        await user.click(screen.getByRole('tab', { name: 'Insights' }));
        expect(screen.getByRole('img', { name: /Volume/ })).toBeInTheDocument();
        expect(screen.getByRole('img', { name: /Response times/ })).toBeInTheDocument();
    });

    it('fills the reply box from a suggested reply and opens the customer drawer', async () => {
        const user = userEvent.setup();
        renderDesk();

        await user.click(screen.getAllByRole('button', { name: /Use this reply/ })[0]);
        expect(screen.getByLabelText('Reply')).toHaveValue('Hello Rami Khalil, we have TK-000011.');

        await user.click(screen.getByRole('button', { name: 'View customer' }));
        expect(screen.getAllByText('A-1001').length).toBeGreaterThan(0);
        expect(screen.getByRole('link', { name: 'Open customer record' })).toHaveAttribute('href', '/customers/4');
    });

    it('shows empty inbox and queue messages', () => {
        renderDesk({
            inbox: [],
            queue: [],
            selected: null,
            customer: null,
            insights: { ...insights, totals: { opened: 0, awaiting_reply: 0, avg_first_response_minutes: null, sla_breached: 0 }, volume: [], response_times: [] },
        });

        expect(screen.getByText('No tickets in the inbox.')).toBeInTheDocument();
        expect(screen.getByText('Nothing is waiting for a reply.')).toBeInTheDocument();
    });
});
