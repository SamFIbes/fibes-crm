import { render, screen } from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { I18nProvider } from '@/lib/i18n';
import { ThemeProvider } from '@/lib/theme';
import IntegrationsPage from '@/Pages/Settings/Integrations';
import type { SharedProps } from '@/types';

const { page } = vi.hoisted(() => ({
    page: { url: '/settings/integrations', props: {} as SharedProps },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
    router: { post: vi.fn(), visit: vi.fn() },
    useForm: (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);
        return {
            data,
            setData: (key: string, value: unknown) => setData((current) => ({ ...current, [key]: value })),
            put: vi.fn(),
            processing: false,
            errors: {},
        };
    },
}));

const copy = {
    integrations: {
        title: 'Integrations',
        subtitle: 'Hub ERP and the other connections.',
        erp_title: 'Hub ERP',
        erp_body: 'Draft Material Request for Smart Network.',
        krypton_title: 'Krypton Radius',
        krypton_body: 'CSV import stays.',
        whish_title: 'Whish',
        whish_body: 'Typed by hand.',
        pbx_title: 'Grandstream PBX',
        pbx_body: 'Secret gate.',
        whatsapp_title: 'WhatsApp Business',
        whatsapp_body: 'wa.me only.',
        enabled: 'Enabled on this desk',
        status_never_connected: 'Never connected',
        status_configured: 'Configured',
        status_synced: 'Last sync',
        status_error: 'Last error',
        secrets: 'Environment secrets',
        secret_help: 'The value is never shown here.',
        secret_missing: 'Not set',
        secret_set: 'Set in the environment',
        off_reason_credentials: 'API credentials are not set.',
        off_reason_missing: 'The environment secret is not set.',
        company: 'Company',
        url: 'Base URL',
        warehouse: 'Default warehouse',
        purpose: 'Purpose',
        purpose_purchase: 'Purchase',
        purpose_transfer: 'Material transfer',
        purpose_issue: 'Material issue',
        phone: 'Phone number ID',
        test: 'Test connection',
        docs: 'How it connects',
        save: 'Save integrations',
        map_title: 'What maps where',
        map_company: 'Company is Smart Network.',
        map_item: 'Item code matches the SKU.',
        map_warehouse: 'Warehouse name matches a location.',
        map_request: 'A draft Material Request is the stock request.',
        map_links: 'Links stay in the description.',
        map_asset: 'Assets are read only.',
        map_expense: 'An expense stays in FIBES.',
        map: 'Warehouse mapping',
        map_help: 'Leave a row blank to use the default.',
    },
    settings_page: { title: 'Settings' },
    a11y: { main: 'Main', skip: 'Skip' },
    nav: { close_menu: 'Close', open_menu: 'Open', workspace: 'Workspace', money: 'Money', field: 'Field', admin_group: 'Admin', sales: 'Sales' },
    shell: { desk: 'ISP CRM', pulse: 'Network pulse', pulse_quiet: 'Quiet' },
    command: { placeholder: 'Search', empty: 'None', navigation: 'Navigation', preferences: 'Preferences', account: 'Account', hint: 'Hint' },
    theme: { switch_to_light: 'Light', switch_to_dark: 'Dark', toggle: 'Theme' },
    language: { en: 'English', ar: 'Arabic', switch: 'Language' },
    user_menu: { account: 'Account', logout: 'Log out' },
    notifications: { open: 'Notices', title: 'Notices', mark_all: 'Mark', empty: 'None' },
};

const card = {
    key: 'erpnext',
    enabled: false,
    url: 'https://erp.thehubholding.com',
    company: 'Smart Network',
    warehouse: '',
    purpose: 'Purchase',
    phone_number_id: '',
    warehouses: [],
    live: false,
    flag: false,
    secrets: [
        { name: 'FIBES_ERPNEXT_API_KEY', set: false },
        { name: 'FIBES_ERPNEXT_API_SECRET', set: false },
    ],
    reason: 'credentials',
    status: 'never_connected' as const,
    last_sync_at: null,
    last_error: null,
    docs: '#erpnext',
};

describe('Integrations desk', () => {
    it('shows Hub ERP as never connected and does not use a frosted field', () => {
        page.props = {
            locale: 'en',
            dir: 'ltr',
            translations: copy,
            auth: { user: { id: 1, name: 'Sam Admin', email: 'a@example.com', locale: 'en', two_factor_enabled: false, role: 'super_admin' }, nav: ['dashboard', 'integrations'], view_as: null },
            flash: {},
            security: { must_change_password: false, two_factor_grace_days: null },
            app: { name: 'FIBES' },
        };
        render(
            <I18nProvider locale="en" dir="ltr" translations={copy}>
                <ThemeProvider>
                    <IntegrationsPage cards={[card, { ...card, key: 'krypton', secrets: [{ name: 'FIBES_RADIUS_API_KEY', set: false }] }]} locations={[]} can_manage />
                </ThemeProvider>
            </I18nProvider>,
        );

        const desk = screen.getByTestId('integrations-page');
        expect(screen.getByRole('heading', { name: 'Integrations' })).toBeInTheDocument();
        expect(screen.getByDisplayValue('Smart Network')).toBeInTheDocument();
        expect(screen.getAllByText('Never connected').length).toBeGreaterThan(0);
        expect(screen.getByText('API credentials are not set.')).toBeInTheDocument();
        expect(screen.getByText('The environment secret is not set.')).toBeInTheDocument();
        expect(desk.innerHTML).not.toContain('glass-field');
        expect(desk.innerHTML).not.toContain('backdrop-blur');
        expect(desk.innerHTML).not.toContain('live-secret-value');
    });
});
