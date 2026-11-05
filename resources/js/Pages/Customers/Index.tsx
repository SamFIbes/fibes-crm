import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { ColumnDef } from '@tanstack/react-table';
import { FormEvent, useEffect, useState } from 'react';
import { Field } from '@/components/form/Field';
import { EmptyState } from '@/components/feedback/EmptyState';
import { AppShell } from '@/components/shell/AppShell';
import { DataTable } from '@/components/table/DataTable';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { FilterSelect } from '@/components/ui/filter-select';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { PlanPicker } from '@/components/catalog/PlanPicker';
import { useI18n } from '@/lib/i18n';
import type { CatalogPlan } from '@/lib/catalog';
import type { SharedProps } from '@/types';
import { initials } from '@/lib/utils';
import { Users } from 'lucide-react';

type AreaOption = { id: number; name_en: string; name_ar: string };
type Service = { id: number; service_type: string; status: string; monthly_usd?: string; monthly_lbp?: number | null };
type Row = {
    id: number;
    account_no: string;
    type: string;
    status: string;
    name_en: string;
    name_ar: string;
    phones: string[];
    area: { name_en: string; name_ar: string } | null;
    services: Service[];
};
type SavedView = { id: number; name: string; filters: Record<string, string | number | null> };

const statuses = ['lead', 'active', 'suspended', 'terminated'] as const;
const types = ['ftth', 'fttb', 'ptmp', 'ptp', 'internal', 'dsl'] as const;

