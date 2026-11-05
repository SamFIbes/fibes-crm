import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/form/Field';
import { Input } from '@/components/ui/input';
import { QuickTask } from '@/components/tasks/QuickTask';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

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
type TierChoice = { id: number; level: number; name_en: string; name_ar: string; members: { id: number; name: string; on_duty: boolean }[] } | null;
type Ticket = {
    id: number;
    number: string;
    subject: string;
    status: string;
    priority: string;
    channel: string;
    escalation_reason: string | null;
    handover_note: string | null;
    customer: { id: number; name_en: string; name_ar: string; account_no: string };
    category: { name_en: string; name_ar: string };
    assignee: string | null;
    tier: { level: number; name_en: string; name_ar: string } | null;
    incident: { id: number; number: string; title: string } | null;
    sla: { response: { state: string; remaining_seconds: number }; resolve: { state: string; remaining_seconds: number } };
    timeline: TimelineItem[];
    files: { id: number; name: string; download_url: string }[];
    checks?: { id: number; label_en: string; label_ar: string; done: boolean }[];
    csat?: { score: number | null; token: string; comment: string | null } | null;
    follow_up?: { due_at: string | null; status: string } | null;
    repair_job?: { id: number; number: string; status: string } | null;
};

const statuses = ['new', 'open', 'pending_customer', 'escalated', 'resolved', 'closed', 'reopened'] as const;

