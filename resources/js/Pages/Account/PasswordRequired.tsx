import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Field } from '@/components/form/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { GuestLayout } from '@/layouts/GuestLayout';
import { useI18n } from '@/lib/i18n';

export default function PasswordRequired() {
    const { t } = useI18n();
    const form = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put('/account/password/required', {
            preserveScroll: true,
            onFinish: () => form.reset('current_password', 'password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout mode="auth">
            <Head title={t('security.password_required_title')} />
            <h1 className="text-2xl font-semibold tracking-tight">{t('security.password_required_title')}</h1>
            <p className="mt-2 text-sm leading-6 text-muted-foreground">{t('security.password_required_body')}</p>
            <form onSubmit={submit} className="mt-8 space-y-4">
                <Field id="current_password" label={t('security.current_password')} error={form.errors.current_password}>
                    <Input
                        id="current_password"
                        type="password"
                        dir="ltr"
                        autoComplete="current-password"
                        value={form.data.current_password}
                        onChange={(event) => form.setData('current_password', event.target.value)}
                        required
                    />
                </Field>
                <Field id="password" label={t('security.new_password')} hint={t('security.password_hint')} error={form.errors.password}>
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
                    {t('security.password_required_submit')}
                </Button>
            </form>
        </GuestLayout>
    );
}
