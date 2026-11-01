import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';

type PermissionRow = {
    key: string;
    module: string;
    label: string;
    scope: string;
};

type RoleInfo = {
    id: number;
    key: string;
    name_en: string;
    name_ar: string;
    locked: boolean;
    builtin: boolean;
} | null;

export default function RoleEdit({ role, permissions }: { role: RoleInfo; permissions: PermissionRow[] }) {
    const { t } = useI18n();
    const form = useForm({
        name_en: role?.name_en ?? '',
        name_ar: role?.name_ar ?? '',
        grants: Object.fromEntries(permissions.map((permission) => [permission.key, permission.scope])) as Record<string, string>,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (role) {
            form.put(`/admin/roles/${role.id}`, { preserveScroll: true });
        }
    };

    const modules = permissions.reduce<Record<string, PermissionRow[]>>((groups, permission) => {
        groups[permission.module] = [...(groups[permission.module] ?? []), permission];

        return groups;
    }, {});

    return (
        <AppShell>
            <Head title={role ? role.name_en : t('admin.permissions')} />
            <form onSubmit={submit} className="mx-auto max-w-4xl">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-3xl font-semibold tracking-tight">{role?.name_en ?? t('admin.permissions')}</h1>
                        <p className="mt-2 text-sm text-muted-foreground">{role?.locked ? t('admin.locked_help') : t('admin.grid_help')}</p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link href="/admin/roles">{t('admin.back')}</Link>
                    </Button>
                </div>
                {role && !role.locked ? (
                    <div className="mt-6 grid gap-3 md:grid-cols-2">
                        <Input value={form.data.name_en} onChange={(event) => form.setData('name_en', event.target.value)} aria-label={t('admin.name_en')} />
                        <Input value={form.data.name_ar} onChange={(event) => form.setData('name_ar', event.target.value)} aria-label={t('admin.name_ar')} />
                    </div>
                ) : null}
                <div className="mt-6 space-y-6">
                    {Object.entries(modules).map(([module, rows]) => (
                        <section key={module} className="card overflow-hidden">
                            <h2 className="border-b border-line px-4 py-3 text-sm font-semibold capitalize">{module}</h2>
                            <ul className="divide-y divide-line">
                                {rows.map((permission) => (
                                    <li key={permission.key} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                        <span className="text-sm">{permission.label}</span>
                                        <select
                                            aria-label={permission.label}
                                            className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                                            disabled={role?.locked}
                                            value={form.data.grants[permission.key] ?? ''}
                                            onChange={(event) => form.setData('grants', { ...form.data.grants, [permission.key]: event.target.value })}
                                        >
                                            <option value="">{t('admin.scope_none')}</option>
                                            <option value="own">{t('admin.scope_own')}</option>
                                            <option value="team">{t('admin.scope_team')}</option>
                                            <option value="all">{t('admin.scope_all')}</option>
                                        </select>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>
                {role && !role.locked ? (
                    <Button type="submit" className="mt-6" disabled={form.processing}>
                        {t('admin.save')}
                    </Button>
                ) : null}
            </form>
        </AppShell>
    );
}
