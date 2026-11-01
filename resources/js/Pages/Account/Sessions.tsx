import { Head, router, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { AppShell } from '@/components/shell/AppShell';
import { DataTable } from '@/components/table/DataTable';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type SessionRow = {
    id: string;
    ip: string | null;
    device: string;
    last_active: string;
    current: boolean;
    owner: { name: string; email: string } | null;
};

export default function Sessions({ sessions, other_sessions }: { sessions: SessionRow[]; other_sessions: SessionRow[] }) {
    const { t } = useI18n();
    const { flash } = usePage<SharedProps>().props;

    const revoke = (id: string) => {
        router.delete(`/account/sessions/${id}`, { preserveScroll: true });
    };

    const columns: ColumnDef<SessionRow, unknown>[] = [
        {
            header: t('sessions.title'),
            id: 'device',
            cell: ({ row }) => (
                <div>
                    <p className="font-semibold">
                        {row.original.device}{' '}
                        {row.original.current ? (
                            <Badge tone="good" className="ms-1">
                                {t('sessions.this_browser')}
                            </Badge>
                        ) : null}
                    </p>
                    <p className="text-xs text-muted" dir="ltr">
                        {row.original.ip ?? '—'} · {t('sessions.last_active')} {row.original.last_active}
                    </p>
                </div>
            ),
        },
        {
            header: '',
            id: 'actions',
            cell: ({ row }) => (
                <div className="text-end">
                    <Button type="button" variant="outline" size="sm" onClick={() => revoke(row.original.id)}>
                        {t('sessions.revoke')}
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <AppShell>
            <Head title={t('sessions.title')} />
            <PageHeader
                title={t('sessions.title')}
                sub={t('sessions.subtitle')}
                actions={
                    <Button type="button" variant="outline" onClick={() => router.delete('/account/sessions/others', { preserveScroll: true })}>
                        {t('sessions.revoke_others')}
                    </Button>
                }
            />
            {flash.status ? (
                <p role="status" className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">
                    {flash.status}
                </p>
            ) : null}
            <DataTable columns={columns} data={sessions} empty={t('sessions.empty')} />
            {other_sessions.length > 0 ? (
                <section className="mt-8">
                    <h2 className="text-lg font-semibold">{t('sessions.others_title')}</h2>
                    <p className="mt-1 text-sm text-muted">{t('sessions.others_subtitle')}</p>
                    <ul className="mt-4 space-y-3">
                        {other_sessions.map((session) => (
                            <li key={session.id} className="card flex flex-wrap items-center justify-between gap-3 p-4">
                                <div>
                                    <p className="text-sm font-semibold">{session.owner?.name}</p>
                                    <p className="text-xs text-muted" dir="ltr">
                                        {session.owner?.email}
                                    </p>
                                    <p className="mt-1 text-xs text-muted" dir="ltr">
                                        {session.device} · {session.ip ?? '—'} · {session.last_active}
                                    </p>
                                </div>
                                <Button type="button" variant="outline" onClick={() => revoke(session.id)}>
                                    {t('sessions.revoke')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </AppShell>
    );
}
