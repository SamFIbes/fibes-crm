import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Linked = { id: number; number: string; subject: string; status: string; customer: string | null; customer_ar: string | null };
type Incident = {
    id: number;
    number: string;
    title: string;
    status: string;
    service_type: string | null;
    network_element: string | null;
    summary: string | null;
    resolution_note: string | null;
    resolution_mode: string | null;
    area: { name_en: string; name_ar: string } | null;
    district: { name_en: string; name_ar: string } | null;
    governorate: { name_en: string; name_ar: string } | null;
    linked: Linked[];
};

export default function IncidentShow({ incident }: { incident: Incident }) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const link = useForm({ ticket: '' });
    const resolve = useForm({ mode: 'update', note: '' });
    const place = [incident.governorate, incident.district, incident.area]
        .filter((row): row is { name_en: string; name_ar: string } => row !== null)
        .map((row) => (locale === 'ar' ? row.name_ar : row.name_en))
        .join(' · ');

    const linkOne = (event: FormEvent) => {
        event.preventDefault();
        link.post(`/incidents/${incident.id}/tickets`, { preserveScroll: true, onSuccess: () => link.reset() });
    };

    const close = (event: FormEvent) => {
        event.preventDefault();
        resolve.post(`/incidents/${incident.id}/resolve`, { preserveScroll: true });
    };

    return (
        <AppShell>
            <Head title={incident.title} />
            <div data-testid="incident-page">
                <p className="text-xs text-faint"><Link href="/incidents">{t('incidents.title')}</Link> · <span dir="ltr">{incident.number}</span></p>
                <div className="mt-2 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-3xl font-semibold tracking-tight">{incident.title}</h1>
                        <p className="mt-1 text-sm text-muted">{place || t('incidents.no_area')}{incident.service_type ? ` · ${t(`customers.${incident.service_type}`)}` : ''}{incident.network_element ? ` · ${incident.network_element}` : ''}</p>
                    </div>
                    <Badge tone={incident.status === 'open' ? 'bad' : 'good'}>{incident.status === 'open' ? t('incidents.open') : t('incidents.resolved_status')}</Badge>
                </div>
                {incident.summary ? <p className="mt-3 text-sm">{incident.summary}</p> : null}
                {page.props.flash.status ? <p className="mt-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                <section className="card mt-4 p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-sm font-semibold">{t('incidents.tickets')}</h2>
                        {incident.status === 'open' ? (
                            <Button type="button" variant="outline" onClick={() => router.post(`/incidents/${incident.id}/link-matching`, {}, { preserveScroll: true })}>{t('incidents.bulk')}</Button>
                        ) : null}
                    </div>
                    {incident.linked.length === 0 ? <p className="mt-3 text-sm text-muted">{t('incidents.no_tickets')}</p> : (
                        <ul className="mt-3 divide-y divide-line">
                            {incident.linked.map((ticket) => (
                                <li key={ticket.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                                    <span>
                                        <Link className="font-semibold text-accent-ink" href={`/tickets/${ticket.id}`} dir="ltr">{ticket.number}</Link>
                                        {' · '}{ticket.subject}
                                        {' · '}{locale === 'ar' ? ticket.customer_ar : ticket.customer}
                                    </span>
                                    {incident.status === 'open' ? (
                                        <button type="button" className="text-xs font-semibold text-bad" onClick={() => router.delete(`/incidents/${incident.id}/tickets/${ticket.id}`, { preserveScroll: true })}>{t('incidents.unlink')}</button>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                    {incident.status === 'open' ? (
                        <form className="mt-4 flex flex-wrap items-end gap-2" onSubmit={linkOne}>
                            <Field id="ticket" label={t('incidents.ticket_id')} error={link.errors.ticket}>
                                <Input id="ticket" dir="ltr" value={link.data.ticket} onChange={(event) => link.setData('ticket', event.target.value)} />
                            </Field>
                            <Button type="submit">{t('incidents.link')}</Button>
                        </form>
                    ) : null}
                </section>
                {incident.status === 'open' ? (
                    <form className="card mt-4 grid gap-3 p-5" onSubmit={close}>
                        <h2 className="text-sm font-semibold">{t('incidents.resolve')}</h2>
                        <p className="text-sm text-muted">{t('incidents.resolve_help')}</p>
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" size="sm" variant={resolve.data.mode === 'update' ? 'default' : 'outline'} onClick={() => resolve.setData('mode', 'update')}>{t('incidents.mode_update')}</Button>
                            <Button type="button" size="sm" variant={resolve.data.mode === 'resolve' ? 'default' : 'outline'} onClick={() => resolve.setData('mode', 'resolve')}>{t('incidents.mode_resolve')}</Button>
                        </div>
                        <Field id="note" label={t('incidents.customer_note')} error={resolve.errors.note}>
                            <textarea id="note" className="min-h-24 w-full rounded-ctl border border-input bg-card p-3 text-sm" value={resolve.data.note} onChange={(event) => resolve.setData('note', event.target.value)} required />
                        </Field>
                        <Button type="submit">{t('incidents.resolve')}</Button>
                    </form>
                ) : incident.resolution_note ? <p className="mt-4 text-sm">{incident.resolution_note}</p> : null}
            </div>
        </AppShell>
    );
}
