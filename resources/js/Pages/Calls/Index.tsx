import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useRef } from 'react';
import { Phone } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Service = { id: number; service_type: string; status: string; plan_name?: string | null };
type Customer = {
    id: number;
    account_no: string;
    status: string;
    name_en: string;
    name_ar: string;
    phones: string[];
    email?: string | null;
    whatsapp?: string | null;
    services: Service[];
};
type Result = {
    matched: boolean;
    phone: string | null;
    phone_display: string;
    whatsapp_url: string | null;
    customer?: Customer;
    open_tickets?: { id: number; number: string; subject: string; status: string; priority: string }[];
    incidents?: { id: number; number: string; title: string }[];
    interactions?: { id: number; direction: string; channel: string; notes: string | null; agent: string | null; wrap_up_en: string | null; wrap_up_ar: string | null; at: string | null }[];
};

export default function CallsIndex({
    phone,
    result,
    wrap_up_codes,
    on_duty,
    extension,
    can_log,
    can_lead,
    can_edit,
    can_ticket,
    can_board,
}: {
    phone: string;
    result: Result | null;
    wrap_up_codes: { id: number; name_en: string; name_ar: string }[];
    on_duty: boolean;
    extension: string | null;
    can_log: boolean;
    can_lead: boolean;
    can_edit: boolean;
    can_ticket: boolean;
    can_board: boolean;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const search = useRef<HTMLInputElement>(null);
    const lookup = useForm({ phone });
    const log = useForm({
        direction: 'inbound',
        channel: 'phone',
        wrap_up_code_id: String(wrap_up_codes[0]?.id ?? ''),
        duration_minutes: '',
        callback_at: '',
        notes: '',
        phone,
        customer_id: result?.customer ? String(result.customer.id) : '',
        ticket_id: '',
    });
    const lead = useForm({ name: '', phone: result?.phone_display ?? phone, notes: '' });
    const contact = useForm({ email: result?.customer?.email ?? '', whatsapp: result?.customer?.whatsapp ?? '' });
    const note = useForm({ body: '' });
    const ext = useForm({ extension: extension ?? '' });

    useEffect(() => {
        search.current?.focus();
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'F2' || event.key === '/') {
                const target = event.target as HTMLElement | null;
                if (event.key === '/' && target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return;
                event.preventDefault();
                search.current?.focus();
                search.current?.select();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const find = (event: FormEvent) => {
        event.preventDefault();
        router.get('/calls', { phone: lookup.data.phone }, { preserveState: true });
    };

    const customer = result?.customer;
    const name = customer ? (locale === 'ar' ? customer.name_ar : customer.name_en) : '';

    return (
        <AppShell>
            <Head title={t('calls.title')} />
            <PageHeader
                title={t('calls.title')}
                sub={t('calls.subtitle')}
                actions={can_board ? <Button type="button" variant="outline" onClick={() => router.visit('/calls/board')}>{t('calls.board')}</Button> : null}
            />
            {page.props.flash.status ? <p className="mb-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
            <form className="mb-4 flex flex-wrap items-end gap-2" onSubmit={find} data-testid="calls-page">
                <Field id="call-search" label={t('calls.search')}>
                    <Input ref={search} id="call-search" dir="ltr" autoFocus value={lookup.data.phone} onChange={(event) => lookup.setData('phone', event.target.value)} placeholder={t('calls.placeholder')} />
                </Field>
                <Button type="submit">{t('calls.find')}</Button>
                <Button type="button" variant={on_duty ? 'default' : 'outline'} onClick={() => router.post('/calls/duty', { on_duty: !on_duty }, { preserveScroll: true })}>
                    {on_duty ? t('calls.on_duty') : t('calls.off_duty')}
                </Button>
            </form>
            <p className="mb-4 text-xs text-faint">{t('calls.shortcut')}</p>
            {!result ? <div className="card"><EmptyState icon={<Phone className="size-5" />} title={t('calls.empty')} body={t('calls.shortcut')} /></div> : null}
            {result && !result.matched ? (
                <section className="card p-5" data-testid="call-unknown">
                    <h2 className="text-lg font-semibold">{t('calls.unknown')}</h2>
                    <p className="mt-1 text-sm text-muted" dir="ltr">{result.phone_display}</p>
                    {result.whatsapp_url ? <a className="mt-3 inline-block text-sm font-semibold text-accent-ink" href={result.whatsapp_url} target="_blank" rel="noreferrer">{t('calls.whatsapp')}</a> : null}
                    {can_lead ? (
                        <form className="mt-4 grid gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); lead.post('/calls/leads', { preserveScroll: true }); }}>
                            <Field id="lead-name" label={t('calls.lead_name')}><Input id="lead-name" value={lead.data.name} onChange={(event) => lead.setData('name', event.target.value)} /></Field>
                            <Field id="lead-phone" label={t('calls.search')} error={lead.errors.phone}><Input id="lead-phone" dir="ltr" value={lead.data.phone} onChange={(event) => lead.setData('phone', event.target.value)} required /></Field>
                            <Field id="lead-notes" label={t('calls.notes')}><textarea id="lead-notes" className="min-h-20 w-full rounded-ctl border border-input bg-card p-3 text-sm" value={lead.data.notes} onChange={(event) => lead.setData('notes', event.target.value)} /></Field>
                            <div className="md:col-span-2"><Button type="submit">{t('calls.create_lead')}</Button></div>
                        </form>
                    ) : null}
                </section>
            ) : null}
            {customer && result?.matched ? (
                <div className="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
                    <section className="card p-5" data-testid="call-card">
                        {(result.incidents ?? []).map((incident) => (
                            <p key={incident.id} className="mb-3 rounded-tile bg-bad-bg px-3 py-2 text-sm text-bad ring-1 ring-bad/30" data-testid="incident-banner">
                                {t('incidents.banner', { number: incident.number, title: incident.title })}
                            </p>
                        ))}
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="text-2xl font-semibold">{name}</h2>
                                <p className="text-sm text-muted" dir="ltr">{customer.account_no}</p>
                            </div>
                            <Badge tone={customer.status === 'active' ? 'good' : 'warn'}>{t(`customers.${customer.status}`)}</Badge>
                        </div>
                        <p className="mt-3 text-sm" dir="ltr">{customer.phones.join(' · ') || '—'}</p>
                        <div className="mt-4 flex flex-wrap gap-2">
                            {result.whatsapp_url ? <a className="inline-flex h-9 items-center rounded-ctl bg-accent px-3 text-sm font-semibold text-on-accent" href={result.whatsapp_url} target="_blank" rel="noreferrer">{t('calls.whatsapp')}</a> : null}
                            {can_ticket ? <Button type="button" variant="outline" onClick={() => router.visit(`/tickets?new=1&customer=${customer.id}`)}>{t('tickets.new')}</Button> : null}
                            <Link className="inline-flex h-9 items-center rounded-ctl border border-line px-3 text-sm font-semibold" href={`/customers/${customer.id}`}>{t('calls.open_customer')}</Link>
                        </div>
                        <h3 className="mt-5 text-sm font-semibold">{t('customers.services')}</h3>
                        {customer.services.length === 0 ? <p className="mt-1 text-sm text-muted">{t('calls.no_services')}</p> : (
                            <ul className="mt-2 space-y-1 text-sm">
                                {customer.services.map((service) => <li key={service.id}>{t(`customers.${service.service_type}`)} · {service.plan_name || service.status}</li>)}
                            </ul>
                        )}
                        <h3 className="mt-5 text-sm font-semibold">{t('calls.open_tickets')}</h3>
                        {(result.open_tickets ?? []).length === 0 ? <p className="mt-1 text-sm text-muted">{t('calls.no_tickets')}</p> : (
                            <ul className="mt-2 space-y-1 text-sm">
                                {(result.open_tickets ?? []).map((ticket) => (
                                    <li key={ticket.id}><Link className="font-semibold text-accent-ink" href={`/tickets/${ticket.id}`} dir="ltr">{ticket.number}</Link> · {ticket.subject} · {ticket.priority}</li>
                                ))}
                            </ul>
                        )}
                        <h3 className="mt-5 text-sm font-semibold">{t('calls.last')}</h3>
                        {(result.interactions ?? []).length === 0 ? <p className="mt-1 text-sm text-muted">{t('calls.no_calls')}</p> : (
                            <ul className="mt-2 divide-y divide-line text-sm">
                                {(result.interactions ?? []).map((item) => (
                                    <li key={item.id} className="py-2">
                                        {t(`calls.${item.direction}`)} · {t(`calls.channel_${item.channel}`)} · {locale === 'ar' ? item.wrap_up_ar : item.wrap_up_en}
                                        {item.notes ? <span className="text-muted"> · {item.notes}</span> : null}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                    <div className="space-y-4">
                        {can_log ? (
                            <form className="card grid gap-3 p-5" onSubmit={(event) => { event.preventDefault(); log.post('/calls', { preserveScroll: true }); }}>
                                <h2 className="text-sm font-semibold">{t('calls.log')}</h2>
                                <Field id="direction" label={t('calls.direction')}>
                                    <select id="direction" className="h-10 w-full rounded-ctl border border-input bg-card px-3 text-sm" value={log.data.direction} onChange={(event) => log.setData('direction', event.target.value)}>
                                        <option value="inbound">{t('calls.inbound')}</option>
                                        <option value="outbound">{t('calls.outbound')}</option>
                                    </select>
                                </Field>
                                <Field id="channel" label={t('tickets.channel')}>
                                    <select id="channel" className="h-10 w-full rounded-ctl border border-input bg-card px-3 text-sm" value={log.data.channel} onChange={(event) => log.setData('channel', event.target.value)}>
                                        <option value="phone">{t('calls.channel_phone')}</option>
                                        <option value="whatsapp">{t('calls.channel_whatsapp')}</option>
                                    </select>
                                </Field>
                                <Field id="wrap" label={t('calls.wrap')} error={log.errors.wrap_up_code_id}>
                                    <select id="wrap" className="h-10 w-full rounded-ctl border border-input bg-card px-3 text-sm" value={log.data.wrap_up_code_id} onChange={(event) => log.setData('wrap_up_code_id', event.target.value)} required>
                                        {wrap_up_codes.map((code) => <option key={code.id} value={code.id}>{locale === 'ar' ? code.name_ar : code.name_en}</option>)}
                                    </select>
                                </Field>
                                <Field id="duration" label={t('calls.duration')}><Input id="duration" dir="ltr" type="number" min={0} value={log.data.duration_minutes} onChange={(event) => log.setData('duration_minutes', event.target.value)} /></Field>
                                <Field id="callback" label={t('calls.callback')}><Input id="callback" dir="ltr" type="datetime-local" value={log.data.callback_at} onChange={(event) => log.setData('callback_at', event.target.value)} /></Field>
                                <Field id="call-notes" label={t('calls.notes')}><textarea id="call-notes" className="min-h-20 w-full rounded-ctl border border-input bg-card p-3 text-sm" value={log.data.notes} onChange={(event) => log.setData('notes', event.target.value)} /></Field>
                                <Button type="submit">{t('calls.save')}</Button>
                            </form>
                        ) : null}
                        {can_edit ? (
                            <form className="card grid gap-3 p-5" onSubmit={(event) => { event.preventDefault(); note.post(`/customers/${customer.id}/notes`, { preserveScroll: true, onSuccess: () => note.reset() }); }}>
                                <Field id="quick-note" label={t('calls.add_note')}><textarea id="quick-note" className="min-h-16 w-full rounded-ctl border border-input bg-card p-3 text-sm" value={note.data.body} onChange={(event) => note.setData('body', event.target.value)} required /></Field>
                                <Button type="submit" variant="outline">{t('calls.add_note')}</Button>
                            </form>
                        ) : null}
                        {can_edit ? (
                            <form className="card grid gap-3 p-5" onSubmit={(event) => { event.preventDefault(); contact.patch(`/calls/customers/${customer.id}`, { preserveScroll: true }); }}>
                                <h2 className="text-sm font-semibold">{t('calls.update_contact')}</h2>
                                <Field id="contact-email" label={t('customers.email')} error={contact.errors.email}><Input id="contact-email" dir="ltr" value={contact.data.email} onChange={(event) => contact.setData('email', event.target.value)} /></Field>
                                <Field id="contact-wa" label={t('customers.whatsapp')} error={contact.errors.whatsapp}><Input id="contact-wa" dir="ltr" value={contact.data.whatsapp} onChange={(event) => contact.setData('whatsapp', event.target.value)} /></Field>
                                <Button type="submit" variant="outline">{t('calls.update_contact')}</Button>
                            </form>
                        ) : null}
                    </div>
                </div>
            ) : null}
            <form className="card mt-4 flex flex-wrap items-end gap-2 p-5" onSubmit={(event) => { event.preventDefault(); ext.post('/calls/extension', { preserveScroll: true }); }}>
                <Field id="extension" label={t('calls.extension')} error={ext.errors.extension}>
                    <Input id="extension" dir="ltr" value={ext.data.extension} onChange={(event) => ext.setData('extension', event.target.value)} />
                </Field>
                <Button type="submit" variant="outline">{t('calls.save_extension')}</Button>
            </form>
        </AppShell>
    );
}
