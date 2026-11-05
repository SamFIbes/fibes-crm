import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Field } from '@/components/form/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { GuestLayout } from '@/layouts/GuestLayout';
import { useI18n } from '@/lib/i18n';

export default function ForgotPassword() {
    const { t } = useI18n();
    const form = useForm({ email: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/forgot-password', { preserveScroll: true });
    };

    return (
        <GuestLayout>
            <Head title={t('auth.forgot_title')} />
            <h1 className="text-2xl font-semibold tracking-tight">{t('auth.forgot_title')}</h1>
            <p className="mt-2 text-sm leading-6 text-muted-foreground">{t('auth.forgot_subtitle')}</p>
            <form onSubmit={submit} className="mt-8 space-y-4">
                <Field id="email" label={t('auth.email')} error={form.errors.email}>
                    <Input
                        id="email"
                        type="email"
                        dir="ltr"
                        autoComplete="username"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        required
                    />
                </Field>
                <Button type="submit" className="w-full" disabled={form.processing}>
                    {t('auth.send_link')}
                </Button>
            </form>
            <a href="/login" className="mt-6 inline-block text-sm font-medium underline-offset-4 hover:underline">
                {t('auth.back_to_login')}
            </a>
        </GuestLayout>
    );
}
