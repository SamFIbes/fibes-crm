import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Inbox, LineChart, MessageSquareReply, Ticket, UserRound } from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { AppShell } from '@/components/shell/AppShell';
import { Field } from '@/components/form/Field';
import { ResponseChart, VolumeChart } from '@/components/tickets/DeskCharts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { FilterSelect } from '@/components/ui/filter-select';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { Segmented } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type SlaSide = { state: string; remaining_seconds: number };
type Suggestion = { id: string; label: string; body: string };
type LastMessage = { body: string; at: string | null; visibility: string; author: string | null };
type TimelineItem = {
    kind: 'message' | 'escalation';
    id: string;
    body?: string;
    visibility?: string;
    author: string | null;
    at: string | null;
    direction?: string;
    automatic?: boolean;
    reason?: string;
    note?: string;
    from_tier_en?: string | null;
    from_tier_ar?: string | null;
    to_tier_en?: string | null;
    to_tier_ar?: string | null;
};
type DeskTicket = {
    id: number;
    number: string;
    subject: string;
    status: string;
    priority: string;
    channel: string;
    assignee: string | null;
    customer: { id: number; name_en: string; name_ar: string; account_no: string };
    area: { name_en: string; name_ar: string } | null;
    category: { id: number; key?: string; name_en: string; name_ar: string };
    sla: { response: SlaSide; resolve: SlaSide };
    last_message: LastMessage | null;
    awaiting_reply: boolean;
    suggestions?: Suggestion[];
    timeline?: TimelineItem[];
    messages?: { id: number; body: string; visibility: string; author: string | null; at: string | null }[];
};
type CustomerProfile = {
    id: number;
    account_no: string;
    type: string;
    status: string;
    name_en: string;
    name_ar: string;
    company_name?: string | null;
    phones?: string[];
    email?: string | null;
    whatsapp?: string | null;
    area?: { name_en: string; name_ar: string } | null;
    services?: { id: number; service_type: string; plan_name?: string | null; status?: string }[];
    open_tickets?: number;
};
type Filters = {
    q: string;
    status: string;
    priority: string;
    assignee_id: number | null;
    area_id: number | null;
    service_type: string;
    sla: string;
    mine: boolean;
    category_id: number | null;
    channel: string;
};
type Insights = {
    period: { from: string; to: string; timezone: string };
    totals: { opened: number; awaiting_reply: number; avg_first_response_minutes: number | null; sla_breached: number };
    volume: { date: string; count: number }[];
    response_times: { date: string; avg_minutes: number | null; responded: number }[];
};

const statuses = ['new', 'open', 'pending_customer', 'escalated', 'resolved', 'closed', 'reopened'] as const;
const priorities = ['P1', 'P2', 'P3', 'P4'] as const;
const channels = ['phone', 'whatsapp', 'walk_in', 'email', 'field'] as const;
const tabs = ['inbox', 'queue', 'insights'] as const;
type Tab = (typeof tabs)[number];

function tabFromUrl(url: string): Tab {
    const value = new URL(url, 'http://local.invalid').searchParams.get('tab');

    return tabs.includes(value as Tab) ? (value as Tab) : 'inbox';
}

function slaTone(state: string): 'bad' | 'warn' | 'good' | 'neutral' {
    if (state === 'breached') {
        return 'bad';
    }
    if (state === 'warning') {
        return 'warn';
    }
    if (state === 'met') {
        return 'good';
    }

    return 'neutral';
}

function formatRemaining(seconds: number): string {
    const sign = seconds < 0 ? '-' : '';
    const abs = Math.abs(seconds);
    const hours = Math.floor(abs / 3600);
    const minutes = Math.floor((abs % 3600) / 60);

    return hours > 0 ? `${sign}${hours}h ${minutes}m` : `${sign}${minutes}m`;
}

