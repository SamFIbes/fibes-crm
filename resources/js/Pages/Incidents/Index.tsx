import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import { Siren } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { FilterSelect } from '@/components/ui/filter-select';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Area = { id: number; name_en: string; name_ar: string };
type District = { id: number; name_en: string; name_ar: string; areas: Area[] };
type Governorate = { id: number; name_en: string; name_ar: string; districts: District[] };
type Incident = {
    id: number;
    number: string;
    title: string;
    status: string;
    service_type: string | null;
    network_element: string | null;
    area: { name_en: string; name_ar: string } | null;
    district: { name_en: string; name_ar: string } | null;
    governorate: { name_en: string; name_ar: string } | null;
    tickets: number;
};

export default function IncidentsIndex({
    incidents,
    filters,
    geography,
    service_types,
}: {
    incidents: Incident[];
    filters: { status: string };
    geography: Governorate[];
    service_types: string[];
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const [open, setOpen] = useState(false);
    const form = useForm({
        title: '',
        governorate_id: '',
        district_id: '',
        area_id: '',
        service_type: '',
        network_element: '',
        summary: '',
    });
    const label = (row: { name_en: string; name_ar: string } | null) => (row ? (locale === 'ar' ? row.name_ar : row.name_en) : '');
    const governorate = geography.find((row) => String(row.id) === form.data.governorate_id);
    const districts = governorate?.districts ?? [];
    const district = districts.find((row) => String(row.id) === form.data.district_id);
    const areas = district?.areas ?? [];
    const place = (incident: Incident) => [label(incident.governorate), label(incident.district), label(incident.area)].filter(Boolean).join(' · ');

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT')) return;
            if (event.key === 'n' && !event.metaKey && !event.ctrlKey) {
                event.preventDefault();
                setOpen(true);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            governorate_id: data.governorate_id === '' ? null : Number(data.governorate_id),
            district_id: data.district_id === '' ? null : Number(data.district_id),
            area_id: data.area_id === '' ? null : Number(data.area_id),
            service_type: data.service_type === '' ? null : data.service_type,
        }));
        form.post('/incidents');
    };

    return (
        <AppShell>
            <Head title={t('incidents.title')} />
            <PageHeader title={t('incidents.title')} sub={t('incidents.subtitle')} actions={<Button type="button" onClick={() => setOpen(true)}>{t('incidents.new')}</Button>} />
            <p className="mb-3 text-xs text-faint">{t('incidents.shortcut')}</p>
            {page.props.flash.status ? <p className="mb-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
            <div className="mb-4" data-testid="incidents-page">
                <FilterSelect
                    label={t('tickets.status')}
                    value={filters.status}
                    onChange={(status) => router.get('/incidents', status ? { status } : {}, { preserveState: true })}
                    options={[{ value: '', label: t('filters.any') }, { value: 'open', label: t('incidents.open') }, { value: 'resolved', label: t('incidents.resolved_status') }]}
                />
            </div>
            {incidents.length === 0 ? (
                <div className="card"><EmptyState icon={<Siren className="size-5" />} title={t('incidents.empty')} body={t('incidents.shortcut')} /></div>
            ) : (
                <ul className="card divide-y divide-line">
                    {incidents.map((incident) => (
                        <li key={incident.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                            <div>
                                <Link className="font-semibold text-accent-ink" href={`/incidents/${incident.id}`} dir="ltr">{incident.number}</Link>
                                <p className="text-sm">{incident.title}</p>
                                <p className="text-xs text-muted">{place(incident)}{incident.service_type ? ` · ${t(`customers.${incident.service_type}`)}` : ''}{incident.network_element ? ` · ${incident.network_element}` : ''}</p>
                            </div>
                            <div className="flex items-center gap-2">
                                <Badge tone={incident.status === 'open' ? 'bad' : 'good'}>{incident.status === 'open' ? t('incidents.open') : t('incidents.resolved_status')}</Badge>
                                <span className="text-xs text-muted">{t('incidents.linked_count', { count: incident.tickets })}</span>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <Drawer open={open} onOpenChange={setOpen} title={t('incidents.new')} description={t('incidents.subtitle')}>
                <form id="new-incident" className="grid gap-3 px-6 py-4" onSubmit={save}>
                    <Field id="ititle" label={t('incidents.name')} error={form.errors.title}><Input id="ititle" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} required /></Field>
                    <Field id="igov" label={t('geography.governorate')}>
                        <select id="igov" className="h-10 w-full rounded-ctl border border-input bg-card px-3 text-sm" value={form.data.governorate_id} onChange={(event) => form.setData({ ...form.data, governorate_id: event.target.value, district_id: '', area_id: '' })}>
                            <option value="">{t('filters.any')}</option>
                            {geography.map((row) => <option key={row.id} value={row.id}>{label(row)}</option>)}
                        </select>
                    </Field>
                    <Field id="idist" label={t('geography.district')}>
                        <select id="idist" className="h-10 w-full rounded-ctl border border-input bg-card px-3 text-sm" value={form.data.district_id} onChange={(event) => form.setData({ ...form.data, district_id: event.target.value, area_id: '' })}>
                            <option value="">{t('filters.any')}</option>
                            {districts.map((row) => <option key={row.id} value={row.id}>{label(row)}</option>)}
                        </select>
                    </Field>
                    <Field id="iarea" label={t('geography.area')}>
                        <select id="iarea" className="h-10 w-full rounded-ctl border border-input bg-card px-3 text-sm" value={form.data.area_id} onChange={(event) => form.setData('area_id', event.target.value)}>
                            <option value="">{t('filters.any')}</option>
                            {areas.map((row) => <option key={row.id} value={row.id}>{label(row)}</option>)}
                        </select>
                    </Field>
                    <Field id="itype" label={t('customers.service_type')}>
                        <select id="itype" className="h-10 w-full rounded-ctl border border-input bg-card px-3 text-sm" value={form.data.service_type} onChange={(event) => form.setData('service_type', event.target.value)}>
                            <option value="">{t('filters.any')}</option>
                            {service_types.map((type) => <option key={type} value={type}>{t(`customers.${type}`)}</option>)}
                        </select>
                    </Field>
                    <Field id="inode" label={t('incidents.element')}><Input id="inode" value={form.data.network_element} onChange={(event) => form.setData('network_element', event.target.value)} /></Field>
                    <Field id="isummary" label={t('incidents.summary')}><textarea id="isummary" className="min-h-24 w-full rounded-ctl border border-input bg-card p-3 text-sm" value={form.data.summary} onChange={(event) => form.setData('summary', event.target.value)} /></Field>
                </form>
                <div className="border-t border-line px-6 py-3"><Button type="submit" form="new-incident" disabled={form.processing}>{t('incidents.open_action')}</Button></div>
            </Drawer>
        </AppShell>
    );
}
