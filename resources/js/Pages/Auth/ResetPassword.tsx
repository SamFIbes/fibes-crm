import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Field } from '@/components/form/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { GuestLayout } from '@/layouts/GuestLayout';
import { useI18n } from '@/lib/i18n';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const { t } = useI18n();
    const form = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/reset-password', { preserveScroll: true });
    };

    return (
        <GuestLayout mode="auth">
            <Head title={t('auth.reset_title')} />
            <h1 className="text-2xl font-semibold tracking-tight">{t('auth.reset_title')}</h1>
            <p className="mt-2 text-sm leading-6 text-muted-foreground">{t('auth.reset_subtitle')}</p>
            <form onSubmit={submit} className="mt-8 space-y-4">
                <Field id="email" label={t('auth.email')} error={form.errors.email}>
                    <Input id="email" type="email" dir="ltr" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} required />
                </Field>
                <Field id="password" label={t('auth.password')} error={form.errors.password}>
                    <Input
                        id="password"
                        type="password"
                        dir="ltr"
                        autoComplete="new-password"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        required
                    />
                </Field>
                <Field id="password_confirmation" label={t('auth.password_confirm')} error={form.errors.password_confirmation}>
                    <Input
                        id="password_confirmation"
                        type="password"
                        dir="ltr"
                        autoComplete="new-password"
                        value={form.data.password_confirmation}
                        onChange={(event) => form.setData('password_confirmation', event.target.value)}
                        required
                    />
                </Field>
                <Button type="submit" variant="gradient" className="h-11 w-full rounded-2xl" disabled={form.processing}>
                    {t('auth.reset_submit')}
                </Button>
            </form>
        </GuestLayout>
    );
}
