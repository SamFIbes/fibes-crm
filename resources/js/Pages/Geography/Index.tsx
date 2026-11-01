import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Named = { id: number; name_en: string; name_ar: string };
type District = Named & { areas: Named[] };
type Governorate = Named & { districts: District[] };

export default function GeographyIndex({ governorates, can_manage }: { governorates: Governorate[]; can_manage: boolean }) {
    const { t, locale } = useI18n();
    const { flash } = usePage<SharedProps>().props;
    const label = (row: Named) => (locale === 'ar' ? row.name_ar : row.name_en);
    const [governorateId, setGovernorateId] = useState(governorates[0]?.id ?? 0);
    const governorate = governorates.find((row) => row.id === governorateId) ?? governorates[0];
    const [districtId, setDistrictId] = useState(governorate?.districts[0]?.id ?? 0);
    const district = governorate?.districts.find((row) => row.id === districtId) ?? governorate?.districts[0];
    const area = useForm({ district_id: district?.id ?? 0, name_en: '', name_ar: '' });
    const rows = useMemo(() => {
        return (governorate?.districts ?? []).flatMap((item) => item.areas.map((place) => ({ ...place, district: label(item) })));
    }, [governorate, locale]);

    const addArea = (event: FormEvent) => {
        event.preventDefault();
        area.transform((data) => ({ ...data, district_id: district?.id ?? data.district_id }));
        area.post('/geography/areas', { preserveScroll: true, onSuccess: () => area.reset('name_en', 'name_ar') });
    };

    return (
        <AppShell>
            <Head title={t('geography.title')} />
            <PageHeader title={t('geography.title')} sub={t('geography.subtitle')} />
            {flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{flash.status}</p> : null}
            <div className="mb-4 flex flex-wrap gap-2" data-testid="geography-page">
                <label className="text-sm">
                    <span className="me-2 text-muted">{t('geography.governorate')}</span>
                    <select className="h-10 rounded-ctl border border-input bg-card px-2" value={governorate?.id ?? ''} onChange={(event) => { const id = Number(event.target.value); setGovernorateId(id); const next = governorates.find((row) => row.id === id); setDistrictId(next?.districts[0]?.id ?? 0); }}>
                        {governorates.map((row) => <option key={row.id} value={row.id}>{label(row)}</option>)}
                    </select>
                </label>
                <label className="text-sm">
                    <span className="me-2 text-muted">{t('geography.district')}</span>
                    <select className="h-10 rounded-ctl border border-input bg-card px-2" value={district?.id ?? ''} onChange={(event) => setDistrictId(Number(event.target.value))}>
                        {(governorate?.districts ?? []).map((row) => <option key={row.id} value={row.id}>{label(row)}</option>)}
                    </select>
                </label>
            </div>
            {can_manage && district ? (
                <form onSubmit={addArea} className="card mb-4 grid gap-3 p-4 md:grid-cols-3">
                    <Field id="area_en" label={t('admin.name_en')}>
                        <Input id="area_en" value={area.data.name_en} onChange={(event) => area.setData('name_en', event.target.value)} required />
                    </Field>
                    <Field id="area_ar" label={t('admin.name_ar')}>
                        <Input id="area_ar" value={area.data.name_ar} onChange={(event) => area.setData('name_ar', event.target.value)} required />
                    </Field>
                    <div className="flex items-end">
                        <Button type="submit">{t('geography.add_area')}</Button>
                    </div>
                </form>
            ) : null}
            <div className="card overflow-x-auto">
                <table className="w-full text-start">
                    <thead>
                        <tr className="border-b border-line text-2xs font-bold uppercase tracking-[0.1em] text-faint">
                            <th className="px-4 py-3 text-start">{t('geography.area')}</th>
                            <th className="px-4 py-3 text-start">{t('geography.district')}</th>
                            <th className="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? <tr><td colSpan={3} className="px-4 py-8 text-center text-muted">{t('geography.empty')}</td></tr> : null}
                        {rows.map((row) => (
                            <tr key={row.id} className="border-b border-line last:border-0">
                                <td className="px-4 py-3 text-sm font-semibold">{label(row)}</td>
                                <td className="px-4 py-3 text-sm text-muted">{row.district}</td>
                                <td className="px-4 py-3 text-end">
                                    {can_manage ? <button type="button" className="text-xs font-semibold text-bad" onClick={() => router.delete(`/geography/areas/${row.id}`, { preserveScroll: true })}>{t('settings_page.remove')}</button> : null}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppShell>
    );
}
