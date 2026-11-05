import { Head, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Eye, EyeOff, Lock, Mail } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { Button } from '@/components/ui/button';
import { GuestLayout } from '@/layouts/GuestLayout';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

export default function Login() {
    const { t } = useI18n();
    const { flash } = usePage<SharedProps>().props;
    const [show, setShow] = useState(false);
    const form = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/login', {
            preserveScroll: true,
            onFinish: () => form.reset('password'),
        });
    };

    return (
        <GuestLayout mode="auth">
            <Head title={t('auth.login_title')} />
            <h1 className="text-center text-lg font-medium tracking-tight">{t('auth.login_subtitle')}</h1>
            {flash.status ? (
                <p role="status" className="mt-4 rounded-tile bg-well px-3 py-2 text-sm text-text ring-1 ring-line">
                    {flash.status}
                </p>
            ) : null}
            <form onSubmit={submit} className="mt-6 grid gap-3" noValidate data-testid="signin-form">
                <div className="glass-field">
                    <label htmlFor="email" className="text-xs font-medium text-muted">
                        {t('auth.email')}
                    </label>
                    <div className="relative mt-1">
                        <Mail className="pointer-events-none absolute start-0 top-1/2 size-4 -translate-y-1/2 text-aqua" />
                        <input
                            id="email"
                            type="email"
                            name="email"
                            autoComplete="username"
                            dir="ltr"
                            placeholder={t('auth.email_hint')}
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                            required
                            aria-invalid={Boolean(form.errors.email)}
                            aria-describedby={form.errors.email ? 'email-error' : undefined}
                            className="h-8 w-full bg-transparent ps-7 text-sm text-text outline-none placeholder:text-faint"
                        />
                    </div>
                </div>
                {form.errors.email ? (
                    <p id="email-error" role="alert" className="text-sm text-bad">
                        {form.errors.email}
                    </p>
                ) : null}
                <div className="glass-field">
                    <label htmlFor="password" className="text-xs font-medium text-muted">
                        {t('auth.password')}
                    </label>
                    <div className="relative mt-1">
                        <Lock className="pointer-events-none absolute start-0 top-1/2 size-4 -translate-y-1/2 text-aqua" />
                        <input
                            id="password"
                            type={show ? 'text' : 'password'}
                            name="password"
                            autoComplete="current-password"
                            dir="ltr"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            required
                            aria-invalid={Boolean(form.errors.password)}
                            aria-describedby={form.errors.password ? 'password-error' : undefined}
                            className="h-8 w-full bg-transparent pe-8 ps-7 text-sm text-text outline-none"
                        />
                        <button
                            type="button"
                            onClick={() => setShow((current) => !current)}
                            aria-label={show ? t('auth.hide_password') : t('auth.show_password')}
                            className="absolute end-0 top-1/2 grid size-7 -translate-y-1/2 place-items-center rounded-md text-faint hover:text-text"
                        >
                            {show ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                        </button>
                    </div>
                </div>
                {form.errors.password ? (
                    <p id="password-error" role="alert" className="text-sm text-bad">
                        {form.errors.password}
                    </p>
                ) : null}
                <div className="flex items-center justify-between gap-3 px-1 text-sm">
                    <label className="inline-flex items-center gap-2 text-muted">
                        <input type="checkbox" className="size-4 rounded accent-[var(--brand-mint)]" checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)} />
                        {t('auth.remember')}
                    </label>
                    <a href="/forgot-password" className="font-semibold text-accent-ink hover:underline">
                        {t('auth.forgot')}
                    </a>
                </div>
                <Button type="submit" variant="gradient" size="lg" className="mt-1 h-12 w-full rounded-2xl text-base" disabled={form.processing}>
                    {t('auth.submit')} <ArrowRight className="flip-rtl" />
                </Button>
            </form>
        </GuestLayout>
    );
}
