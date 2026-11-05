import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';

type Option = { id: number; label: string; key?: string; locked?: boolean };

type Account = {
    id: number;
    name: string;
    email: string;
    locale: 'en' | 'ar';
    is_active: boolean;
    force_two_factor: boolean;
    extension?: string | null;
    role_ids: number[];
    team_ids: number[];
    last_seen_at: string | null;
};

export default function UserForm({
    account,
    roles,
    teams,
}: {
    account: Account | null;
    roles: Option[];
    teams: Option[];
}) {
    const { t } = useI18n();
    const form = useForm({
        name: account?.name ?? '',
        email: account?.email ?? '',
        locale: account?.locale ?? 'en',
        is_active: account?.is_active ?? true,
        force_two_factor: account?.force_two_factor ?? false,
        extension: account?.extension ?? '',
        password: '',
        password_confirmation: '',
        role_ids: account?.role_ids ?? [],
        team_ids: account?.team_ids ?? [],
        revoke_sessions: false,
    });

    const toggle = (key: 'role_ids' | 'team_ids', id: number) => {
        const current = form.data[key];
        form.setData(
            key,
            current.includes(id) ? current.filter((item) => item !== id) : [...current, id],
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (account) {
            form.put(`/admin/users/${account.id}`);
        } else {
            form.post('/admin/users');
        }
    };

    return (
        <AppShell>
            <Head title={account ? t('admin.edit_user') : t('admin.add_user')} />
            <form onSubmit={submit} className="mx-auto max-w-3xl space-y-6">
                <div className="card px-5 py-4">
                    <h1 className="text-2xl font-semibold tracking-tight">{account ? t('admin.edit_user') : t('admin.add_user')}</h1>
                    <p className="mt-2 text-sm text-muted-foreground">{t('admin.user_form_help')}</p>
                </div>
                <div className="card space-y-4 p-5">
                    <Field id="name" label={t('admin.name')} error={form.errors.name}>
                        <Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required />
                    </Field>
                    <Field id="extension" label={t('calls.extension')} error={form.errors.extension}>
                        <Input id="extension" dir="ltr" value={form.data.extension} onChange={(event) => form.setData('extension', event.target.value)} />
                    </Field>
                    <Field id="email" label={t('auth.email')} error={form.errors.email}>
                        <Input id="email" type="email" dir="ltr" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} required />
                    </Field>
                    <Field id="locale" label={t('language.label')} error={form.errors.locale}>
                        <select
                            id="locale"
                            className="flex h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                            value={form.data.locale}
                            onChange={(event) => form.setData('locale', event.target.value as 'en' | 'ar')}
                        >
                            <option value="en">{t('language.en')}</option>
                            <option value="ar">{t('language.ar')}</option>
                        </select>
                    </Field>
                    <Field id="password" label={account ? t('admin.new_temporary_password') : t('auth.password')} hint={t('security.password_hint')} error={form.errors.password}>
                        <Input
                            id="password"
                            type="password"
                            dir="ltr"
                            autoComplete="new-password"
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                            required={!account}
                        />
                    </Field>
                    <Field id="password_confirmation" label={t('auth.password_confirm')} error={form.errors.password_confirmation}>
                        <Input
                            id="password_confirmation"
                            type="password"
                            dir="ltr"
                            value={form.data.password_confirmation}
                            onChange={(event) => form.setData('password_confirmation', event.target.value)}
                        />
                    </Field>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />
                        {t('admin.active')}
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.data.force_two_factor} onChange={(event) => form.setData('force_two_factor', event.target.checked)} />
                        {t('admin.force_two_factor')}
                    </label>
                    {account ? (
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={form.data.revoke_sessions} onChange={(event) => form.setData('revoke_sessions', event.target.checked)} />
                            {t('admin.revoke_sessions')}
                        </label>
                    ) : null}
                </div>
                <fieldset className="card p-5">
                    <legend className="px-1 text-sm font-semibold">{t('admin.roles')}</legend>
                    {form.errors.role_ids ? <p className="mb-3 text-sm text-destructive">{form.errors.role_ids}</p> : null}
                    <div className="mt-3 grid gap-2 sm:grid-cols-2">
                        {roles.map((role) => (
                            <label key={role.id} className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={form.data.role_ids.includes(role.id)} onChange={() => toggle('role_ids', role.id)} />
                                {role.label}
                                {role.locked ? <span className="text-xs text-muted-foreground">{t('admin.locked')}</span> : null}
                            </label>
                        ))}
                    </div>
                </fieldset>
                <fieldset className="card p-5">
                    <legend className="px-1 text-sm font-semibold">{t('admin.teams')}</legend>
                    <div className="mt-3 grid gap-2 sm:grid-cols-2">
                        {teams.map((team) => (
                            <label key={team.id} className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={form.data.team_ids.includes(team.id)} onChange={() => toggle('team_ids', team.id)} />
                                {team.label}
                            </label>
                        ))}
                    </div>
                </fieldset>
                <div className="flex gap-3">
                    <Button type="submit" disabled={form.processing}>
                        {t('admin.save')}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href="/admin/users">{t('admin.back')}</Link>
                    </Button>
                </div>
            </form>
        </AppShell>
    );
}
