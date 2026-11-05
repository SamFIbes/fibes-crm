import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Field } from '@/components/form/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { GuestLayout } from '@/layouts/GuestLayout';
import { useI18n } from '@/lib/i18n';

export default function TwoFactorChallenge() {
    const { t } = useI18n();
    const form = useForm({ code: '', mode: 'code' as 'code' | 'recovery' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/two-factor-challenge', { preserveScroll: true });
    };

    return (
        <GuestLayout mode="auth">
            <Head title={t('auth.two_factor_title')} />
            <h1 className="text-2xl font-semibold tracking-tight">{t('auth.two_factor_title')}</h1>
            <p className="mt-2 text-sm leading-6 text-muted-foreground">
                {form.data.mode === 'code' ? t('auth.two_factor_subtitle') : t('auth.recovery')}
            </p>
            <form onSubmit={submit} className="mt-8 space-y-4">
                <Field id="code" label={form.data.mode === 'code' ? t('auth.code') : t('auth.recovery')} error={form.errors.code}>
                    <Input
                        id="code"
                        dir="ltr"
                        inputMode={form.data.mode === 'code' ? 'numeric' : 'text'}
                        autoComplete="one-time-code"
                        value={form.data.code}
                        onChange={(event) => form.setData('code', event.target.value)}
                        required
                    />
                </Field>
                <Button type="submit" variant="gradient" className="h-11 w-full rounded-2xl" disabled={form.processing}>
                    {t('auth.verify')}
                </Button>
            </form>
            <button
                type="button"
                className="mt-6 text-sm font-medium underline-offset-4 hover:underline"
                onClick={() => {
                    form.setData({
                        code: '',
                        mode: form.data.mode === 'code' ? 'recovery' : 'code',
                    });
                    form.clearErrors();
                }}
            >
                {form.data.mode === 'code' ? t('auth.use_recovery') : t('auth.use_code')}
            </button>
        </GuestLayout>
    );
}