function formatWhen(value: string | null, locale: string): string {
    if (!value) {
        return '';
    }
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar' : 'en', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

export default function TicketsDesk({
    inbox,
    filters,
    categories,
    areas,
    queue,
    selected,
    customer,
    insights,
    can_reply,
    service_types = [],
}: {
    inbox: DeskTicket[];
    filters: Filters;
    categories: { id: number; name_en: string; name_ar: string }[];
    areas: { id: number; name_en: string; name_ar: string }[];
    queue: DeskTicket[];
    selected: DeskTicket | null;
    customer: CustomerProfile | null;
    insights: Insights;
    can_reply: boolean;
    service_types?: string[];
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const [tab, setTab] = useState<Tab>(() => tabFromUrl(page.url));
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState(false);
    const [profileOpen, setProfileOpen] = useState(false);
    const reply = useForm({ body: '', visibility: 'public' });
    const nameOf = (row: { name_en: string; name_ar: string }) => (locale === 'ar' ? row.name_ar : row.name_en);
    const selectedName = selected ? nameOf(selected.customer) : '';
    const showConversation = Boolean(selected) && tab !== 'insights';
    const hideListOnMobile = showConversation && tab === 'inbox';

    const apply = (next: Filters, extras: Record<string, string | number | undefined> = {}) => {
        setLoadError(false);
        const ticketParam = Object.prototype.hasOwnProperty.call(extras, 'ticket')
            ? (extras.ticket ?? '')
            : (selected?.id ?? '');
        router.get('/tickets/desk', {
            q: next.q,
            status: next.status,
            priority: next.priority,
            assignee_id: next.assignee_id ?? '',
            area_id: next.area_id ?? '',
            service_type: next.service_type,
            sla: next.sla,
            mine: next.mine ? 1 : '',
            category_id: next.category_id ?? '',
            channel: next.channel,
            tab: extras.tab ?? tab,
            ticket: ticketParam,
        }, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
            onError: () => setLoadError(true),
        });
    };

    const openTicket = (id: number, nextTab: Tab = 'inbox') => {
        setTab(nextTab);
        reply.setData({ body: '', visibility: 'public' });
        apply(filters, { ticket: id, tab: nextTab });
    };

    const changeTab = (next: Tab) => {
        setTab(next);
        apply(filters, { tab: next, ticket: selected?.id });
    };

    const send = (event: FormEvent, ticketId = selected?.id) => {
        event.preventDefault();
        if (!ticketId) {
            return;
        }
        reply.post(`/tickets/${ticketId}/messages`, { preserveScroll: true });
    };

    const useSuggestion = (suggestion: Suggestion, ticketId: number) => {
        if (selected?.id !== ticketId) {
            openTicket(ticketId, 'inbox');
        }
        reply.setData('body', suggestion.body);
        window.requestAnimationFrame(() => {
            document.getElementById('desk-reply')?.focus();
        });
    };

    const thread = useMemo(() => {
        if (!selected) {
            return [];
        }
        if (selected.timeline && selected.timeline.length > 0) {
            return selected.timeline;
        }

        return (selected.messages ?? []).map((message) => ({
            kind: 'message' as const,
            id: `m${message.id}`,
            body: message.body,
            visibility: message.visibility,
            author: message.author,
            at: message.at,
        }));
    }, [selected]);

    const worstSla = (ticket: DeskTicket): SlaSide => {
        const rank = (state: string) => ({ breached: 4, warning: 3, paused: 2, ok: 1, met: 0 }[state] ?? 0);
        return [ticket.sla.response, ticket.sla.resolve].sort((a, b) => rank(b.state) - rank(a.state))[0];
    };

    return (
        <AppShell>
            <Head title={t('tickets.desk_title')} />
            <PageHeader
                title={t('tickets.desk_title')}
                sub={t('tickets.desk_subtitle')}
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" asChild>
                            <Link href="/tickets">{t('tickets.title')}</Link>
                        </Button>
                    </div>
                }
            />
            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <Segmented
                    value={tab}
                    onChange={changeTab}
                    options={[
                        { value: 'inbox', label: t('tickets.tab_inbox'), count: inbox.length },
                        { value: 'queue', label: t('tickets.tab_queue'), count: queue.length },
                        { value: 'insights', label: t('tickets.tab_insights') },
                    ]}
                />
                {loading ? <p className="text-sm text-muted" aria-live="polite">{t('a11y.loading')}</p> : null}
            </div>
            {page.props.flash.status ? (
                <p className="mb-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line" role="status">{page.props.flash.status}</p>
            ) : null}
            {loadError || reply.errors.body ? (
                <div className="mb-3 flex flex-wrap items-center gap-3 rounded-tile bg-bad-bg px-3 py-2 text-sm text-bad" role="alert">
                    <p>{reply.errors.body || t('tickets.load_error')}</p>
                    <Button type="button" size="sm" variant="outline" onClick={() => apply(filters)}>{t('tickets.retry')}</Button>
                </div>
            ) : null}

            {tab !== 'insights' ? (
                <div className="mb-4 flex flex-wrap gap-2" data-testid="desk-filters">
                    <Input
                        id="desk-search"
                        className="max-w-xs"
                        placeholder={t('tickets.search')}
                        defaultValue={filters.q}
                        aria-label={t('tickets.search')}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                apply({ ...filters, q: (event.target as HTMLInputElement).value });
                            }
                        }}
                    />
                    <FilterSelect label={t('tickets.status')} value={filters.status} onChange={(status) => apply({ ...filters, status })} options={[{ value: '', label: t('filters.any') }, ...statuses.map((status) => ({ value: status, label: t(`tickets.status_${status}`) }))]} />
                    <FilterSelect label={t('tickets.priority')} value={filters.priority} onChange={(priority) => apply({ ...filters, priority })} options={[{ value: '', label: t('filters.any') }, ...priorities.map((priority) => ({ value: priority, label: priority }))]} />
                    <FilterSelect label={t('tickets.channel')} value={filters.channel} onChange={(channel) => apply({ ...filters, channel })} options={[{ value: '', label: t('filters.any') }, ...channels.map((channel) => ({ value: channel, label: t(`tickets.channel_${channel}`) }))]} />
                    <FilterSelect label={t('tickets.category')} value={String(filters.category_id ?? '')} onChange={(value) => apply({ ...filters, category_id: value ? Number(value) : null })} options={[{ value: '', label: t('filters.any') }, ...categories.map((category) => ({ value: String(category.id), label: nameOf(category) }))]} />
                    <FilterSelect label={t('customers.area')} value={String(filters.area_id ?? '')} onChange={(area) => apply({ ...filters, area_id: area ? Number(area) : null })} options={[{ value: '', label: t('filters.any') }, ...areas.map((area) => ({ value: String(area.id), label: nameOf(area) }))]} />
                    {service_types.length > 0 ? (
                        <FilterSelect label={t('customers.service_type')} value={filters.service_type} onChange={(service) => apply({ ...filters, service_type: service })} options={[{ value: '', label: t('filters.any') }, ...service_types.map((type) => ({ value: type, label: t(`customers.${type}`) }))]} />
                    ) : null}
                    <FilterSelect label={t('tickets.sla')} value={filters.sla} onChange={(sla) => apply({ ...filters, sla })} options={[{ value: '', label: t('filters.any') }, { value: 'warning', label: t('tickets.warning') }, { value: 'breached', label: t('tickets.breached') }]} />
                    <Button type="button" variant={filters.mine ? 'default' : 'outline'} onClick={() => apply({ ...filters, mine: !filters.mine })}>{t('tickets.mine')}</Button>
                </div>
            ) : null}

            <div hidden={tab !== 'inbox'} role="tabpanel">
                <div className="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                    <section className={hideListOnMobile ? 'hidden lg:block' : undefined} aria-label={t('tickets.tab_inbox')}>
                        {loading && inbox.length === 0 ? (
                            <div className="space-y-2" aria-busy="true" aria-label={t('a11y.loading')}>
                                <Skeleton className="h-24 rounded-tile" />
                                <Skeleton className="h-24 rounded-tile" />
                                <Skeleton className="h-24 rounded-tile" />
                            </div>
                        ) : inbox.length === 0 ? (
                            <div className="card"><EmptyState icon={<Inbox className="size-5" />} title={t('tickets.inbox_empty')} body={t('tickets.desk_subtitle')} /></div>
                        ) : (
                            <ul className="space-y-2">
                                {inbox.map((ticket) => {
                                    const sla = worstSla(ticket);
                                    const active = selected?.id === ticket.id;

                                    return (
                                        <li key={ticket.id}>
                                            <button
                                                type="button"
                                                onClick={() => openTicket(ticket.id)}
                                                aria-current={active ? 'true' : undefined}
                                                className={`w-full rounded-tile border bg-card p-3 text-start shadow-card transition hover:border-text/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring ${active ? 'border-navy/30 ring-1 ring-navy/20' : 'border-line'}`}
                                            >
                                                <div className="flex items-start justify-between gap-2">
                                                    <p className="font-semibold text-text" dir="ltr">{ticket.number}</p>
                                                    <Badge tone={ticket.priority === 'P1' ? 'bad' : ticket.priority === 'P2' ? 'warn' : 'info'}>{ticket.priority}</Badge>
                                                </div>
                                                <p className="mt-1 text-sm text-text">{ticket.subject}</p>
                                                <p className="mt-1 text-xs text-muted">{nameOf(ticket.customer)} · {t(`tickets.status_${ticket.status}`)} · {t(`tickets.channel_${ticket.channel}`)}</p>
                                                {ticket.last_message ? <p className="mt-2 line-clamp-2 text-xs text-faint">{ticket.last_message.body}</p> : null}
                                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                                    <Badge tone={slaTone(sla.state)}>{t(`tickets.${sla.state === 'ok' ? 'response' : sla.state}`)} {formatRemaining(sla.remaining_seconds)}</Badge>
                                                    {ticket.awaiting_reply ? <Badge tone="warn">{t('tickets.awaiting')}</Badge> : null}
                                                </div>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </section>
                    <section className={!showConversation && tab === 'inbox' ? 'hidden lg:block' : undefined} aria-label={selected ? selected.number : t('tickets.tab_inbox')}>
                        {!selected ? (
                            <div className="card hidden lg:block"><EmptyState icon={<Ticket className="size-5" />} title={t('tickets.pick_ticket')} body={t('tickets.desk_subtitle')} /></div>
                        ) : (
                            <Conversation
                                ticket={selected}
                                thread={thread}
                                canReply={can_reply}
                                reply={reply}
                                locale={locale}
                                t={t}
                                name={selectedName}
                                onBack={() => apply(filters, { ticket: undefined })}
                                onProfile={() => setProfileOpen(true)}
                                onSuggest={(suggestion) => useSuggestion(suggestion, selected.id)}
                                onSubmit={send}
                                formatWhen={formatWhen}
                            />
                        )}
                    </section>
                </div>
            </div>

            <div hidden={tab !== 'queue'} role="tabpanel">
                {queue.length === 0 ? (
                    <div className="card"><EmptyState icon={<MessageSquareReply className="size-5" />} title={t('tickets.queue_empty')} body={t('tickets.desk_subtitle')} /></div>
                ) : (
                    <ul className="grid gap-3 lg:grid-cols-2">
                        {queue.map((ticket) => (
                            <li key={ticket.id} className="rounded-card border border-line bg-card p-4 shadow-card">
                                <div className="flex items-start justify-between gap-2">
                                    <button type="button" className="text-start font-semibold text-accent-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" onClick={() => openTicket(ticket.id)}>
                                        <span dir="ltr">{ticket.number}</span>
                                        <span className="mt-1 block text-sm font-medium text-text">{ticket.subject}</span>
                                    </button>
                                    <Badge tone={ticket.priority === 'P1' ? 'bad' : 'warn'}>{ticket.priority}</Badge>
                                </div>
                                <p className="mt-2 text-xs text-muted">{nameOf(ticket.customer)} · {nameOf(ticket.category)}</p>
                                {ticket.last_message ? <p className="mt-3 whitespace-pre-wrap text-sm text-text">{ticket.last_message.body}</p> : null}
                                <p className="mt-3 text-xs font-semibold uppercase tracking-[0.12em] text-faint">{t('tickets.suggestions')}</p>
                                <div className="mt-2 grid gap-2">
                                    {(ticket.suggestions ?? []).map((suggestion) => (
                                        <button
                                            key={suggestion.id}
                                            type="button"
                                            className="rounded-tile border border-line bg-card-2 px-3 py-2 text-start text-sm text-text transition hover:border-text/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            onClick={() => useSuggestion(suggestion, ticket.id)}
                                        >
                                            <span className="block text-xs font-semibold text-accent-ink">{suggestion.label} · {t('tickets.use_suggestion')}</span>
                                            <span className="mt-1 block text-sm">{suggestion.body}</span>
                                        </button>
                                    ))}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div hidden={tab !== 'insights'} role="tabpanel">
                {insights.totals.opened === 0 && insights.totals.awaiting_reply === 0 ? (
                    <div className="card"><EmptyState icon={<LineChart className="size-5" />} title={t('tickets.insights_empty')} body={t('tickets.desk_subtitle')} /></div>
                ) : (
                    <div className="grid gap-4">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <Stat label={t('tickets.opened')} value={String(insights.totals.opened)} hint={`${insights.period.from} – ${insights.period.to}`} />
                            <Stat label={t('tickets.awaiting')} value={String(insights.totals.awaiting_reply)} />
                            <Stat label={t('tickets.avg_response')} value={insights.totals.avg_first_response_minutes === null ? '—' : `${insights.totals.avg_first_response_minutes} ${t('tickets.minutes')}`} />
                            <Stat label={t('tickets.breached')} value={String(insights.totals.sla_breached)} />
                        </div>
                        <div className="grid gap-4 lg:grid-cols-2">
                            <div className="rounded-card border border-line bg-card p-4 shadow-card">
                                <VolumeChart data={insights.volume} label={t('tickets.volume')} unit={t('tickets.opened')} />
                            </div>
                            <div className="rounded-card border border-line bg-card p-4 shadow-card">
                                <ResponseChart data={insights.response_times} label={t('tickets.response_times')} unit={t('tickets.minutes')} />
                            </div>
                        </div>
                    </div>
                )}
            </div>

            <Drawer
                open={profileOpen && customer !== null}
                onOpenChange={setProfileOpen}
                title={customer ? nameOf(customer) : t('tickets.view_customer')}
                description={customer ? customer.account_no : undefined}
            >
                {customer ? (
                    <div className="space-y-4 px-6 py-5">
                        <dl className="grid gap-3 text-sm">
                            <Row label={t('tickets.customer')} value={nameOf(customer)} />
                            <Row label={t('customers.account')} value={customer.account_no} ltr />
                            {customer.phones && customer.phones.length > 0 ? <Row label={t('customers.phone')} value={customer.phones.join(' · ')} ltr /> : null}
                            {customer.email ? <Row label={t('customers.email')} value={customer.email} ltr /> : null}
                            {customer.whatsapp ? <Row label={t('customers.whatsapp')} value={customer.whatsapp} ltr /> : null}
                            {customer.area ? <Row label={t('customers.area')} value={nameOf(customer.area)} /> : null}
                            {customer.open_tickets !== undefined ? <Row label={t('tickets.title')} value={String(customer.open_tickets)} /> : null}
                        </dl>
                        {customer.services && customer.services.length > 0 ? (
                            <ul className="space-y-2">
                                {customer.services.map((service) => (
                                    <li key={service.id} className="rounded-tile bg-well px-3 py-2 text-sm">
                                        {t(`customers.${service.service_type}`)}
                                        {service.plan_name ? ` · ${service.plan_name}` : ''}
                                        {service.status ? ` · ${service.status}` : ''}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                        <Button type="button" asChild>
                            <Link href={`/customers/${customer.id}`}>{t('tickets.open_profile')}</Link>
                        </Button>
                    </div>
                ) : null}
            </Drawer>
        </AppShell>
    );
}

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
    return (
        <div className="rounded-card border border-line bg-card p-4 shadow-card">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-faint">{label}</p>
            <p className="mt-2 text-2xl font-semibold tracking-tight text-text">{value}</p>
            {hint ? <p className="mt-1 text-xs text-muted">{hint}</p> : null}
        </div>
    );
}

function Row({ label, value, ltr }: { label: string; value: string; ltr?: boolean }) {
    return (
        <div>
            <dt className="text-xs font-semibold uppercase tracking-[0.12em] text-faint">{label}</dt>
            <dd className="mt-1 text-sm text-text" dir={ltr ? 'ltr' : undefined}>{value}</dd>
        </div>
    );
}

function Conversation({
    ticket,
    thread,
    canReply,
    reply,
    locale,
    t,
    name,
    onBack,
    onProfile,
    onSuggest,
    onSubmit,
    formatWhen,
}: {
    ticket: DeskTicket;
    thread: TimelineItem[];
    canReply: boolean;
    reply: { data: { body: string; visibility: string }; setData: (key: string, value: string) => void; processing: boolean; errors: Record<string, string> };
    locale: string;
    t: (key: string, replace?: Record<string, string | number>) => string;
    name: string;
    onBack: () => void;
    onProfile: () => void;
    onSuggest: (suggestion: Suggestion) => void;
    onSubmit: (event: FormEvent) => void;
    formatWhen: (value: string | null, locale: string) => string;
}) {
    return (
        <div className="rounded-card border border-line bg-card shadow-card">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-line px-4 py-3 sm:px-5">
                <div className="min-w-0">
                    <button type="button" className="mb-2 text-sm font-semibold text-accent-ink lg:hidden focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" onClick={onBack}>{t('tickets.back_to_inbox')}</button>
                    <p className="font-semibold" dir="ltr">{ticket.number}</p>
                    <h2 className="text-lg font-semibold tracking-tight">{ticket.subject}</h2>
                    <p className="mt-1 text-sm text-muted">{name} · {t(`tickets.status_${ticket.status}`)} · {t(`tickets.channel_${ticket.channel}`)}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button type="button" size="sm" variant="outline" onClick={onProfile}>
                        <UserRound className="size-4" />
                        {t('tickets.view_customer')}
                    </Button>
                    <Button type="button" size="sm" variant="outline" asChild>
                        <Link href={`/tickets/${ticket.id}`}>{t('tickets.title')}</Link>
                    </Button>
                </div>
            </div>
            <div className="divide-y divide-line" data-testid="desk-thread">
                {thread.map((item) => (
                    <article key={item.id} className="px-4 py-4 sm:px-5">
                        {item.kind === 'escalation' ? (
                            <>
                                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-faint">
                                    {item.direction === 'deescalate' ? t('tickets.deescalate') : t('tickets.escalate')}
                                    {item.automatic ? ` · ${t('tickets.automatic')}` : ''} · {item.author || t('tickets.automatic')}
                                    {item.at ? ` · ${formatWhen(item.at, locale)}` : ''}
                                </p>
                                <p className="mt-2 text-sm font-semibold">
                                    {(locale === 'ar' ? item.from_tier_ar : item.from_tier_en) || '—'} → {(locale === 'ar' ? item.to_tier_ar : item.to_tier_en) || '—'}
                                </p>
                                <p className="mt-1 text-sm">{item.reason}</p>
                                <p className="mt-1 whitespace-pre-wrap text-sm text-muted">{item.note}</p>
                            </>
                        ) : (
                            <>
                                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-faint">
                                    {item.visibility === 'internal' ? t('tickets.internal') : t('tickets.public')} · {item.author}
                                    {item.at ? ` · ${formatWhen(item.at, locale)}` : ''}
                                </p>
                                <p className="mt-2 whitespace-pre-wrap text-sm">{item.body}</p>
                            </>
                        )}
                    </article>
                ))}
            </div>
            {canReply ? (
                <form className="grid gap-3 border-t border-line p-4 sm:p-5" onSubmit={onSubmit}>
                    {(ticket.suggestions ?? []).length > 0 ? (
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-faint">{t('tickets.suggestions')}</p>
                            <div className="mt-2 flex flex-col gap-2">
                                {ticket.suggestions!.map((suggestion) => (
                                    <button
                                        key={suggestion.id}
                                        type="button"
                                        className="rounded-tile border border-line bg-card-2 px-3 py-2 text-start text-sm transition hover:border-text/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        onClick={() => onSuggest(suggestion)}
                                    >
                                        <span className="block text-xs font-semibold text-accent-ink">{suggestion.label} · {t('tickets.use_suggestion')}</span>
                                        <span className="mt-1 block">{suggestion.body}</span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    ) : null}
                    <Field id="desk-reply" label={t('tickets.reply')} error={reply.errors.body}>
                        <textarea id="desk-reply" className="min-h-24 w-full rounded-ctl border border-input bg-card p-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" value={reply.data.body} onChange={(event) => reply.setData('body', event.target.value)} />
                    </Field>
                    <div className="flex flex-wrap items-center gap-3">
                        <label className="text-sm"><input type="radio" className="me-1" name="desk-visibility" checked={reply.data.visibility === 'public'} onChange={() => reply.setData('visibility', 'public')} /> {t('tickets.public')}</label>
                        <label className="text-sm"><input type="radio" className="me-1" name="desk-visibility" checked={reply.data.visibility === 'internal'} onChange={() => reply.setData('visibility', 'internal')} /> {t('tickets.internal')}</label>
                        <Button type="submit" disabled={reply.processing}>{t('tickets.reply')}</Button>
                    </div>
                </form>
            ) : null}
        </div>
    );
}
