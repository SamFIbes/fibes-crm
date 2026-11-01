import { Head, Link, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { AppShell } from '@/components/shell/AppShell';
import { DataTable } from '@/components/table/DataTable';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Account = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    force_two_factor: boolean;
    last_seen_at: string | null;
    roles: string[];
    teams: string[];
};

export default function UsersIndex({ users, can_manage }: { users: Account[]; can_manage: boolean }) {
    const { t } = useI18n();
    const { flash } = usePage<SharedProps>().props;
    const columns: ColumnDef<Account, unknown>[] = [
        {
            header: t('admin.name'),
            accessorKey: 'name',
            cell: ({ row }) => (
                <div>
                    <p className="font-semibold">
                        {row.original.name}{' '}
                        {!row.original.is_active ? (
                            <Badge tone="neutral" className="ms-1">
                                {t('admin.inactive')}
                            </Badge>
                        ) : null}
                    </p>
                    <p className="text-xs text-muted" dir="ltr">
                        {row.original.email}
                    </p>
                </div>
            ),
        },
        {
            header: t('admin.roles'),
            id: 'roles',
            cell: ({ row }) => <span className="text-sm text-muted">{row.original.roles.join(' · ') || t('admin.no_role')}</span>,
        },
        {
            header: '',
            id: 'actions',
            cell: ({ row }) =>
                can_manage ? (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/admin/users/${row.original.id}/edit`}>{t('admin.edit')}</Link>
                    </Button>
                ) : null,
        },
    ];

    return (
        <AppShell>
            <Head title={t('admin.users_title')} />
            <PageHeader
                title={t('admin.users_title')}
                sub={t('admin.users_subtitle')}
                actions={
                    can_manage ? (
                        <Button asChild>
                            <Link href="/admin/users/create">{t('admin.add_user')}</Link>
                        </Button>
                    ) : null
                }
            />
            {flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{flash.status}</p> : null}
            <DataTable columns={columns} data={users} empty={t('admin.no_role')} />
        </AppShell>
    );
}