export default function TicketShow({
    ticket,
    technicians,
    ladder,
    incidents = [],
    can_move,
    can_escalate,
    can_upload,
    can_whatsapp = false,
}: {
    ticket: Ticket;
    technicians: { id: number; name: string }[];
    teams: { id: number; name_en: string; name_ar: string }[];
    ladder: { up: TierChoice; down: TierChoice };
    incidents?: { id: number; number: string; title: string }[];
    can_move: boolean;
    can_escalate: boolean;
    can_upload: boolean;
    can_whatsapp?: boolean;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const reply = useForm({ body: '', visibility: 'public' });
    const escalate = useForm({ assignee_id: '', team_id: '', reason: '', note: '', direction: 'escalate', tier_id: '' });
    const file = useForm<{ file: File | null }>({ file: null });
    const name = locale === 'ar' ? ticket.customer.name_ar : ticket.customer.name_en;
    const tierName = ticket.tier ? (locale === 'ar' ? ticket.tier.name_ar : ticket.tier.name_en) : null;
    const destination = escalate.data.direction === 'deescalate' ? ladder.down : ladder.up;
    const people = destination?.members?.length ? destination.members : technicians.map((person) => ({ ...person, on_duty: false }));

    const send = (event: FormEvent) => {
        event.preventDefault();
        reply.post(`/tickets/${ticket.id}/messages`, { preserveScroll: true, onSuccess: () => reply.reset('body') });
    };

    return (
        <AppShell>
            <Head title={ticket.subject} />
            <div data-testid="ticket-page">
                <p className="text-xs text-faint"><Link href="/tickets">{t('tickets.title')}</Link> · <span dir="ltr">{ticket.number}</span></p>
                <div className="mt-2 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-3xl font-semibold tracking-tight">{ticket.subject}</h1>
                        <p className="mt-1 text-sm text-muted">
                            <Link href={`/customers/${ticket.customer.id}`} className="font-semibold text-accent-ink">{name}</Link>
                            {' · '}{locale === 'ar' ? ticket.category.name_ar : ticket.category.name_en}
                            {' · '}{t(`tickets.channel_${ticket.channel}`)}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Badge tone={ticket.priority === 'P1' ? 'bad' : 'info'}>{ticket.priority}</Badge>
                        <Badge tone={ticket.sla.resolve.state === 'breached' ? 'bad' : ticket.sla.resolve.state === 'warning' ? 'warn' : 'neutral'}>
                            {t('tickets.resolve')} {ticket.sla.resolve.state}
                        </Badge>
                    </div>
                </div>
                {incidents.map((incident) => (
                    <p key={incident.id} className="mt-3 rounded-tile bg-bad-bg px-3 py-2 text-sm text-bad ring-1 ring-bad/30" data-testid="incident-banner">
                        {t('incidents.banner', { number: incident.number, title: incident.title })}
                    </p>
                ))}
                {tierName ? <p className="mt-3 text-sm text-muted">{t('tickets.current_tier')}: {tierName}</p> : null}
                {page.props.flash.status ? <p className="mt-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                {page.props.auth.abilities?.tasks_create ? <div className="mt-3"><QuickTask customerId={ticket.customer.id} ticketId={ticket.id} /></div> : null}
                {can_whatsapp ? (
                    <div className="mt-4">
                        <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/tickets/${ticket.id}/whatsapp`, {}, { preserveScroll: true })}>{t('tickets.wa_send')}</Button>
                    </div>
                ) : null}
                {can_move ? (
                    <div className="mt-4 flex flex-wrap gap-2">
                        {statuses.map((status) => (
                            <Button key={status} type="button" size="sm" variant={ticket.status === status ? 'default' : 'outline'} onClick={() => router.patch(`/tickets/${ticket.id}/status`, { status }, { preserveScroll: true })}>
                                {t(`tickets.status_${status}`)}
                            </Button>
                        ))}
                    </div>
                ) : null}
                <section className="card mt-4 divide-y divide-line" data-testid="ticket-timeline">
                    {ticket.timeline.map((item) => (
                        <article key={item.id} className="px-5 py-4">
                            {item.kind === 'escalation' ? (
                                <>
                                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-faint">
                                        {item.direction === 'deescalate' ? t('tickets.deescalate') : t('tickets.escalate')}
                                        {item.automatic ? ` · ${t('tickets.automatic')}` : ''} · {item.author || t('tickets.automatic')}
                                    </p>
                                    <p className="mt-2 text-sm font-semibold">
                                        {(locale === 'ar' ? item.from_tier_ar : item.from_tier_en) || '—'} → {(locale === 'ar' ? item.to_tier_ar : item.to_tier_en) || '—'}
                                    </p>
                                    <p className="mt-1 text-sm">{item.reason}</p>
                                    <p className="mt-1 whitespace-pre-wrap text-sm text-muted">{item.note}</p>
                                </>
                            ) : (
                                <>
                                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-faint">{item.visibility === 'internal' ? t('tickets.internal') : t('tickets.public')} · {item.author}</p>
                                    <p className="mt-2 whitespace-pre-wrap text-sm">{item.body}</p>
                                </>
                            )}
                        </article>
                    ))}
                </section>
                {can_move ? (
                    <form className="card mt-4 grid gap-3 p-5" onSubmit={send}>
                        <Field id="reply" label={t('tickets.reply')}>
                            <textarea id="reply" className="min-h-24 w-full rounded-ctl border border-input bg-card p-3 text-sm" value={reply.data.body} onChange={(event) => reply.setData('body', event.target.value)} />
                        </Field>
                        <div className="flex flex-wrap items-center gap-3">
                            <label className="text-sm"><input type="radio" checked={reply.data.visibility === 'public'} onChange={() => reply.setData('visibility', 'public')} /> {t('tickets.public')}</label>
                            <label className="text-sm"><input type="radio" checked={reply.data.visibility === 'internal'} onChange={() => reply.setData('visibility', 'internal')} /> {t('tickets.internal')}</label>
                            <Button type="submit">{t('tickets.reply')}</Button>
                        </div>
                    </form>
                ) : null}
                {can_escalate ? (
                    <form className="card mt-4 grid gap-3 p-5 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); escalate.post(`/tickets/${ticket.id}/escalate`, { preserveScroll: true }); }}>
                        <h2 className="md:col-span-2 text-sm font-semibold">{t('tickets.handover_title')}</h2>
                        <div className="md:col-span-2 flex flex-wrap gap-2">
                            <Button type="button" size="sm" variant={escalate.data.direction === 'escalate' ? 'default' : 'outline'} disabled={!ladder.up} onClick={() => escalate.setData({ ...escalate.data, direction: 'escalate', tier_id: ladder.up ? String(ladder.up.id) : '' })}>
                                {t('tickets.escalate')}{ladder.up ? ` · ${locale === 'ar' ? ladder.up.name_ar : ladder.up.name_en}` : ''}
                            </Button>
                            <Button type="button" size="sm" variant={escalate.data.direction === 'deescalate' ? 'default' : 'outline'} disabled={!ladder.down} onClick={() => escalate.setData({ ...escalate.data, direction: 'deescalate', tier_id: ladder.down ? String(ladder.down.id) : '' })}>
                                {t('tickets.deescalate')}{ladder.down ? ` · ${locale === 'ar' ? ladder.down.name_ar : ladder.down.name_en}` : ''}
                            </Button>
                        </div>
                        <Field id="tech" label={t('tickets.assignee')}>
                            <select id="tech" className="h-10 w-full rounded-ctl border border-input bg-card px-3 text-sm" value={escalate.data.assignee_id} onChange={(event) => escalate.setData('assignee_id', event.target.value)}>
                                <option value="">{t('tickets.on_duty_auto')}</option>
                                {people.map((person) => <option key={person.id} value={person.id}>{person.name}{person.on_duty ? ` · ${t('tickets.on_duty')}` : ''}</option>)}
                            </select>
                        </Field>
                        <Field id="reason" label={t('tickets.reason')}><Input id="reason" value={escalate.data.reason} onChange={(event) => escalate.setData('reason', event.target.value)} required /></Field>
                        <Field id="note" label={t('tickets.handover')}><textarea id="note" className="min-h-20 w-full rounded-ctl border border-input bg-card p-3 text-sm" value={escalate.data.note} onChange={(event) => escalate.setData('note', event.target.value)} required /></Field>
                        <div className="md:col-span-2"><Button type="submit">{escalate.data.direction === 'deescalate' ? t('tickets.deescalate') : t('tickets.escalate')}</Button></div>
                    </form>
                ) : null}
                {ticket.repair_job ? <p className="mt-3 text-sm">{t('jobs.repair')}: <a className="font-semibold text-accent-ink" href="/tech" dir="ltr">{ticket.repair_job.number}</a> · {t(`jobs.status_${ticket.repair_job.status}`)}</p> : null}
                {ticket.follow_up ? <p className="mt-2 text-sm">{t('jobs.follow_up')} · <span dir="ltr">{ticket.follow_up.due_at}</span></p> : null}
                {(ticket.checks ?? []).length > 0 ? (
                    <section className="card mt-4 p-5">
                        <h2 className="text-sm font-semibold">{t('jobs.checklist')}</h2>
                        <ul className="mt-2 space-y-1">
                            {(ticket.checks ?? []).map((check) => (
                                <li key={check.id}>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input type="checkbox" checked={check.done} onChange={(event) => router.post(`/tickets/${ticket.id}/checks/${check.id}`, { done: event.target.checked }, { preserveScroll: true })} />
                                        {locale === 'ar' ? check.label_ar : check.label_en}
                                    </label>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}
                {ticket.csat ? (
                    <form className="card mt-4 flex flex-wrap items-end gap-2 p-5" onSubmit={(event) => { event.preventDefault(); router.post(`/tickets/${ticket.id}/csat`, { score: (event.currentTarget.elements.namedItem('score') as HTMLSelectElement).value }); }}>
                        <p className="w-full text-sm font-semibold">{t('jobs.csat')}{ticket.csat.score ? ` · ${ticket.csat.score}` : ''}</p>
                        <p className="w-full text-xs text-muted" dir="ltr">{`${window.location.origin}/csat/${ticket.csat.token}`}</p>
                        <select name="score" aria-label={t('jobs.csat')} className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" defaultValue={ticket.csat.score ?? ''}>
                            <option value="">{t('filters.any')}</option>
                            {[1, 2, 3, 4, 5].map((score) => <option key={score} value={score}>{score}</option>)}
                        </select>
                        <Button type="submit" variant="outline">{t('jobs.csat_send')}</Button>
                    </form>
                ) : null}
                <section className="card mt-4 p-5">
                    <h2 className="text-sm font-semibold">{t('customers.files')}</h2>
                    <ul className="mt-2 space-y-1">
                        {ticket.files.map((item) => <li key={item.id}><a className="text-sm font-semibold text-accent-ink" href={item.download_url}>{item.name}</a></li>)}
                    </ul>
                    {can_upload ? (
                        <form className="mt-3 flex gap-2" onSubmit={(event) => { event.preventDefault(); file.post(`/tickets/${ticket.id}/files`, { forceFormData: true, preserveScroll: true }); }}>
                            <input type="file" aria-label={t('customers.upload')} onChange={(event) => file.setData('file', event.target.files?.[0] ?? null)} />
                            <Button type="submit">{t('customers.upload')}</Button>
                        </form>
                    ) : null}
                </section>
            </div>
        </AppShell>
    );
}