export default function CustomersIndex({
    customers,
    filters,
    areas,
    views,
    can_create,
    can_edit,
    service_types,
    plans = [],
}: {
    customers: Row[];
    filters: { q: string; status: string; area_id: number | null; service_type: string };
    areas: AreaOption[];
    views: SavedView[];
    can_create: boolean;
    can_edit: boolean;
    service_types: string[];
    plans?: CatalogPlan[];
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const [open, setOpen] = useState(page.url.includes('new=1'));
    const [viewName, setViewName] = useState('');
    const form = useForm({
        type: 'individual',
        status: 'lead',
        name_en: '',
        name_ar: '',
        company_name: '',
        email: '',
        whatsapp: '',
        phones: [''],
        area_id: '' as number | '',
        building: '',
        floor: '',
        landmark: '',
        latitude: '',
        longitude: '',
        tags: '',
        notes: '',
        service_type: '',
        plan_name: '',
        plan_id: '',
        speed: '',
        monthly_usd: '',
    });

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            const typing = target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName);
            if (event.key === '/' && !typing) {
                event.preventDefault();
                document.getElementById('customer-search')?.focus();
            }
            if ((event.key === 'c' || event.key === 'C') && !typing && can_create && !event.metaKey && !event.ctrlKey) {
                event.preventDefault();
                setOpen(true);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [can_create]);

    const apply = (next: Record<string, string | number | null>) => {
        router.get('/customers', {
            q: next.q ?? '',
            status: next.status ?? '',
            area_id: next.area_id ?? '',
            service_type: next.service_type ?? '',
        }, { preserveState: true, preserveScroll: true });
    };

    const columns: ColumnDef<Row, unknown>[] = [
        {
            header: t('customers.account'),
            accessorKey: 'account_no',
            cell: ({ row }) => <Link className="font-semibold text-accent-ink" dir="ltr" href={`/customers/${row.original.id}`}>{row.original.account_no}</Link>,
        },
        {
            header: t('customers.name_en'),
            id: 'name',
            cell: ({ row }) => {
                const person = locale === 'ar' ? row.original.name_ar : row.original.name_en;

                return (
                    <div className="flex items-center gap-2">
                        <span className="grid size-8 shrink-0 place-items-center rounded-full bg-brand-gradient text-2xs font-bold text-navy">{initials(person)}</span>
                        <div>
                            <div className="font-semibold">{person}</div>
                            <div className="text-xs text-muted" dir="ltr">{row.original.phones[0] ?? t('customers.hidden')}</div>
                        </div>
                    </div>
                );
            },
        },
        {
            header: t('customers.area'),
            id: 'area',
            cell: ({ row }) => <span className="text-sm">{row.original.area ? (locale === 'ar' ? row.original.area.name_ar : row.original.area.name_en) : '—'}</span>,
        },
        {
            header: t('customers.status'),
            id: 'status',
            cell: ({ row }) => can_edit ? (
                <select aria-label={t('customers.status')} className="h-8 rounded-ctl border border-input bg-card px-2 text-sm" value={row.original.status} onChange={(event) => router.patch(`/customers/${row.original.id}`, { status: event.target.value }, { preserveScroll: true })}>
                    {statuses.map((status) => <option key={status} value={status}>{t(`customers.${status}`)}</option>)}
                </select>
            ) : <Badge tone={row.original.status === 'active' ? 'good' : row.original.status === 'suspended' ? 'warn' : 'neutral'}>{t(`customers.${row.original.status}`)}</Badge>,
        },
        {
            header: t('customers.services'),
            id: 'services',
            cell: ({ row }) => <span className="text-sm">{row.original.services.map((service) => t(`customers.${service.service_type}`)).join(' · ') || '—'}</span>,
        },
    ];

    const create = (event: FormEvent) => {
        event.preventDefault();
        form.post('/customers');
    };

    return (
        <AppShell>
            <Head title={t('customers.title')} />
            <PageHeader
                title={t('customers.title')}
                sub={t('customers.subtitle')}
                actions={can_create ? <Button type="button" onClick={() => setOpen(true)}>{t('customers.new')}</Button> : null}
            />
            {page.props.flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
            {page.props.flash.link ? <p className="mb-4 text-sm" dir="ltr"><a className="font-semibold text-accent-ink" href={page.props.flash.link}>{page.props.flash.link}</a></p> : null}
            <p className="mb-3 text-xs text-faint">{t('customers.shortcut')}</p>
            <div className="card mb-4 flex flex-wrap items-end gap-2 p-3" data-testid="customers-page">
                <Input id="customer-search" data-testid="customer-search" className="max-w-xs" placeholder={t('customers.search')} defaultValue={filters.q} onKeyDown={(event) => { if (event.key === 'Enter') apply({ ...filters, q: (event.target as HTMLInputElement).value }); }} />
                <FilterSelect label={t('customers.status')} value={filters.status} onChange={(status) => apply({ ...filters, status })} options={[{ value: '', label: t('filters.any') }, ...statuses.map((status) => ({ value: status, label: t(`customers.${status}`) }))]} />
                <FilterSelect label={t('customers.area')} value={String(filters.area_id ?? '')} onChange={(area) => apply({ ...filters, area_id: area ? Number(area) : null })} options={[{ value: '', label: t('filters.any') }, ...areas.map((area) => ({ value: String(area.id), label: locale === 'ar' ? area.name_ar : area.name_en }))]} />
                <FilterSelect label={t('customers.service_type')} value={filters.service_type} onChange={(service) => apply({ ...filters, service_type: service })} options={[{ value: '', label: t('filters.any') }, ...service_types.map((type) => ({ value: type, label: t(`customers.${type}`) }))]} />
                <Button type="button" variant="outline" onClick={() => router.post('/exports/customers')}>{t('customers.export_csv')}</Button>
            </div>
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <span className="text-xs font-semibold uppercase tracking-[0.12em] text-faint">{t('customers.saved_views')}</span>
                {views.map((view) => (
                    <button key={view.id} type="button" className="rounded-full border border-[var(--glass-border)] bg-[var(--glass)] px-3 py-1 text-sm" onClick={() => apply(view.filters)}>{view.name}</button>
                ))}
                <Input className="h-8 max-w-[10rem]" placeholder={t('customers.view_name')} value={viewName} onChange={(event) => setViewName(event.target.value)} />
                <Button type="button" variant="outline" size="sm" onClick={() => viewName && router.post('/customer-views', { name: viewName, filters }, { preserveScroll: true, onSuccess: () => setViewName('') })}>{t('customers.save_view')}</Button>
            </div>
            {customers.length === 0 ? (
                <div className="card">
                    <EmptyState icon={<Users className="size-5" />} title={t('customers.empty')} body={t('customers.shortcut')} />
                </div>
            ) : <DataTable columns={columns} data={customers} />}
            <Drawer open={open} onOpenChange={setOpen} title={t('customers.new')} description={t('customers.shortcut')}>
                <form id="new-customer" className="grid gap-3 p-6" data-testid="customer-drawer" onSubmit={create} onKeyDown={(event) => { if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') create(event); }}>
                    {form.errors.phones ? <p role="alert" className="text-sm text-bad">{form.errors.phones}</p> : null}
                    <div className="grid grid-cols-2 gap-3">
                        <Field id="ctype" label={t('customers.type')}>
                            <select id="ctype" className="h-10 w-full rounded-ctl border border-input bg-card px-2" value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}>
                                <option value="individual">{t('customers.individual')}</option>
                                <option value="business">{t('customers.business')}</option>
                            </select>
                        </Field>
                        <Field id="cstatus" label={t('customers.status')}>
                            <select id="cstatus" className="h-10 w-full rounded-ctl border border-input bg-card px-2" value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                                {statuses.map((status) => <option key={status} value={status}>{t(`customers.${status}`)}</option>)}
                            </select>
                        </Field>
                    </div>
                    <Field id="cname" label={t('customers.name_en')} error={form.errors.name_en}><Input id="cname" value={form.data.name_en} onChange={(event) => form.setData('name_en', event.target.value)} required /></Field>
                    <Field id="cnamear" label={t('customers.name_ar')} error={form.errors.name_ar}><Input id="cnamear" value={form.data.name_ar} onChange={(event) => form.setData('name_ar', event.target.value)} required /></Field>
                    <Field id="cphone" label={t('customers.phone')}><Input id="cphone" dir="ltr" value={form.data.phones[0]} onChange={(event) => form.setData('phones', [event.target.value])} required /></Field>
                    <Field id="cwa" label={t('customers.whatsapp')}><Input id="cwa" dir="ltr" value={form.data.whatsapp} onChange={(event) => form.setData('whatsapp', event.target.value)} /></Field>
                    <Field id="carea" label={t('customers.area')}>
                        <select id="carea" className="h-10 w-full rounded-ctl border border-input bg-card px-2" value={form.data.area_id} onChange={(event) => form.setData('area_id', event.target.value ? Number(event.target.value) : '')}>
                            <option value="">—</option>
                            {areas.map((area) => <option key={area.id} value={area.id}>{locale === 'ar' ? area.name_ar : area.name_en}</option>)}
                        </select>
                    </Field>
                    <div className="grid grid-cols-3 gap-2">
                        <Field id="cbuilding" label={t('customers.building')}><Input id="cbuilding" value={form.data.building} onChange={(event) => form.setData('building', event.target.value)} /></Field>
                        <Field id="cfloor" label={t('customers.floor')}><Input id="cfloor" value={form.data.floor} onChange={(event) => form.setData('floor', event.target.value)} /></Field>
                        <Field id="clandmark" label={t('customers.landmark')}><Input id="clandmark" value={form.data.landmark} onChange={(event) => form.setData('landmark', event.target.value)} /></Field>
                    </div>
                    <Field id="cplan" label={t('customers.plan')}>
                        <PlanPicker id="cplan" plans={plans} value={form.data.plan_id} arabic={locale === 'ar'} onChange={(plan) => form.setData({
                            ...form.data,
                            plan_id: plan ? String(plan.id) : '',
                            plan_name: plan?.name ?? form.data.plan_name,
                            speed: plan?.down_mbps ? `${plan.down_mbps} / ${plan.up_mbps ?? plan.down_mbps} Mb` : form.data.speed,
                            monthly_usd: plan?.price ?? form.data.monthly_usd,
                            service_type: plan?.service_type ?? form.data.service_type,
                        })} />
                    </Field>
                    <Field id="cservice" label={t('customers.service_type')}>
                        <select id="cservice" className="h-10 w-full rounded-ctl border border-input bg-card px-2" value={form.data.service_type} onChange={(event) => form.setData('service_type', event.target.value)}>
                            <option value="">—</option>
                            {types.map((type) => <option key={type} value={type}>{t(`customers.${type}`)}</option>)}
                        </select>
                    </Field>
                    <div className="grid grid-cols-2 gap-2">
                        <Field id="cspeed" label={t('customers.speed')}><Input id="cspeed" value={form.data.speed} onChange={(event) => form.setData('speed', event.target.value)} /></Field>
                        <Field id="cprice" label={t('customers.monthly')}><Input id="cprice" dir="ltr" inputMode="decimal" value={form.data.monthly_usd} onChange={(event) => form.setData('monthly_usd', event.target.value)} /></Field>
                    </div>
                </form>
                <div className="border-t border-line px-6 py-3">
                    <Button type="submit" form="new-customer" disabled={form.processing}>{t('customers.save')}</Button>
                </div>
            </Drawer>
        </AppShell>
    );
}
