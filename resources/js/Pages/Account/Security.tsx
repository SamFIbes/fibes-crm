import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { toast } from 'sonner';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

export default function Security({
    pending,
    qr,
    secret,
}: {
    pending: boolean;
    qr: string | null;
    secret: string | null;
}) {
    const { t } = useI18n();
    const { auth, flash, security } = usePage<SharedProps>().props;
    const enabled = auth.user?.two_factor_enabled ?? false;
    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const confirmForm = useForm({ code: '' });
    const disableForm = useForm({ current_password: '' });
    const enableForm = useForm({});

    const updatePassword = (event: FormEvent) => {
        event.preventDefault();
        passwordForm.put('/account/password', {
            preserveScroll: true,
            onSuccess: () => passwordForm.reset(),
        });
    };

    const copySecret = async () => {
        if (!secret) {
            return;
        }

        await navigator.clipboard.writeText(secret);
        toast.success(t('security.copied'));
    };

    return (
        <AppShell>
            <Head title={t('security.title')} />
            <div className="mx-auto max-w-3xl">
                <h1 className="text-3xl font-semibold tracking-tight">{t('security.title')}</h1>
                <p className="mt-2 text-sm leading-6 text-muted-foreground">{t('security.subtitle')}</p>
                {security.two_factor_grace_days === 0 ? (
                    <p className="mt-4 rounded-lg bg-secondary px-3 py-2 text-sm text-foreground">{t('security.enroll_body')}</p>
                ) : null}

                {flash.recovery_codes && flash.recovery_codes.length > 0 ? (
                    <section className="mt-6 rounded-2xl border border-border bg-card p-5">
                        <h2 className="text-sm font-semibold">{t('security.recovery_title')}</h2>
                        <p className="mt-2 text-sm leading-6 text-muted-foreground">{t('security.recovery_body')}</p>
                        <ul className="mt-4 grid gap-2 sm:grid-cols-2" dir="ltr">
                            {flash.recovery_codes.map((code) => (
                                <li key={code} className="rounded-lg bg-secondary px-3 py-2 font-mono text-sm tracking-wide">
                                    {code}
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}

                <section className="mt-6 rounded-2xl border border-border bg-card p-5">
                    <h2 className="text-sm font-semibold">{t('security.password_heading')}</h2>
                    <form onSubmit={updatePassword} className="mt-4 space-y-4">
                        <Field id="current_password" label={t('security.current_password')} error={passwordForm.errors.current_password}>
                            <Input
                                id="current_password"
                                type="password"
                                dir="ltr"
                                autoComplete="current-password"
                                value={passwordForm.data.current_password}
                                onChange={(event) => passwordForm.setData('current_password', event.target.value)}
                                required
                            />
                        </Field>
                        <Field id="password" label={t('security.new_password')} hint={t('security.password_hint')} error={passwordForm.errors.password}>
                            <Input
                                id="password"
                                type="password"
                                dir="ltr"
                                autoComplete="new-password"
                                value={passwordForm.data.password}
                                onChange={(event) => passwordForm.setData('password', event.target.value)}
                                required
                            />
                        </Field>
                        <Field id="password_confirmation" label={t('auth.password_confirm')} error={passwordForm.errors.password_confirmation}>
                            <Input
                                id="password_confirmation"
                                type="password"
                                dir="ltr"
                                autoComplete="new-password"
                                value={passwordForm.data.password_confirmation}
                                onChange={(event) => passwordForm.setData('password_confirmation', event.target.value)}
                                required
                            />
                        </Field>
                        <Button type="submit" disabled={passwordForm.processing}>
                            {t('security.save_password')}
                        </Button>
                    </form>
                </section>

                <section className="mt-4 rounded-2xl border border-border bg-card p-5">
                    <h2 className="text-sm font-semibold">{t('security.two_factor_heading')}</h2>
                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                        {enabled ? t('security.two_factor_on') : pending ? t('security.two_factor_pending') : t('security.two_factor_off')}
                    </p>

                    {pending && qr ? (
                        <div className="mt-4 space-y-4">
                            <p className="text-sm text-muted-foreground">{t('security.scan')}</p>
                            <div className="inline-flex rounded-xl bg-white p-3" dangerouslySetInnerHTML={{ __html: qr }} />
                            {secret ? (
                                <div className="flex flex-wrap items-center gap-2" dir="ltr">
                                    <code className="rounded-lg bg-secondary px-3 py-2 text-sm">{secret}</code>
                                    <Button type="button" variant="outline" size="sm" onClick={copySecret}>
                                        {t('security.copy')}
                                    </Button>
                                </div>
                            ) : null}
                            <form
                                className="max-w-xs space-y-3"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    confirmForm.post('/account/two-factor/confirm', {
                                        preserveScroll: true,
                                        onSuccess: () => confirmForm.reset(),
                                    });
                                }}
                            >
                                <Field id="code" label={t('auth.code')} error={confirmForm.errors.code}>
                                    <Input
                                        id="code"
                                        dir="ltr"
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        value={confirmForm.data.code}
                                        onChange={(event) => confirmForm.setData('code', event.target.value)}
                                        required
                                    />
                                </Field>
                                <Button type="submit" disabled={confirmForm.processing}>
                                    {t('security.confirm')}
                                </Button>
                            </form>
                        </div>
                    ) : null}

                    {!enabled && !pending ? (
                        <form
                            className="mt-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                enableForm.post('/account/two-factor', { preserveScroll: true });
                            }}
                        >
                            <Button type="submit" disabled={enableForm.processing}>
                                {t('security.enable')}
                            </Button>
                        </form>
                    ) : null}

                    {enabled ? (
                        <form
                            className="mt-4 max-w-xs space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                disableForm.delete('/account/two-factor', {
                                    preserveScroll: true,
                                    onSuccess: () => disableForm.reset(),
                                });
                            }}
                        >
                            <Field id="disable_password" label={t('security.current_password')} error={disableForm.errors.current_password}>
                                <Input
                                    id="disable_password"
                                    type="password"
                                    dir="ltr"
                                    autoComplete="current-password"
                                    value={disableForm.data.current_password}
                                    onChange={(event) => disableForm.setData('current_password', event.target.value)}
                                    required
                                />
                            </Field>
                            <Button type="submit" variant="destructive" disabled={disableForm.processing}>
                                {t('security.disable')}
                            </Button>
                        </form>
                    ) : null}
                </section>
            </div>
        </AppShell>
    );
}
