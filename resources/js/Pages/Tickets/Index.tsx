import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { Ticket } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { KanbanBoard, type KanbanColumn } from '@/components/kanban/Board';
import { AppShell } from '@/components/shell/AppShell';
import { DataTable } from '@/components/table/DataTable';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { Field } from '@/components/form/Field';
import { FilterSelect } from '@/components/ui/filter-select';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { attentionRank, formatRemaining, slaConfigured, type Sla } from '@/lib/attention';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Row = {
    id: number;
    number: string;
    subject: string;
    status: string;
    priority: string;
    channel: string;
    assignee: string | null;
    service_type: string | null;
    customer: { id: number; name_en: string; name_ar: string; account_no: string };
    area: { name_en: string; name_ar: string } | null;
    category: { name_en: string; name_ar: string };
    incident?: { id: number; number: string; title: string } | null;
    sla: Sla;
    sla_configured?: boolean;
};
type Match = { id: number; name_en: string; name_ar: string; account_no: string; services: { id: number; service_type: string }[] };
type Filters = { q: string; status: string; priority: string; assignee_id: number | null; area_id: number | null; service_type: string; sla: string; queue: string; mine: boolean };

const statuses = ['new', 'open', 'pending_customer', 'escalated', 'resolved', 'closed', 'reopened'] as const;
const priorities = ['P1', 'P2', 'P3', 'P4'] as const;
const channels = ['phone', 'whatsapp', 'walk_in', 'email', 'field'] as const;

