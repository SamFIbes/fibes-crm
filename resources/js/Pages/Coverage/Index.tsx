import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { MapPinned } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { CoverageMap, type MapPin } from '@/components/maps/CoverageMap';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Item = {
    id: number;
    name: string;
    kind: string;
    service_type: string;
    status: string;
    latitude: number | string;
    longitude: number | string;
    capacity_total: number;
    capacity_used: number;
    free: number;
};

const kinds = ['building', 'polygon', 'fat', 'olt', 'tower', 'sector'] as const;

export default function CoverageIndex({
    items,
    limits,
    can_check,
    can_edit,
    services,
    preview,
}: {
    items: Item[];
    limits: { covered_m: number; near_m: number; tile_url: string };
    can_check: boolean;
    can_edit: boolean;
    services: string[];
    preview: { name?: string; kind?: string; latitude?: number; longitude?: number }[];
}) {
    const { t } = useI18n();
    const page = usePage<SharedProps>();
    const check = useForm({ subject: 'lead', subject_id: '', location: '', address: '' });
    const place = useForm({
        name: '',
        kind: 'fat',
        service_type: services[0] ?? 'ftth',
        latitude: '33.8938',
        longitude: '35.5018',
        capacity_total: '8',
        capacity_used: '0',
        status: 'live',
        azimuth: '',
        beamwidth: '',
        range_m: '',
    });
    const incoming = useForm<{ file: File | null; apply: boolean }>({ file: null, apply: false });
    const pins: MapPin[] = items.map((item) => ({
        id: item.id,
        lat: Number(item.latitude),
        lng: Number(item.longitude),
        label: `${item.name} · ${t(`coverage.kind_${item.kind}`)}`,
        tone: 'place',
    }));
    const tone = (status: string) => (status === 'live' ? 'good' : status === 'full' ? 'bad' : 'warn');

    return (
        <AppShell>
            <Head title={t('coverage.title')} />
            <div data-testid="coverage-page">
                <PageHeader title={t('coverage.title')} sub={t('coverage.subtitle')} />
                <p className="mb-4 text-sm text-muted">{t('coverage.limits', { covered: limits.covered_m, near: limits.near_m })}</p>
                {page.props.flash.status ? <p className="mb-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(18rem,1fr)]">
                    <CoverageMap tileUrl={limits.tile_url} pins={pins} />
                    <section className="card max-h-[420px] overflow-auto">
                        <h2 className="border-b border-line px-4 py-3 text-sm font-semibold">{t('coverage.layers')}</h2>
                        {items.length === 0 ? <EmptyState icon={<MapPinned className="size-5" />} title={t('coverage.empty')} body={t('coverage.subtitle')} /> : (
                            <ul className="divide-y divide-line">
                                {items.map((item) => (
                                    <li key={item.id} className="flex items-start justify-between gap-3 px-4 py-3 text-sm">
                                        <div>
                                            <p className="font-semibold">{item.name}</p>
                                            <p className="text-muted">{t(`coverage.kind_${item.kind}`)} · {t(`customers.${item.service_type}`)} · {t('coverage.free')} {item.free}/{item.capacity_total}</p>
                                        </div>
                                        <div className="flex flex-col items-end gap-2">
                                            <Badge tone={tone(item.status)}>{t(`coverage.status_${item.status}`)}</Badge>
                                            {can_edit && item.status !== 'live' ? (
                                                <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/coverage/items/${item.id}/publish`, {}, { preserveScroll: true })}>{t('coverage.publish')}</Button>
                                            ) : null}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
                {can_check ? (
                    <form className="card mt-4 grid gap-3 p-5 md:grid-cols-4" onSubmit={(event: FormEvent) => { event.preventDefault(); check.post('/coverage/checks', { preserveScroll: true }); }}>
                        <Field id="check-subject" label={t('coverage.subject')}>
                            <select id="check-subject" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={check.data.subject} onChange={(event) => check.setData('subject', event.target.value)}>
                                <option value="lead">{t('coverage.lead')}</option>
                                <option value="opportunity">{t('coverage.opportunity')}</option>
                            </select>
                        </Field>
                        <Field id="check-id" label={t('coverage.subject_id')}><Input id="check-id" dir="ltr" value={check.data.subject_id} onChange={(event) => check.setData('subject_id', event.target.value)} required /></Field>
                        <Field id="check-location" label={t('coverage.location')} error={check.errors.location}><Input id="check-location" dir="ltr" value={check.data.location} onChange={(event) => check.setData('location', event.target.value)} placeholder="33.89, 35.50" /></Field>
                        <div className="flex items-end"><Button type="submit">{t('coverage.check')}</Button></div>
                    </form>
                ) : null}
                {can_edit ? (
                    <>
                        <form className="card mt-4 grid gap-3 p-5 md:grid-cols-3" onSubmit={(event: FormEvent) => { event.preventDefault(); place.post('/coverage/items', { preserveScroll: true, onSuccess: () => place.reset('name') }); }}>
                            <Field id="place-name" label={t('coverage.name')}><Input id="place-name" value={place.data.name} onChange={(event) => place.setData('name', event.target.value)} required /></Field>
                            <Field id="place-kind" label={t('coverage.kind')}>
                                <select id="place-kind" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={place.data.kind} onChange={(event) => place.setData('kind', event.target.value)}>
                                    {kinds.map((kind) => <option key={kind} value={kind}>{t(`coverage.kind_${kind}`)}</option>)}
                                </select>
                            </Field>
                            <Field id="place-service" label={t('coverage.service')}>
                                <select id="place-service" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={place.data.service_type} onChange={(event) => place.setData('service_type', event.target.value)}>
                                    {services.map((service) => <option key={service} value={service}>{t(`customers.${service}`)}</option>)}
                                </select>
                            </Field>
                            <Field id="place-lat" label={t('customers.pin')}><Input id="place-lat" dir="ltr" value={place.data.latitude} onChange={(event) => place.setData('latitude', event.target.value)} required /></Field>
                            <Field id="place-lng" label={t('coverage.location')}><Input id="place-lng" dir="ltr" value={place.data.longitude} onChange={(event) => place.setData('longitude', event.target.value)} required /></Field>
                            <Field id="place-ports" label={t('coverage.capacity')}><Input id="place-ports" dir="ltr" value={place.data.capacity_total} onChange={(event) => place.setData('capacity_total', event.target.value)} /></Field>
                            <Field id="place-used" label={t('coverage.used')}><Input id="place-used" dir="ltr" value={place.data.capacity_used} onChange={(event) => place.setData('capacity_used', event.target.value)} /></Field>
                            <Field id="place-status" label={t('tasks.status')}>
                                <select id="place-status" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={place.data.status} onChange={(event) => place.setData('status', event.target.value)}>
                                    <option value="live">{t('coverage.status_live')}</option>
                                    <option value="planned">{t('coverage.status_planned')}</option>
                                    <option value="full">{t('coverage.status_full')}</option>
                                </select>
                            </Field>
                            {place.data.kind === 'sector' ? (
                                <>
                                    <Field id="place-azimuth" label={t('coverage.azimuth')}><Input id="place-azimuth" dir="ltr" value={place.data.azimuth} onChange={(event) => place.setData('azimuth', event.target.value)} /></Field>
                                    <Field id="place-beam" label={t('coverage.beam')}><Input id="place-beam" dir="ltr" value={place.data.beamwidth} onChange={(event) => place.setData('beamwidth', event.target.value)} /></Field>
                                    <Field id="place-range" label={t('coverage.range')}><Input id="place-range" dir="ltr" value={place.data.range_m} onChange={(event) => place.setData('range_m', event.target.value)} /></Field>
                                </>
                            ) : null}
                            <div className="flex items-end"><Button type="submit">{t('coverage.add')}</Button></div>
                        </form>
                        <form className="card mt-4 grid gap-3 p-5 md:grid-cols-[1fr_auto_auto]" onSubmit={(event: FormEvent) => { event.preventDefault(); incoming.post('/coverage/import', { forceFormData: true, preserveScroll: true }); }}>
                            <Field id="coverage-file" label={t('coverage.import')} error={incoming.errors.file}>
                                <Input id="coverage-file" type="file" onChange={(event) => incoming.setData('file', event.target.files?.[0] ?? null)} />
                            </Field>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={incoming.data.apply} onChange={(event) => incoming.setData('apply', event.target.checked)} />
                                {t('coverage.apply')}
                            </label>
                            <div className="flex items-end"><Button type="submit" variant="outline">{incoming.data.apply ? t('coverage.apply') : t('coverage.preview')}</Button></div>
                        </form>
                        {preview.length > 0 ? (
                            <ul className="card mt-4 divide-y divide-line text-sm">
                                {preview.map((row, index) => (
                                    <li key={`${row.name}-${index}`} className="px-4 py-2">{row.name} · {row.kind} · <span dir="ltr">{row.latitude}, {row.longitude}</span></li>
                                ))}
                            </ul>
                        ) : null}
                    </>
                ) : null}
            </div>
        </AppShell>
    );
}
