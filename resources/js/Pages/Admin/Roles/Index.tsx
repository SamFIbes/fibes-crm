import { Head, Link, router, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { FormEvent, useState } from 'react';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { DataTable } from '@/components/table/DataTable';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type RoleRow = {
    id: number;
    key: string;
    label: string;
    locked: boolean;
    builtin: boolean;
    users: number;
};

export default function RolesIndex({ roles, can_manage }: { roles: RoleRow[]; can_manage: boolean }) {
    const { t } = useI18n();
    const { flash } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState({ key: '', name_en: '', name_ar: '' });

    const create = (event: FormEvent) => {
        event.preventDefault();
        router.post('/admin/roles', form);
    };

    const columns: ColumnDef<RoleRow, unknown>[] = [
        {
            header: t('admin.roles'),
            accessorKey: 'label',
            cell: ({ row }) => (
                <div>
                    <p className="font-semibold">
                        {row.original.label}{' '}
                        {row.original.locked ? (
                            <Badge tone="info" className="ms-1">
                                {t('admin.locked')}
                            </Badge>
                        ) : null}
                    </p>
                    <p className="text-xs text-muted" dir="ltr">
                        {row.original.key}
                    </p>
                </div>
            ),
        },
        {
            header: t('admin.users_title'),
            accessorKey: 'users',
            cell: ({ row }) => <span className="tabular text-sm">{row.original.users}</span>,
        },
        {
            header: '',
            id: 'actions',
            cell: ({ row }) => (
                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/admin/roles/${row.original.id}/edit`}>{t('admin.permissions')}</Link>
                    </Button>
                    {can_manage ? (
                        <Button type="button" variant="outline" size="sm" onClick={() => router.post(`/admin/roles/${row.original.id}/clone`)}>
                            {t('admin.clone')}
                        </Button>
                    ) : null}
                </div>
            ),
        },
    ];

    return (
        <AppShell>
            <Head title={t('admin.roles_title')} />
            <PageHeader
                title={t('admin.roles_title')}
                sub={t('admin.roles_subtitle')}
                actions={
                    can_manage ? (
                        <Button type="button" onClick={() => setOpen((value) => !value)}>
                            {t('admin.add_role')}
                        </Button>
                    ) : null
                }
            />
            {flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{flash.status}</p> : null}
            {open ? (
                <form onSubmit={create} className="card mb-4 grid gap-3 p-4 md:grid-cols-4">
                    <Field id="key" label={t('admin.role_key')}>
                        <Input id="key" dir="ltr" value={form.key} onChange={(event) => setForm({ ...form, key: event.target.value })} required />
                    </Field>
                    <Field id="name_en" label={t('admin.name_en')}>
                        <Input id="name_en" value={form.name_en} onChange={(event) => setForm({ ...form, name_en: event.target.value })} required />
                    </Field>
                    <Field id="name_ar" label={t('admin.name_ar')}>
                        <Input id="name_ar" value={form.name_ar} onChange={(event) => setForm({ ...form, name_ar: event.target.value })} required />
                    </Field>
                    <div className="flex items-end">
                        <Button type="submit">{t('admin.save')}</Button>
                    </div>
                </form>
            ) : null}
            <DataTable columns={columns} data={roles} />
        </AppShell>
    );
}
