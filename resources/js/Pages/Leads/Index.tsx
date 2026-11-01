import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import { UserRound } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { PageSkeleton } from '@/components/feedback/PageSkeleton';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import { mapPoint } from '@/lib/leadPlace';
import { CoverageMap } from '@/components/maps/CoverageMap';
import type { SharedProps } from '@/types';

type Row = {
    id: number;
    number: string | null;
    name: string;
    phone: string | null;
    email?: string | null;
    status: string;
    channel: string | null;
    owner: string | null;
    owner_id: number | null;
    due_at: string | null;
    breached: boolean;
    warned: boolean;
    whatsapp_url?: string | null;
    service?: string | null;
    notes?: string | null;
    address?: string | null;
};
type Detail = Row & {
    activities: { id: number; kind: string; body: string | null; at: string | null; user: string | null }[];
    next: string[];
    latitude?: string | null;
    longitude?: string | null;
    coverage_result?: string | null;
};
type Source = { id: number; channel: string; name_en: string; name_ar: string };
type Area = { id: number; name_en: string; name_ar: string };
type Reason = { id: number; name_en: string; name_ar: string };
type Campaign = { id: number; name: string; budget: string | null; currency: string; leads: number };

const views = ['queue', 'mine', 'sales', 'risk', 'waitlisted'] as const;

