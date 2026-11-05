import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState, type ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { I18nProvider } from '@/lib/i18n';
import { ThemeProvider } from '@/lib/theme';
import type { SharedProps } from '@/types';
import { CommandPalette } from './CommandPalette';
import { LanguageSwitch } from './LanguageSwitch';
import { Sidebar } from './Sidebar';
import { ThemeToggle } from './ThemeToggle';

const { page, router } = vi.hoisted(() => ({
    router: { visit: vi.fn(), post: vi.fn(), delete: vi.fn() },
    page: {
        url: '/dashboard',
        props: {} as SharedProps,
    },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router,
}));

const copy: SharedProps['translations'] = {
    nav: {
        workspace: 'Workspace',
        money: 'Money',
        field: 'Field',
        admin_group: 'Admin',
        sales: 'Sales',
        dashboard: 'Dashboard',
        customers: 'Customers',
        users: 'Users',
        roles: 'Roles',
        billing: 'Billing',
        tickets: 'Tickets',
        collapse: 'Collapse sidebar',
        expand: 'Expand sidebar',
        close_menu: 'Close menu',
        open_menu: 'Open menu',
    },
    a11y: { main: 'Main', skip: 'Skip' },
    shell: { desk: 'ISP CRM', pulse: 'Network pulse', pulse_quiet: 'No live network feed on this desk' },
    command: {
        placeholder: 'Search actions',
        empty: 'No matching actions',
        navigation: 'Navigation',
        preferences: 'Preferences',
        account: 'Account',
        hint: 'Command palette',
    },
    theme: { switch_to_light: 'Switch to light mode', switch_to_dark: 'Switch to dark mode', toggle: 'Toggle color theme' },
    language: { en: 'English', ar: 'Arabic', switch: 'Language' },
    user_menu: { account: 'Account security', logout: 'Log out' },
};

function shell(node: ReactNode, dir: 'ltr' | 'rtl' = 'ltr') {
    return render(
        <I18nProvider locale={dir === 'rtl' ? 'ar' : 'en'} dir={dir} translations={copy}>
            <ThemeProvider>{node}</ThemeProvider>
        </I18nProvider>,
    );
}

beforeEach(() => {
    document.documentElement.classList.remove('dark');
    document.documentElement.dir = 'ltr';
    localStorage.clear();
    router.post.mockReset();
    page.url = '/dashboard';
    page.props = {
        locale: 'en',
        dir: 'ltr',
        translations: copy,
        auth: {
            user: { id: 1, name: 'Sam', email: 'sam@fibes.test', locale: 'en', two_factor_enabled: false, role: 'super_admin' },
            nav: ['dashboard', 'customers', 'users', 'roles'],
            view_as: null,
        },
        flash: {},
        security: { must_change_password: false, two_factor_grace_days: null },
        app: { name: 'FIBES' },
    };
});

describe('sidebar navigation', () => {
    it('shows only the pages the role is allowed to open', () => {
        shell(<Sidebar collapsed={false} mobileOpen={false} onToggle={() => undefined} onClose={() => undefined} />);

        expect(screen.getByRole('link', { name: 'Users' })).toHaveAttribute('href', '/admin/users');
        expect(screen.getByRole('link', { name: 'Customers' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Billing' })).not.toBeInTheDocument();
        expect(screen.getByTestId('brand-logo')).toHaveAttribute('dir', 'ltr');
    });

    it('hides admin pages for a field technician', () => {
        page.props.auth.nav = ['dashboard', 'customers', 'tickets', 'tech'];
        page.props.auth.user = { ...page.props.auth.user!, role: 'field_technician' };
        shell(<Sidebar collapsed={false} mobileOpen={false} onToggle={() => undefined} onClose={() => undefined} />);

        expect(screen.getByRole('link', { name: 'Tickets' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Users' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Roles' })).not.toBeInTheDocument();
    });

    it('collapses to the icon and still does not mirror the logo', async () => {
        const user = userEvent.setup();
        document.documentElement.dir = 'rtl';
        page.props.dir = 'rtl';
        page.props.locale = 'ar';

        function Harness() {
            const [collapsed, setCollapsed] = useState(false);

            return <Sidebar collapsed={collapsed} mobileOpen={false} onToggle={() => setCollapsed((current) => !current)} onClose={() => undefined} />;
        }

        shell(<Harness />, 'rtl');

        expect(screen.getByTestId('brand-logo')).toHaveAttribute('dir', 'ltr');
        await user.click(screen.getByTestId('sidebar-collapse'));
        expect(screen.queryByTestId('brand-logo')).not.toBeInTheDocument();
        expect(document.querySelector('[data-brand="icon"]')).toHaveAttribute('dir', 'ltr');
    });
});

describe('command palette', () => {
    it('opens with the button and with Ctrl+K, and lists only allowed pages', async () => {
        const user = userEvent.setup();
        shell(<CommandPalette />);

        await user.click(screen.getByTestId('command-trigger'));
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByText('Users')).toBeInTheDocument();
        expect(screen.queryByText('Billing')).not.toBeInTheDocument();

        await user.keyboard('{Control>}k{/Control}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});

describe('theme and language', () => {
    it('switches light and dark', async () => {
        const user = userEvent.setup();
        shell(<ThemeToggle />);

        await user.click(screen.getByTestId('theme-toggle'));
        expect(document.documentElement).toHaveClass('dark');
        expect(localStorage.getItem('fibes-theme')).toBe('dark');

        await user.click(screen.getByTestId('theme-toggle'));
        expect(document.documentElement).not.toHaveClass('dark');
        expect(localStorage.getItem('fibes-theme')).toBe('light');
    });

    it('posts the chosen language', async () => {
        const user = userEvent.setup();
        shell(<LanguageSwitch />);

        await user.click(screen.getByTestId('locale-ar'));
        expect(router.post).toHaveBeenCalledWith('/locale', { locale: 'ar' }, { preserveScroll: true });
    });
});
