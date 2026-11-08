import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { I18nProvider } from '@/lib/i18n';
import { ThemeProvider } from '@/lib/theme';
import Login from '@/Pages/Auth/Login';

const { post, page } = vi.hoisted(() => ({
    post: vi.fn(),
    page: {
        url: '/login',
        props: {
            flash: { status: null as string | null },
        },
    },
}));

vi.mock('gsap', () => ({
    default: {
        context: (run: () => void) => {
            run();
            return { revert: () => undefined };
        },
        fromTo: () => ({}),
        to: () => ({}),
    },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    Head: () => null,
    router: { post: vi.fn() },
    useForm: (initial: { email: string; password: string; remember: boolean }) => {
        const [data, setData] = useState(initial);

        return {
            data,
            setData: (key: string, value: string | boolean) => setData((current) => ({ ...current, [key]: value })),
            post,
            reset: vi.fn(),
            processing: false,
            errors: page.props.flash.status === 'invalid' ? { email: 'Those credentials do not match our records.' } : {},
        };
    },
}));

const copy = {
    a11y: { skip: 'Skip to content' },
    language: { switch: 'Switch language' },
    theme: { switch_to_dark: 'Switch to dark', switch_to_light: 'Switch to light' },
    auth: {
        login_title: 'Sign in',
        login_subtitle: 'Welcome back to FIBES CRM.',
        headline: 'Every customer, ticket and link.',
        headline_accent: 'One calm screen.',
        show_password: 'Show password',
        hide_password: 'Hide password',
        email: 'Email',
        password: 'Password',
        remember: 'Remember this device',
        forgot: 'Forgot password?',
        submit: 'Sign in',
    },
};

const arabic = {
    ...copy,
    auth: {
        ...copy.auth,
        login_title: 'تسجيل الدخول',
        headline: 'كل عميل وتذكرة ووصلة.',
        headline_accent: 'شاشة واحدة هادئة.',
        email: 'البريد الإلكتروني',
        password: 'كلمة المرور',
        remember: 'تذكّر هذا الجهاز',
        forgot: 'نسيت كلمة المرور؟',
        submit: 'دخول',
    },
};

function renderLogin(dir: 'ltr' | 'rtl' = 'ltr') {
    const tree = dir === 'rtl' ? arabic : copy;

    return render(
        <I18nProvider locale={dir === 'rtl' ? 'ar' : 'en'} dir={dir} translations={tree}>
            <ThemeProvider>
                <div dir={dir}>
                    <Login />
                </div>
            </ThemeProvider>
        </I18nProvider>,
    );
}

function matchMedia(matches: (query: string) => boolean) {
    window.matchMedia = (query: string) =>
        ({
            matches: matches(query),
            media: query,
            onchange: null,
            addListener: () => undefined,
            removeListener: () => undefined,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
            dispatchEvent: () => false,
        }) as MediaQueryList;
}

describe('sign-in screen', () => {
    it('keeps the fields, names, remember-me, and forgot-password link', async () => {
        matchMedia((query) => query.includes('prefers-reduced-motion'));
        page.props.flash.status = null;
        const user = userEvent.setup();
        renderLogin();

        expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
        expect(screen.getByText('Welcome back to FIBES CRM.')).toBeInTheDocument();
        expect(screen.getByText('FIBES CRM')).toBeInTheDocument();

        const email = screen.getByLabelText('Email');
        const password = screen.getByLabelText('Password');
        expect(email).toHaveAttribute('name', 'email');
        expect(email).toHaveAttribute('type', 'email');
        expect(email).toHaveAttribute('autocomplete', 'username');
        expect(password).toHaveAttribute('name', 'password');
        expect(password).toHaveAttribute('autocomplete', 'current-password');
        expect(screen.getByRole('checkbox', { name: 'Remember this device' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Forgot password?' })).toHaveAttribute('href', '/forgot-password');
        expect(screen.getByTestId('signin-form')).toBeInTheDocument();
        expect(screen.getByTestId('guest-particles')).toHaveAttribute('data-motion', 'off');
        expect(screen.getByTestId('guest-particles')).toHaveAttribute('aria-hidden', 'true');

        const logos = screen.getAllByTestId('brand-logo');
        expect(logos[0]).toHaveAttribute('dir', 'ltr');
        expect(logos[0].querySelector('img')).toHaveAttribute('src', '/brand/logo-full-on-dark.png');

        await user.type(email, 'ada@fibes.test');
        await user.type(password, 'secret-pass');
        await user.click(screen.getByRole('checkbox', { name: 'Remember this device' }));
        await user.click(screen.getByRole('button', { name: 'Sign in' }));

        expect(post).toHaveBeenCalledWith('/login', expect.objectContaining({ preserveScroll: true }));
        expect(email).toHaveValue('ada@fibes.test');
        expect(password).toHaveValue('secret-pass');
    });

    it('shows the validation message and stays left-to-right in Arabic', () => {
        matchMedia((query) => query.includes('prefers-reduced-motion'));
        page.props.flash.status = 'invalid';
        renderLogin('rtl');

        expect(screen.getByRole('heading', { name: 'تسجيل الدخول' })).toBeInTheDocument();
        expect(screen.getByRole('alert')).toHaveTextContent('Those credentials do not match our records.');
        expect(screen.getByLabelText('البريد الإلكتروني')).toHaveAttribute('dir', 'ltr');
        expect(screen.getByLabelText('البريد الإلكتروني').parentElement).toHaveAttribute('dir', 'ltr');
        expect(screen.getByLabelText('كلمة المرور')).toHaveAttribute('dir', 'ltr');
        expect(screen.getByLabelText('كلمة المرور').parentElement).toHaveAttribute('dir', 'ltr');
        expect(screen.getByRole('link', { name: 'نسيت كلمة المرور؟' })).toHaveAttribute('href', '/forgot-password');
        for (const logo of screen.getAllByTestId('brand-logo')) {
            expect(logo).toHaveAttribute('dir', 'ltr');
        }
    });
});