export default function LeadsIndex({
    leads, detail, view, sources, campaigns, reasons, areas, people, assignment, breaches, services,
    can_create, can_claim, can_assign, can_check, tiles,
}: {
    leads: Row[];
    detail: Detail | null;
    view: string;
    sources: Source[];
    campaigns: Campaign[];
    reasons: Reason[];
    areas: Area[];
    people: { id: number; name: string }[];
    assignment: { round_robin: boolean; by_area: boolean; by_language: boolean; by_service: boolean; cap: number; no_answer_limit: number };
    breaches: number;
    services: string[];
    can_create: boolean;
    can_claim: boolean;
    can_assign: boolean;
    can_edit: boolean;
    can_check: boolean;
    tiles: string;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [pin, setPin] = useState<string | null>(null);
    const matches = page.props.flash.lead_matches;
    const form = useForm({
        name: '', phone: '', email: '', channel: 'call', service_type_interest: 'ftth', area_id: '',
        location: '', notes: '', referrer: '', force: false, force_reason: '', intent: '', attach_to: '',
    });
    const rules = useForm({
        round_robin: assignment.round_robin,
        by_area: assignment.by_area,
        by_language: assignment.by_language,
        by_service: assignment.by_service,
        cap: assignment.cap,
        no_answer_limit: assignment.no_answer_limit,
    });
    const note = useForm({ to: 'note', body: '' });
    const campaign = useForm({ name: '', budget: '', external_id: '' });

    useEffect(() => {
        const timer = window.setInterval(() => router.reload({ only: ['leads', 'breaches', 'detail'] }), 30000);
        const offStart = router.on('start', () => setLoading(true));
        const offFinish = router.on('finish', () => setLoading(false));
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            if (target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return;
            if ((event.key === 'n' || event.key === 'N') && can_create && !event.metaKey && !event.ctrlKey) {
                event.preventDefault();
                setOpen(true);
            }
            if ((event.key === 'c' || event.key === 'C') && can_claim && !event.metaKey && !event.ctrlKey) {
                const first = leads.find((lead) => lead.owner_id === null && (lead.status === 'new' || lead.status === 'qualified'));
                if (first) {
                    event.preventDefault();
                    router.post(`/leads/${first.id}/claim`, {}, { preserveScroll: true });
                }
            }
        };
        window.addEventListener('keydown', onKey);
        return () => {
            window.clearInterval(timer);
            offStart();
            offFinish();
            window.removeEventListener('keydown', onKey);
        };
    }, [can_claim, can_create, leads]);

    const visit = (next: string) => router.get('/leads', { view: next }, { preserveState: true, preserveScroll: true });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.post('/leads', { preserveScroll: true, onSuccess: () => { form.reset('name', 'phone', 'notes'); setOpen(false); } });
    };

    return (
        <AppShell>
            <Head title={t('leads.title')} />
            {loading ? <PageSkeleton /> : null}
            <div data-testid="leads-page">
                <PageHeader title={t('leads.title')} sub={t('leads.subtitle')} actions={can_create ? <Button type="button" onClick={() => setOpen(true)}>{t('leads.add')}</Button> : null} />
                <p className="mb-3 text-xs text-faint">{t('leads.shortcut')}</p>
                {page.props.flash.status ? <p className="mb-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                {breaches > 0 ? <p className="mb-3 text-sm font-semibold text-bad">{t('leads.breached')}: <span dir="ltr">{breaches}</span></p> : null}
                <div className="mb-4 flex flex-wrap gap-2">
                    {views.map((item) => (
                        <Button key={item} type="button" size="sm" variant={view === item ? 'default' : 'outline'} onClick={() => visit(item)}>{t(`leads.${item}`)}</Button>
                    ))}
                </div>
                {open && can_create ? (
                    <form className="card mb-4 grid gap-3 p-4 md:grid-cols-3" onSubmit={save} data-testid="lead-drawer">
                        <Field id="lead-name" label={t('leads.name')}><Input id="lead-name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                        <Field id="lead-phone" label={t('leads.phone')} error={form.errors.phone}><Input id="lead-phone" dir="ltr" value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} required /></Field>
                        <Field id="lead-email" label={t('leads.email')}><Input id="lead-email" dir="ltr" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} /></Field>
                        <Field id="lead-channel" label={t('leads.channel')}>
                            <select id="lead-channel" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.channel} onChange={(event) => form.setData('channel', event.target.value)}>
                                {sources.map((source) => <option key={source.id} value={source.channel}>{locale === 'ar' ? source.name_ar : source.name_en}</option>)}
                            </select>
                        </Field>
                        <Field id="lead-service" label={t('leads.service')}>
                            <select id="lead-service" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.service_type_interest} onChange={(event) => form.setData('service_type_interest', event.target.value)}>
                                {services.map((service) => <option key={service} value={service}>{service}</option>)}
                            </select>
                        </Field>
                        <Field id="lead-area" label={t('leads.area')}>
                            <select id="lead-area" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.area_id} onChange={(event) => form.setData('area_id', event.target.value)}>
                                <option value="">{t('filters.any')}</option>
                                {areas.map((area) => <option key={area.id} value={area.id}>{locale === 'ar' ? area.name_ar : area.name_en}</option>)}
                            </select>
                        </Field>
                        <Field id="lead-location" label={t('leads.location')}>
                            <Input id="lead-location" dir="ltr" value={form.data.location} onChange={(event) => { form.setData('location', event.target.value); const point = mapPoint(event.target.value); setPin(point ? `${point.lat}, ${point.lng}` : null); }} />
                        </Field>
                        {pin ? <p className="text-xs text-muted" dir="ltr">{pin}</p> : null}
                        <Field id="lead-referrer" label={t('leads.referrer')}><Input id="lead-referrer" dir="ltr" value={form.data.referrer} onChange={(event) => form.setData('referrer', event.target.value)} /></Field>
                        <Field id="lead-notes" label={t('leads.note')}><Input id="lead-notes" value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} /></Field>
                        {matches ? (
                            <div className="md:col-span-3 rounded-tile bg-card-2 p-3 text-sm">
                                <p className="font-semibold">{t('leads.duplicate')}</p>
                                {matches.leads.map((lead) => <p key={lead.id} dir="ltr">{lead.number} · {lead.name} · {lead.phone}</p>)}
                                {matches.customers.map((customer) => <p key={customer.id}>{customer.name} · <span dir="ltr">{customer.account_no}</span></p>)}
                                {matches.possible.map((lead) => <p key={lead.id}>{t('leads.possible')}: {lead.name}</p>)}
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {matches.leads[0] ? <Button type="button" size="sm" variant="outline" onClick={() => { form.setData('attach_to', String(matches.leads[0].id)); }}>{t('leads.attach')}</Button> : null}
                                    {matches.customers.length > 0 ? (
                                        <>
                                            <Button type="button" size="sm" variant="outline" onClick={() => form.setData('intent', 'upsell')}>{t('leads.upsell')}</Button>
                                            <Button type="button" size="sm" variant="outline" onClick={() => form.setData('intent', 'support')}>{t('leads.support')}</Button>
                                        </>
                                    ) : null}
                                </div>
                                <label className="mt-2 flex items-center gap-2"><input type="checkbox" checked={form.data.force} onChange={(event) => form.setData('force', event.target.checked)} /> {t('leads.create_anyway')}</label>
                                {form.data.force ? <Input className="mt-2" value={form.data.force_reason} onChange={(event) => form.setData('force_reason', event.target.value)} placeholder={t('leads.reason')} /> : null}
                            </div>
                        ) : null}
                        <div className="md:col-span-3"><Button type="submit">{t('leads.save')}</Button></div>
                    </form>
                ) : null}
                {leads.length === 0 ? <div className="card"><EmptyState icon={<UserRound className="size-5" />} title={t('leads.empty')} body={t('leads.refresh')} /></div> : (
                    <ul className="card divide-y divide-line">
                        {leads.map((lead) => (
                            <li key={lead.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm" data-testid="lead-row">
                                <button type="button" className="text-start" onClick={() => router.get('/leads', { view, lead: lead.id }, { preserveState: true, preserveScroll: true })}>
                                    <p className="font-semibold">{lead.name || lead.number}</p>
                                    <p className="text-xs text-muted" dir="ltr">{lead.number} · {lead.phone} · {lead.due_at}</p>
                                </button>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge>{t(`leads.status_${lead.status}`)}</Badge>
                                    {lead.breached ? <Badge tone="bad">{t('leads.breached')}</Badge> : null}
                                    {lead.warned && !lead.breached ? <Badge tone="warn">{t('leads.warning')}</Badge> : null}
                                    {can_claim && lead.owner_id === null ? <Button type="button" size="sm" onClick={() => router.post(`/leads/${lead.id}/claim`, {}, { preserveScroll: true })}>{t('leads.claim')}</Button> : null}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {detail ? (
                    <section className="card mt-4 p-5" data-testid="lead-detail">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="text-xl font-semibold">{detail.name || detail.number}</h2>
                                <p className="text-sm text-muted" dir="ltr">{detail.number} · {detail.phone}</p>
                                {detail.whatsapp_url ? <a className="text-sm font-semibold text-accent-ink" href={detail.whatsapp_url} target="_blank" rel="noreferrer">{t('leads.open_chat')}</a> : null}
                            </div>
                            <Badge>{t(`leads.status_${detail.status}`)}</Badge>
                        </div>
                        {detail.status === 'qualified' ? <p className="mt-2 text-xs text-faint">{t('leads.converted_later')}</p> : null}
                        <div className="mt-3 flex flex-wrap gap-2">
                            {detail.next.includes('contacted') && detail.status === 'assigned' ? <Button type="button" size="sm" onClick={() => router.post(`/leads/${detail.id}/transition`, { to: 'contacted' }, { preserveScroll: true })}>{t('leads.contact')}</Button> : null}
                            {detail.next.includes('contacted') && detail.status === 'unreachable' ? <Button type="button" size="sm" onClick={() => router.post(`/leads/${detail.id}/transition`, { to: 'contacted' }, { preserveScroll: true })}>{t('leads.callback')}</Button> : null}
                            {detail.latitude && detail.longitude && can_check ? <Button type="button" size="sm" variant="outline" onClick={() => router.post('/coverage/checks', { subject: 'lead', subject_id: detail.id, latitude: detail.latitude, longitude: detail.longitude }, { preserveScroll: true })}>{t('coverage.check_lead')}</Button> : null}
                            {detail.coverage_result ? <Badge tone={detail.coverage_result === 'covered' ? 'good' : detail.coverage_result === 'not_covered' ? 'bad' : 'warn'}>{t(`coverage.result_${detail.coverage_result}`)}</Badge> : null}
                            {detail.next.includes('qualified') ? <Button type="button" size="sm" onClick={() => router.post(`/leads/${detail.id}/transition`, { to: 'qualified' }, { preserveScroll: true })}>{t('leads.qualify')}</Button> : null}
                            {detail.next.includes('converted') ? <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/leads/${detail.id}/transition`, { to: 'converted' }, { preserveScroll: true })}>{t('leads.handoff')}</Button> : null}
                            {detail.next.includes('unreachable') ? <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/leads/${detail.id}/transition`, { to: 'no_answer' }, { preserveScroll: true })}>{t('leads.no_answer')}</Button> : null}
                            {detail.next.includes('waitlisted') ? <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/leads/${detail.id}/transition`, { to: 'waitlisted' }, { preserveScroll: true })}>{t('leads.waitlist')}</Button> : null}
                            {detail.next.includes('assigned') && detail.status === 'disqualified' && can_assign ? <Button type="button" size="sm" onClick={() => router.post(`/leads/${detail.id}/transition`, { to: 'assigned' }, { preserveScroll: true })}>{t('leads.reopen')}</Button> : null}
                        </div>
                        {detail.next.includes('disqualified') ? (
                            <form className="mt-3 flex flex-wrap items-end gap-2" onSubmit={(event) => {
                                event.preventDefault();
                                const reason = (event.currentTarget.elements.namedItem('reason') as HTMLSelectElement).value;
                                router.post(`/leads/${detail.id}/transition`, { to: 'disqualified', disqualify_reason_id: reason }, { preserveScroll: true });
                            }}>
                                <Field id="dq" label={t('leads.reason')}>
                                    <select id="dq" name="reason" className="h-10 rounded-ctl border border-input bg-card px-2 text-sm">
                                        {reasons.map((reason) => <option key={reason.id} value={reason.id}>{locale === 'ar' ? reason.name_ar : reason.name_en}</option>)}
                                    </select>
                                </Field>
                                <Button type="submit" size="sm" variant="outline">{t('leads.disqualify')}</Button>
                            </form>
                        ) : null}
                        {can_assign ? (
                            <form className="mt-3 flex flex-wrap items-end gap-2" onSubmit={(event) => {
                                event.preventDefault();
                                const assignee = (event.currentTarget.elements.namedItem('who') as HTMLSelectElement).value;
                                router.post(`/leads/${detail.id}/assign`, { assignee_id: assignee }, { preserveScroll: true });
                            }}>
                                <Field id="who" label={t('leads.assignee')}>
                                    <select id="who" name="who" className="h-10 rounded-ctl border border-input bg-card px-2 text-sm">
                                        {people.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                                    </select>
                                </Field>
                                <Button type="submit" size="sm" variant="outline">{t('leads.assign')}</Button>
                            </form>
                        ) : null}
                        <form className="mt-3 flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); note.post(`/leads/${detail.id}/transition`, { preserveScroll: true, onSuccess: () => note.reset('body') }); }}>
                            <Field id="lead-note" label={t('leads.note')}><Input id="lead-note" value={note.data.body} onChange={(event) => note.setData('body', event.target.value)} /></Field>
                            <Button type="submit" size="sm" variant="outline">{t('leads.save_note')}</Button>
                        </form>
                        {detail.latitude && detail.longitude ? (
                            <div className="mt-4">
                                <CoverageMap tileUrl={tiles} height={220} pins={[{ id: detail.id, lat: Number(detail.latitude), lng: Number(detail.longitude), label: detail.name || detail.number || '' }]} />
                            </div>
                        ) : null}
                        <ul className="mt-4 space-y-2 text-sm">
                            {detail.activities.map((item) => (
                                <li key={item.id}><span className="text-muted" dir="ltr">{item.at}</span> · {item.kind}{item.body ? ` · ${item.body}` : ''}{item.user ? ` · ${item.user}` : ''}</li>
                            ))}
                        </ul>
                    </section>
                ) : null}
                {can_assign ? (
                    <section className="card mt-4 space-y-4 p-5" data-testid="lead-setup">
                        <h2 className="text-sm font-semibold">{t('leads.setup')}</h2>
                        <p className="text-sm text-muted">{t('leads.cap_help')}</p>
                        <form className="grid gap-3 md:grid-cols-3" onSubmit={(event) => { event.preventDefault(); rules.put('/lead-assignment', { preserveScroll: true }); }}>
                            {(['round_robin', 'by_area', 'by_language', 'by_service'] as const).map((key) => (
                                <label key={key} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={rules.data[key]} onChange={(event) => rules.setData(key, event.target.checked)} /> {t(`leads.${key}`)}</label>
                            ))}
                            <Field id="cap" label={t('leads.cap')}><Input id="cap" dir="ltr" type="number" min={0} value={String(rules.data.cap)} onChange={(event) => rules.setData('cap', Number(event.target.value))} /></Field>
                            <div className="flex items-end"><Button type="submit" variant="outline">{t('settings_page.save')}</Button></div>
                        </form>
                        <ul className="text-sm text-muted">
                            {campaigns.map((item) => <li key={item.id}>{item.name} · {t('leads.budget')} <span dir="ltr">{item.budget ?? '—'} {item.currency}</span> · {item.leads}</li>)}
                        </ul>
                        <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); campaign.post('/campaigns', { preserveScroll: true, onSuccess: () => campaign.reset() }); }}>
                            <Field id="camp-name" label={t('leads.campaign')}><Input id="camp-name" value={campaign.data.name} onChange={(event) => campaign.setData('name', event.target.value)} required /></Field>
                            <Field id="camp-budget" label={t('leads.budget')}><Input id="camp-budget" dir="ltr" value={campaign.data.budget} onChange={(event) => campaign.setData('budget', event.target.value)} /></Field>
                            <Button type="submit" variant="outline">{t('leads.save')}</Button>
                        </form>
                    </section>
                ) : null}
            </div>
        </AppShell>
    );
}