export default function TicketsIndex({
    tickets,
    filters,
    categories,
    areas,
    views,
    can_create,
    can_move,
    service_types,
    prefill = null,
}: {
    tickets: Row[];
    filters: Filters;
    categories: { id: number; name_en: string; name_ar: string }[];
    areas: { id: number; name_en: string; name_ar: string }[];
    technicians: { id: number; name: string }[];
    teams: { id: number; name_en: string; name_ar: string }[];
    views: { id: number; name: string; filters: Record<string, string | number | boolean | null> }[];
    can_create: boolean;
    can_move: boolean;
    can_escalate: boolean;
    service_types: string[];
    prefill?: Match | null;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const [mode, setMode] = useState<'queue' | 'table' | 'board'>('queue');
    const [rows, setRows] = useState(tickets);
    const [selectedId, setSelectedId] = useState<number | null>(tickets[0]?.id ?? null);
    const [open, setOpen] = useState(page.url.includes('new=1'));
    const [matches, setMatches] = useState<Match[]>([]);
    const [picked, setPicked] = useState<Match | null>(null);
    const form = useForm({
        customer_id: '',
        customer_service_id: '',
        category_id: String(categories[0]?.id ?? ''),
        priority: 'P3',
        channel: 'phone',
        subject: '',
        body: '',
        lookup: '',
    });

    useEffect(() => setRows(tickets), [tickets]);
    useEffect(() => {
        if (!tickets.some((row) => row.id === selectedId)) {
            setSelectedId(tickets[0]?.id ?? null);
        }
    }, [tickets, selectedId]);
    useEffect(() => {
        if (page.url.includes('new=1')) setOpen(true);
        if (prefill) {
            setOpen(true);
            setPicked(prefill);
            form.setData('customer_id', String(prefill.id));
        }
    }, [page.url, prefill]);

    const ordered = useMemo(() => {
        if (filters.queue === 'latest') {
            return rows;
        }

        return [...rows].sort((a, b) => attentionRank(a) - attentionRank(b));
    }, [rows, filters.queue]);
    const selected = ordered.find((row) => row.id === selectedId) ?? null;

    const apply = (next: Filters) => {
        router.get('/tickets', {
            q: next.q,
            status: next.status,
            priority: next.priority,
            assignee_id: next.assignee_id ?? '',
            area_id: next.area_id ?? '',
            service_type: next.service_type,
            sla: next.sla,
            queue: next.queue,
            mine: next.mine ? 1 : '',
        }, { preserveState: true, preserveScroll: true });
    };

    const columns = useMemo<KanbanColumn<Omit<Row, 'id'> & { id: string }>[]>(() => statuses.map((status) => ({
        id: status,
        title: t(`tickets.status_${status}`),
        items: rows.filter((row) => row.status === status).map(({ id, ...row }) => ({ ...row, id: String(id) })),
    })), [rows, t]);

    const tableColumns: ColumnDef<Row, unknown>[] = [
        { header: t('tickets.title'), cell: ({ row }) => <Link className="font-semibold text-accent-ink" dir="ltr" href={`/tickets/${row.original.id}`}>{row.original.number}</Link> },
        { header: t('tickets.subject'), cell: ({ row }) => <span>{row.original.subject}<div className="text-xs text-muted">{locale === 'ar' ? row.original.customer.name_ar : row.original.customer.name_en}</div></span> },
        { header: t('tickets.priority'), cell: ({ row }) => <Badge tone={row.original.priority === 'P1' ? 'bad' : row.original.priority === 'P2' ? 'warn' : 'info'}>{row.original.priority}</Badge> },
        { header: t('tickets.status'), cell: ({ row }) => t(`tickets.status_${row.original.status}`) },
        { header: t('tickets.sla'), cell: ({ row }) => <SlaChip sla={row.original.sla} /> },
    ];

    const lookup = async (value: string) => {
        form.setData('lookup', value);
        if (value.trim().length < 2) {
            setMatches([]);
            return;
        }
        const response = await fetch(`/tickets/lookup?q=${encodeURIComponent(value)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        setMatches(response.ok ? await response.json() : []);
    };

    const create = (event: FormEvent) => {
        event.preventDefault();
        form.post('/tickets');
    };

    return (
        <AppShell>
            <Head title={t('tickets.title')} />
            <PageHeader title={t('tickets.title')} sub={t('tickets.subtitle')} actions={
                <div className="flex flex-wrap gap-2">
                    <Button type="button" variant={mode === 'queue' ? 'default' : 'outline'} onClick={() => setMode('queue')}>{t('tickets.queue')}</Button>
                    <Button type="button" variant={mode === 'table' ? 'default' : 'outline'} onClick={() => setMode('table')}>{t('tickets.list')}</Button>
                    <Button type="button" variant={mode === 'board' ? 'default' : 'outline'} onClick={() => setMode('board')}>{t('tickets.board')}</Button>
                    {can_create ? <Button type="button" onClick={() => setOpen(true)}>{t('tickets.new')}</Button> : null}
                </div>
            } />
            <p className="mb-3 text-xs text-muted">{t('tickets.shortcut')}</p>
            <div className="mb-4 flex flex-wrap gap-2" data-testid="tickets-page">
                <Input id="ticket-search" className="max-w-xs" placeholder={t('tickets.search')} defaultValue={filters.q} onKeyDown={(event) => { if (event.key === 'Enter') apply({ ...filters, q: (event.target as HTMLInputElement).value }); }} />
                <FilterSelect label={t('tickets.status')} value={filters.status} onChange={(status) => apply({ ...filters, status })} options={[{ value: '', label: t('filters.any') }, ...statuses.map((status) => ({ value: status, label: t(`tickets.status_${status}`) }))]} />
                <FilterSelect label={t('tickets.priority')} value={filters.priority} onChange={(priority) => apply({ ...filters, priority })} options={[{ value: '', label: t('filters.any') }, ...priorities.map((priority) => ({ value: priority, label: priority }))]} />
                <FilterSelect label={t('customers.area')} value={String(filters.area_id ?? '')} onChange={(area) => apply({ ...filters, area_id: area ? Number(area) : null })} options={[{ value: '', label: t('filters.any') }, ...areas.map((area) => ({ value: String(area.id), label: locale === 'ar' ? area.name_ar : area.name_en }))]} />
                <FilterSelect label={t('customers.service_type')} value={filters.service_type} onChange={(service) => apply({ ...filters, service_type: service })} options={[{ value: '', label: t('filters.any') }, ...service_types.map((type) => ({ value: type, label: t(`customers.${type}`) }))]} />
                <FilterSelect label={t('tickets.sla')} value={filters.sla} onChange={(sla) => apply({ ...filters, sla })} options={[{ value: '', label: t('filters.any') }, { value: 'warning', label: t('tickets.warning') }, { value: 'breached', label: t('tickets.breached') }]} />
                <Button type="button" variant={filters.queue === 'attention' ? 'default' : 'outline'} onClick={() => apply({ ...filters, queue: filters.queue === 'attention' ? 'latest' : 'attention' })}>{t('tickets.queue')}</Button>
                <Button type="button" variant={filters.mine ? 'default' : 'outline'} onClick={() => apply({ ...filters, mine: !filters.mine })}>{t('tickets.mine')}</Button>
            </div>
            <div className="mb-4 flex flex-wrap gap-2">
                {views.map((view) => <button key={view.id} type="button" className="min-h-11 rounded-full bg-card px-3 text-sm ring-1 ring-line" onClick={() => apply({ ...filters, ...view.filters } as Filters)}>{view.name}</button>)}
            </div>
            {ordered.length === 0 ? <div className="card"><EmptyState icon={<Ticket className="size-5" />} title={t('tickets.empty')} body={t('tickets.shortcut')} /></div> : null}
            {ordered.length > 0 && mode === 'queue' ? (
                <div className="grid items-start gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(260px,340px)]" data-testid="ticket-queue">
                    <ul className="card divide-y divide-line">
                        {ordered.map((row) => (
                            <li key={row.id}>
                                <button type="button" className="flex min-h-11 w-full items-start justify-between gap-3 px-4 py-3 text-start" aria-pressed={selected?.id === row.id} onClick={() => setSelectedId(row.id)}>
                                    <span className="min-w-0">
                                        <span className="block text-xs text-muted" dir="ltr">{row.number} · {row.priority}</span>
                                        <span className="mt-1 block font-semibold">{row.subject}</span>
                                        <span className="mt-1 block text-sm text-muted">{locale === 'ar' ? row.customer.name_ar : row.customer.name_en}</span>
                                    </span>
                                    <SlaChip sla={row.sla} />
                                </button>
                            </li>
                        ))}
                    </ul>
                    <TicketContext row={selected} />
                </div>
            ) : null}
            {ordered.length > 0 && mode === 'table' ? (
                <div className="max-w-full" data-testid="ticket-table">
                    <DataTable columns={tableColumns} data={ordered} />
                </div>
            ) : null}
            {ordered.length > 0 && mode === 'board' ? (
                <KanbanBoard
                    columns={columns}
                    empty={t('tickets.empty')}
                    onMove={can_move ? (id, status) => {
                        setRows((current) => current.map((row) => row.id === Number(id) ? { ...row, status } : row));
                        router.patch(`/tickets/${id}/status`, { status }, { preserveScroll: true, preserveState: true });
                    } : undefined}
                    renderCard={(item) => (
                        <Link href={`/tickets/${item.id}`} className="block">
                            <div className="text-xs text-muted" dir="ltr">{item.number} · {item.priority}</div>
                            <div className="mt-1 text-sm font-semibold">{item.subject}</div>
                            <SlaChip sla={item.sla} />
                        </Link>
                    )}
                />
            ) : null}
            <Drawer open={open} onOpenChange={setOpen} title={t('tickets.new')} description={t('tickets.phone_search')}>
                <form id="new-ticket" data-testid="ticket-drawer" className="grid gap-3 p-6" onSubmit={create}>
                    <Field id="lookup" label={t('tickets.phone_search')}>
                        <Input id="lookup" value={form.data.lookup} onChange={(event) => lookup(event.target.value)} />
                    </Field>
                    {matches.length > 0 ? (
                        <ul className="rounded-tile border border-line">
                            {matches.map((match) => (
                                <li key={match.id}>
                                    <button type="button" className="min-h-11 w-full px-3 py-2 text-start text-sm hover:bg-well" onClick={() => { setPicked(match); form.setData('customer_id', String(match.id)); setMatches([]); }}>
                                        {locale === 'ar' ? match.name_ar : match.name_en} · <span dir="ltr">{match.account_no}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    {picked ? <p className="text-sm font-semibold">{locale === 'ar' ? picked.name_ar : picked.name_en}</p> : null}
                    {picked && picked.services.length > 0 ? (
                        <Field id="tservice" label={t('customers.service_type')}>
                            <select id="tservice" className="h-11 w-full rounded-ctl border border-input bg-card px-2" value={form.data.customer_service_id} onChange={(event) => form.setData('customer_service_id', event.target.value)}>
                                <option value="">{t('filters.any')}</option>
                                {picked.services.map((service) => <option key={service.id} value={service.id}>{t(`customers.${service.service_type}`)}</option>)}
                            </select>
                        </Field>
                    ) : null}
                    <Field id="tsubject" label={t('tickets.subject')} error={form.errors.subject}><Input id="tsubject" value={form.data.subject} onChange={(event) => form.setData('subject', event.target.value)} required /></Field>
                    <Field id="tbody" label={t('tickets.details')} error={form.errors.body}><textarea id="tbody" className="min-h-24 w-full rounded-ctl border border-input bg-card p-3 text-sm" value={form.data.body} onChange={(event) => form.setData('body', event.target.value)} required /></Field>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <Field id="tpriority" label={t('tickets.priority')}>
                            <select id="tpriority" className="h-11 w-full rounded-ctl border border-input bg-card px-2" value={form.data.priority} onChange={(event) => form.setData('priority', event.target.value)}>
                                {priorities.map((priority) => <option key={priority} value={priority}>{priority}</option>)}
                            </select>
                        </Field>
                        <Field id="tchannel" label={t('tickets.channel')}>
                            <select id="tchannel" className="h-11 w-full rounded-ctl border border-input bg-card px-2" value={form.data.channel} onChange={(event) => form.setData('channel', event.target.value)}>
                                {channels.map((channel) => <option key={channel} value={channel}>{t(`tickets.channel_${channel}`)}</option>)}
                            </select>
                        </Field>
                    </div>
                    <Field id="tcategory" label={t('tickets.category')}>
                        <select id="tcategory" className="h-11 w-full rounded-ctl border border-input bg-card px-2" value={form.data.category_id} onChange={(event) => form.setData('category_id', event.target.value)}>
                            {categories.map((category) => <option key={category.id} value={category.id}>{locale === 'ar' ? category.name_ar : category.name_en}</option>)}
                        </select>
                    </Field>
                </form>
                <div className="border-t border-line px-6 py-3"><Button type="submit" form="new-ticket" disabled={form.processing || !form.data.customer_id}>{t('tickets.save')}</Button></div>
            </Drawer>
        </AppShell>
    );
}

function TicketContext({ row }: { row: Row | null }) {
    const { t, locale } = useI18n();

    if (!row) {
        return <aside className="card p-4 text-sm text-muted" data-testid="ticket-context">{t('tickets.pick')}</aside>;
    }

    const name = locale === 'ar' ? row.customer.name_ar : row.customer.name_en;
    const area = row.area ? (locale === 'ar' ? row.area.name_ar : row.area.name_en) : null;

    return (
        <aside className="card p-4" data-testid="ticket-context">
            <p className="text-xs text-muted" dir="ltr">{row.number}</p>
            <h2 className="mt-1 text-lg font-semibold">{row.subject}</h2>
            <p className="mt-2 text-sm"><Link className="font-semibold text-accent-ink" href={`/customers/${row.customer.id}`}>{name}</Link> · <span dir="ltr">{row.customer.account_no}</span></p>
            <dl className="mt-3 grid gap-2 text-sm">
                <div><dt className="text-muted">{t('tickets.status')}</dt><dd>{t(`tickets.status_${row.status}`)}</dd></div>
                <div><dt className="text-muted">{t('tickets.assignee')}</dt><dd>{row.assignee || t('tickets.unassigned')}</dd></div>
                <div><dt className="text-muted">{t('customers.service_type')}</dt><dd>{row.service_type ? t(`customers.${row.service_type}`) : t('tickets.not_on_file')}</dd></div>
                <div><dt className="text-muted">{t('customers.area')}</dt><dd>{area || t('tickets.not_on_file')}</dd></div>
            </dl>
            {slaConfigured(row.sla) && row.sla ? (
                <div className="mt-3 grid gap-2 text-sm">
                    <p>{t('tickets.response')}: {t(`tickets.${row.sla.response.state === 'ok' ? 'response' : row.sla.response.state}`)} {formatRemaining(row.sla.response.remaining_seconds)}</p>
                    <p>{t('tickets.resolve')}: {t(`tickets.${row.sla.resolve.state === 'ok' ? 'response' : row.sla.resolve.state}`)} {formatRemaining(row.sla.resolve.remaining_seconds)}</p>
                </div>
            ) : <p className="mt-3 text-sm text-muted">{t('tickets.sla_missing')}</p>}
            {row.incident ? <p className="mt-3 text-sm"><Link href={`/incidents/${row.incident.id}`}>{row.incident.number}</Link> · {row.incident.title}</p> : null}
            <Link href={`/tickets/${row.id}`} className="mt-4 inline-flex min-h-11 items-center text-sm font-semibold text-accent-ink">{t('tickets.open_full')}</Link>
        </aside>
    );
}

function SlaChip({ sla }: { sla: Sla }) {
    const { t } = useI18n();

    if (!slaConfigured(sla) || !sla) {
        return <Badge tone="neutral">{t('tickets.sla_missing')}</Badge>;
    }

    const worst = [sla.response, sla.resolve].sort((a, b) => rank(b.state) - rank(a.state))[0];
    const tone = worst.state === 'breached' ? 'bad' : worst.state === 'warning' ? 'warn' : worst.state === 'met' ? 'good' : 'neutral';

    return <Badge tone={tone}>{t(`tickets.${worst.state === 'ok' ? 'response' : worst.state}`)} {formatRemaining(worst.remaining_seconds)}</Badge>;
}

function rank(state: string): number {
    return { breached: 4, warning: 3, paused: 2, ok: 1, met: 0 }[state] ?? 0;
}
