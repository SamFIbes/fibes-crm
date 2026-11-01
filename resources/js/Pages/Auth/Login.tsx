import { Head, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Eye, EyeOff, Lock, Mail } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { Field } from '@/components/form/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
        <GuestLayout>
            <Head title={t('auth.login_title')} />
            <h1 className="text-3xl font-semibold tracking-tight">{t('auth.login_title')}</h1>
            <p className="mt-1.5 text-md text-muted">{t('auth.login_subtitle')}</p>
            {flash.status ? (
                <p role="status" className="mt-4 rounded-tile bg-card px-3 py-2 text-sm text-text ring-1 ring-line">
                    {flash.status}
                </p>
            ) : null}
            <form onSubmit={submit} className="mt-8 grid gap-4" noValidate data-testid="signin-form">
                <Field id="email" label={t('auth.email')} error={form.errors.email}>
                    <div className="relative">
                        <Mail className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" />
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            autoComplete="username"
                            dir="ltr"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                            required
                            aria-invalid={Boolean(form.errors.email)}
                            aria-describedby={form.errors.email ? 'email-error' : undefined}
                            className="h-11 ps-9"
                        />
                    </div>
                </Field>
                <Field id="password" label={t('auth.password')} error={form.errors.password}>
                    <div className="relative">
                        <Lock className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" />
                        <Input
                            id="password"
                            type={show ? 'text' : 'password'}
                            name="password"
                            autoComplete="current-password"
                            dir="ltr"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            required
                            className="h-11 pe-10 ps-9"
                        />
                        <button
                            type="button"
                            onClick={() => setShow((current) => !current)}
                            aria-label={show ? t('auth.hide_password') : t('auth.show_password')}
                            className="absolute end-2 top-1/2 grid size-7 -translate-y-1/2 place-items-center rounded-md text-faint hover:text-text"
                        >
                            {show ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                        </button>
                    </div>
                </Field>
                <div className="flex items-center justify-between gap-3 text-sm">
                    <label className="inline-flex items-center gap-2 text-muted">
                        <input type="checkbox" className="size-4 accent-[var(--brand-mint)]" checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)} />
                        {t('auth.remember')}
                    </label>
                    <a href="/forgot-password" className="font-semibold text-accent-ink hover:underline">
                        {t('auth.forgot')}
                    </a>
                </div>
                <Button type="submit" variant="gradient" size="lg" className="mt-2 w-full" disabled={form.processing}>
                    {t('auth.submit')} <ArrowRight className="flip-rtl" />
                </Button>
            </form>
        </GuestLayout>
    );
}
