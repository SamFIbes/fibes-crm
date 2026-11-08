import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { Field } from '@/components/form/Field';
import { EmptyState } from '@/components/feedback/EmptyState';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { QuickTask } from '@/components/tasks/QuickTask';
import { CoverageMap } from '@/components/maps/CoverageMap';
import { PlanPicker } from '@/components/catalog/PlanPicker';
import type { CatalogPlan } from '@/lib/catalog';
import { Receipt, Ticket } from 'lucide-react';

type Area = { name_en: string; name_ar: string; district_en: string; district_ar: string; governorate_en: string; governorate_ar: string } | null;
type Service = {
    id: number;
    service_type: string;
    plan_name: string | null;
    speed: string | null;
    status: string;
    install_date: string | null;
    monthly_usd?: string;
    monthly_lbp?: number | null;
    radius_username?: string | null;
    static_ip?: string | null;
    mac?: string | null;
    nas?: string | null;
    vlan?: string | null;
    radius_online?: boolean | null;
    radius_status?: string | null;
    radius_framed_ip?: string | null;
    radius_checked_at?: string | null;
    ont_serial?: string | null;
    installed?: { serial: string; mac: string | null }[];
    router_model?: string | null;
    has_radius_password: boolean;
};
type Customer = {
    id: number;
    account_no: string;
    type: string;
    status: string;
    name_en: string;
    name_ar: string;
    company_name: string | null;
    phones: string[];
    email?: string | null;
    whatsapp?: string | null;
    area: Area;
    building: string | null;
    floor: string | null;
    landmark: string | null;
    latitude: string | null;
    longitude: string | null;
    tags: string[];
    notes: string | null;
    whatsapp_consent?: boolean;
    services: Service[];
};
type Note = { id: number; body: string; author: string | null; at: string | null };
type FileRow = { id: number; name: string; download_url: string };
type Activity = { id: number; event: string; at: string | null };

const tabs = ['overview', 'services', 'tickets', 'billing', 'files', 'activity', 'notes'] as const;

export default function CustomerShow({
    customer,
    notes,
    files,
    activity,
    tickets,
    incidents = [],
    can_edit,
    can_upload,
    can_reveal,
    can_see_money,
    can_edit_radius,
    tiles,
    plans = [],
    orders = [],
    billing_balance = null,
    billing_invoices = [],
    can_dial = false,
    can_check_radius = false,
    radius_live = false,
    whatsapp_live = false,
}: {
    customer: Customer;
    notes: Note[];
    files: FileRow[];
    activity: Activity[];
    tickets: { id: number; number: string; subject: string; status: string; priority: string }[];
    incidents?: { id: number; number: string; title: string }[];
    areas: { id: number; name_en: string; name_ar: string }[];
    can_edit: boolean;
    can_upload: boolean;
    can_reveal: boolean;
    can_see_money: boolean;
    can_edit_radius: boolean;
    service_types: string[];
    tiles: string;
    plans?: CatalogPlan[];
    orders?: { id: number; number: string | null; type: string; status: string; sla_due: string | null }[];
    billing_balance?: string | null;
    billing_invoices?: { id: number; number: string | null; type: string; status: string; total: string; lbp: string }[];
    can_dial?: boolean;
    can_check_radius?: boolean;
    radius_live?: boolean;
    whatsapp_live?: boolean;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const [tab, setTab] = useState<(typeof tabs)[number]>('overview');
    const [revealed, setRevealed] = useState<Record<number, string>>({});
    const note = useForm({ body: '' });
    const file = useForm<{ file: File | null }>({ file: null });
    const service = useForm({
        service_type: 'ftth',
        plan_id: '',
        plan_name: '',
        speed: '',
        install_date: '',
        service_status: 'pending',
        monthly_usd: '',
        radius_username: '',
        radius_password: '',
        static_ip: '',
        mac: '',
        nas: '',
        vlan: '',
        ont_serial: '',
        router_model: '',
    });
    const name = locale === 'ar' ? customer.name_ar : customer.name_en;
    const area = customer.area ? (locale === 'ar' ? `${customer.area.governorate_ar} · ${customer.area.district_ar} · ${customer.area.name_ar}` : `${customer.area.governorate_en} · ${customer.area.district_en} · ${customer.area.name_en}`) : null;

    const reveal = async (serviceId: number) => {
        const response = await fetch(`/customers/${customer.id}/services/${serviceId}/reveal`, {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.split('XSRF-TOKEN=')[1]?.split(';')[0] ?? ''), Accept: 'application/json' },
        });
        if (!response.ok) return;
        const body = await response.json() as { password: string };
        setRevealed((current) => ({ ...current, [serviceId]: body.password }));
    };

    return (
        <AppShell>
            <Head title={name} />
            <div data-testid="customer-360">
                <p className="text-xs text-faint"><Link href="/customers">{t('customers.title')}</Link> · <span dir="ltr">{customer.account_no}</span></p>
                <div className="mt-2 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-display font-semibold tracking-tight">{name}</h1>
                        <p className="mt-1 text-sm text-muted">{customer.type === 'business' ? customer.company_name : t(`customers.${customer.type}`)}{area ? ` · ${area}` : ''}</p>
                    </div>
                    <Badge tone={customer.status === 'active' ? 'good' : customer.status === 'suspended' ? 'warn' : 'neutral'}>{t(`customers.${customer.status}`)}</Badge>
                </div>
                {incidents.map((incident) => (
                    <p key={incident.id} className="mt-3 rounded-tile bg-bad-bg px-3 py-2 text-sm text-bad ring-1 ring-bad/30" data-testid="incident-banner">
                        {t('incidents.banner', { number: incident.number, title: incident.title })}
                    </p>
                ))}
                {page.props.flash.status ? <p className="mt-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                {page.props.auth.abilities?.tasks_create ? <div className="mt-3"><QuickTask customerId={customer.id} /></div> : null}
                <div className="mt-4 flex flex-wrap gap-2" role="tablist">
                    {tabs.map((item) => (
                        <button key={item} type="button" role="tab" aria-selected={tab === item} data-testid={`tab-${item}`} className={tab === item ? 'rounded-full bg-navy px-3 py-1 text-sm text-white' : 'rounded-full bg-card px-3 py-1 text-sm ring-1 ring-line'} onClick={() => setTab(item)}>
                            {t(`customers.${item}`)}
                        </button>
                    ))}
                </div>
                <div className="mt-4">
                    {tab === 'overview' ? (
                        <section className="card grid gap-3 p-5 md:grid-cols-2">
                            <Fact ltr label={t('customers.phones')} value={customer.phones.join(' · ') || t('customers.hidden')} />
                            {can_dial && customer.phones[0] ? (
                                <div className="md:col-span-2">
                                    <Button type="button" size="sm" onClick={() => router.post('/calls/dial', { phone: customer.phones[0] }, { preserveScroll: true })}>{t('customers.call')}</Button>
                                </div>
                            ) : null}
                            <Fact ltr label={t('customers.whatsapp')} value={customer.whatsapp || '—'} />
                            {whatsapp_live && customer.whatsapp_consent ? (
                                <div>
                                    <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/customers/${customer.id}/whatsapp/renewal`, {}, { preserveScroll: true })}>{t('customers.wa_renewal')}</Button>
                                </div>
                            ) : null}
                            <Fact ltr label={t('customers.email')} value={customer.email || '—'} />
                            <Fact label={t('customers.landmark')} value={[customer.building, customer.floor, customer.landmark].filter(Boolean).join(' · ') || '—'} />
                            <Fact ltr label={t('customers.pin')} value={customer.latitude && customer.longitude ? `${customer.latitude}, ${customer.longitude}` : '—'} />
                            {customer.latitude && customer.longitude ? (
                                <div className="md:col-span-2">
                                    <CoverageMap tileUrl={tiles} height={220} pins={[{ id: customer.id, lat: Number(customer.latitude), lng: Number(customer.longitude), label: locale === 'ar' ? customer.name_ar : customer.name_en }]} />
                                </div>
                            ) : null}
                            <Fact label={t('customers.tags')} value={customer.tags.join(' · ') || '—'} />
                            {orders.length > 0 ? (
                                <div className="md:col-span-2 space-y-1 text-sm">
                                    {orders.map((row) => (
                                        <p key={row.id}><Link href={`/orders/${row.id}`}><span dir="ltr">{row.number}</span></Link> · {t(`orders.type_${row.type}`)} · {t(`orders.status_${row.status}`)}{row.sla_due ? ` · ${row.sla_due}` : ''}</p>
                                    ))}
                                </div>
                            ) : null}
                        </section>
                    ) : null}
                    {tab === 'services' ? (
                        <div className="grid gap-3">
                            {customer.services.length === 0 ? <div className="card"><EmptyState icon={<Receipt className="size-5" />} title={t('customers.services')} body={t('customers.no_billing')} /></div> : null}
                            {customer.services.map((item) => (
                                <article key={item.id} className="card p-5">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <h2 className="text-lg font-semibold">{t(`customers.${item.service_type}`)}</h2>
                                        <Badge>{t(`customers.${item.status}`)}</Badge>
                                    </div>
                                    <p className="mt-1 text-sm text-muted">{item.plan_name} {item.speed ? `· ${item.speed}` : ''} {item.install_date ? `· ${item.install_date}` : ''}</p>
                                    {can_see_money && item.monthly_usd ? <p className="mt-2 text-sm" dir="ltr">${item.monthly_usd}{item.monthly_lbp ? ` · ${item.monthly_lbp.toLocaleString()} LBP` : ''}</p> : <p className="mt-2 text-sm text-muted">{t('customers.hidden')}</p>}
                                    {item.radius_username !== undefined ? (
                                        <div className="mt-3 text-sm" data-testid="radius-panel">
                                            <p className="font-semibold">{t('customers.radius')}</p>
                                            <p dir="ltr">{item.radius_username || '—'} · {item.static_ip || '—'} · {item.mac || '—'} · {item.nas || '—'} · VLAN {item.vlan || '—'}</p>
                                            <div className="mt-3 rounded-tile border border-line bg-card p-3">
                                                <p className="font-semibold">{t('customers.radius_session')}</p>
                                                {radius_live ? (
                                                    <>
                                                        <p className="mt-1" dir="ltr">
                                                            {item.radius_checked_at ? (item.radius_online ? t('customers.radius_online') : t('customers.radius_offline')) : t('customers.radius_unchecked')}
                                                            {item.radius_status ? ` · ${item.radius_status}` : ''}
                                                            {item.radius_framed_ip ? ` · ${item.radius_framed_ip}` : ''}
                                                        </p>
                                                        {item.radius_checked_at ? <p className="text-xs text-muted" dir="ltr">{t('customers.radius_checked', { time: item.radius_checked_at })}</p> : null}
                                                        {can_check_radius && item.radius_username ? (
                                                            <Button type="button" className="mt-2" size="sm" variant="outline" onClick={() => router.post(`/customers/${customer.id}/services/${item.id}/radius`, {}, { preserveScroll: true })}>{t('customers.radius_check')}</Button>
                                                        ) : null}
                                                    </>
                                                ) : <p className="mt-1 text-muted">{t('customers.radius_off')}</p>}
                                            </div>
                                        </div>
                                    ) : null}
                                    {item.ont_serial !== undefined ? <p className="mt-2 text-sm">{t('customers.ont')}: {item.ont_serial || '—'} · {t('customers.router')}: {item.router_model || '—'}</p> : null}
                                    {(item.installed ?? []).length > 0 ? <p className="mt-2 text-sm" dir="ltr">{t('inventory.installed')}: {item.installed?.map((row) => row.serial).join(' · ')}</p> : null}
                                    {can_reveal && item.has_radius_password ? (
                                        revealed[item.id] ? <p className="mt-2 text-sm" dir="ltr">{revealed[item.id]}</p> : <Button type="button" className="mt-3" variant="outline" size="sm" onClick={() => reveal(item.id)}>{t('customers.reveal')}</Button>
                                    ) : null}
                                </article>
                            ))}
                            {can_edit ? (
                                <form className="card grid gap-3 p-5 md:grid-cols-2" onSubmit={(event: FormEvent) => { event.preventDefault(); service.post(`/customers/${customer.id}/services`, { preserveScroll: true }); }}>
                                    <h2 className="md:col-span-2 text-sm font-semibold">{t('customers.add_service')}</h2>
                                    <Field id="stype" label={t('customers.service_type')}>
                                        <select id="stype" className="h-10 w-full rounded-ctl border border-input bg-card px-2" value={service.data.service_type} onChange={(event) => service.setData('service_type', event.target.value)}>
                                            {(['ftth', 'fttb', 'ptmp', 'ptp', 'internal', 'dsl'] as const).map((type) => <option key={type} value={type}>{t(`customers.${type}`)}</option>)}
                                        </select>
                                    </Field>
                                    <Field id="splan" label={t('customers.plan')}>
                                        {plans.length > 0 ? (
                                            <PlanPicker id="splan" plans={plans.filter((plan) => !plan.service_type || plan.service_type === service.data.service_type)} value={service.data.plan_id} arabic={locale === 'ar'} onChange={(plan) => service.setData({
                                                ...service.data,
                                                plan_id: plan ? String(plan.id) : '',
                                                plan_name: plan?.name ?? '',
                                                speed: plan?.down_mbps ? `${plan.down_mbps} / ${plan.up_mbps ?? plan.down_mbps} Mb` : service.data.speed,
                                                monthly_usd: plan?.price ?? service.data.monthly_usd,
                                            })} />
                                        ) : <Input id="splan" value={service.data.plan_name} onChange={(event) => service.setData('plan_name', event.target.value)} />}
                                    </Field>
                                    {plans.length > 0 ? <p className="text-xs text-muted md:col-span-2">{t('catalog.standard')}</p> : null}
                                    <Field id="sspeed" label={t('customers.speed')}><Input id="sspeed" value={service.data.speed} onChange={(event) => service.setData('speed', event.target.value)} /></Field>
                                    <Field id="sprice" label={t('customers.monthly')}><Input id="sprice" dir="ltr" value={service.data.monthly_usd} onChange={(event) => service.setData('monthly_usd', event.target.value)} /></Field>
                                    <Field id="sdate" label={t('customers.install_date')}><Input id="sdate" dir="ltr" type="date" value={service.data.install_date} onChange={(event) => service.setData('install_date', event.target.value)} /></Field>
                                    {can_edit_radius ? (
                                        <>
                                            <Field id="suser" label={t('customers.username')}><Input id="suser" dir="ltr" value={service.data.radius_username} onChange={(event) => service.setData('radius_username', event.target.value)} /></Field>
                                            <Field id="spass" label={t('customers.password')}><Input id="spass" dir="ltr" type="password" value={service.data.radius_password} onChange={(event) => service.setData('radius_password', event.target.value)} autoComplete="new-password" /></Field>
                                            <Field id="sip" label={t('customers.ip')}><Input id="sip" dir="ltr" value={service.data.static_ip} onChange={(event) => service.setData('static_ip', event.target.value)} /></Field>
                                            <Field id="smac" label={t('customers.mac')}><Input id="smac" dir="ltr" value={service.data.mac} onChange={(event) => service.setData('mac', event.target.value)} /></Field>
                                            <Field id="snas" label={t('customers.nas')}><Input id="snas" value={service.data.nas} onChange={(event) => service.setData('nas', event.target.value)} /></Field>
                                            <Field id="svlan" label={t('customers.vlan')}><Input id="svlan" dir="ltr" value={service.data.vlan} onChange={(event) => service.setData('vlan', event.target.value)} /></Field>
                                        </>
                                    ) : null}
                                    <Field id="sont" label={t('customers.ont')}><Input id="sont" dir="ltr" value={service.data.ont_serial} onChange={(event) => service.setData('ont_serial', event.target.value)} /></Field>
                                    <Field id="srouter" label={t('customers.router')}><Input id="srouter" value={service.data.router_model} onChange={(event) => service.setData('router_model', event.target.value)} /></Field>
                                    <div className="md:col-span-2"><Button type="submit">{t('customers.add_service')}</Button></div>
                                </form>
                            ) : null}
                        </div>
                    ) : null}
                    {tab === 'tickets' ? (
                        <section className="card p-5" data-testid="customer-tickets">
                            {tickets.length === 0 ? <EmptyState icon={<Ticket className="size-5" />} title={t('customers.tickets')} body={t('customers.no_tickets')} /> : (
                                <ul className="divide-y divide-line">
                                    {tickets.map((item) => (
                                        <li key={item.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                            <Link href={`/tickets/${item.id}`} className="text-sm font-semibold text-accent-ink">
                                                <span dir="ltr">{item.number}</span> · {item.subject}
                                            </Link>
                                            <span className="text-xs text-muted">{item.priority} · {t(`tickets.status_${item.status}`)}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    ) : null}
                    {tab === 'billing' ? (
                        <section className="card p-5" data-testid="customer-billing">
                            {billing_balance === null ? <p className="text-sm text-muted">{t('billing.hidden')}</p> : (
                                <p className="text-sm" dir="ltr">{t('customers.ledger_balance')}: ${billing_balance}</p>
                            )}
                            {can_see_money ? <a className="mt-2 inline-flex text-sm font-semibold text-accent-ink" href={`/customers/${customer.id}/statement`} data-testid="statement-link">{t('payments.statement')}</a> : null}
                            {billing_invoices.length === 0 ? <EmptyState icon={<Receipt className="size-5" />} title={t('customers.billing')} body={t('customers.billing_empty')} /> : (
                                <ul className="mt-4 divide-y divide-line">
                                    {billing_invoices.map((invoice) => (
                                        <li key={invoice.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                            <a className="text-sm font-semibold text-accent-ink" href={`/invoices/${invoice.id}`}><span dir="ltr">{invoice.number}</span></a>
                                            <span className="text-xs text-muted">{t(`billing.type_${invoice.type}`)} · {t(`billing.status_${invoice.status}`)}</span>
                                            <span className="text-sm" dir="ltr">${invoice.total} · {invoice.lbp} LBP</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    ) : null}
                    {tab === 'files' ? (
                        <section className="card p-5">
                            {files.length === 0 ? <p className="text-sm text-muted">{t('customers.no_files')}</p> : null}
                            <ul className="space-y-2">
                                {files.map((item) => <li key={item.id}><a className="text-sm font-semibold text-accent-ink" href={item.download_url}>{item.name}</a></li>)}
                            </ul>
                            {can_upload ? (
                                <form className="mt-4 flex flex-wrap items-center gap-2" onSubmit={(event) => { event.preventDefault(); file.post(`/customers/${customer.id}/files`, { forceFormData: true, preserveScroll: true }); }}>
                                    <input type="file" aria-label={t('customers.upload')} onChange={(event) => file.setData('file', event.target.files?.[0] ?? null)} />
                                    <Button type="submit">{t('customers.upload')}</Button>
                                </form>
                            ) : null}
                        </section>
                    ) : null}
                    {tab === 'activity' ? (
                        <ul className="card divide-y divide-line">
                            {activity.length === 0 ? <li className="px-4 py-8 text-center text-sm text-muted">{t('customers.no_activity')}</li> : null}
                            {activity.map((item) => (
                                <li key={item.id} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                    <span>{item.event}</span>
                                    <span className="text-xs text-muted" dir="ltr">{item.at}</span>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    {tab === 'notes' ? (
                        <section className="card p-5">
                            {notes.length === 0 ? <p className="text-sm text-muted">{t('customers.no_notes')}</p> : null}
                            <ul className="space-y-3">
                                {notes.map((item) => (
                                    <li key={item.id}>
                                        <p className="text-sm">{item.body}</p>
                                        <p className="text-xs text-muted">{item.author}</p>
                                    </li>
                                ))}
                            </ul>
                            {can_edit ? (
                                <form className="mt-4 grid gap-2" onSubmit={(event) => { event.preventDefault(); note.post(`/customers/${customer.id}/notes`, { preserveScroll: true, onSuccess: () => note.reset() }); }}>
                                    <textarea aria-label={t('customers.notes')} className="min-h-24 rounded-ctl border border-input bg-card p-3 text-sm" value={note.data.body} onChange={(event) => note.setData('body', event.target.value)} />
                                    <Button type="submit">{t('customers.add_note')}</Button>
                                </form>
                            ) : null}
                            {customer.notes ? <p className="mt-4 text-sm text-muted">{customer.notes}</p> : null}
                        </section>
                    ) : null}
                </div>
            </div>
        </AppShell>
    );
}

function Fact({ label, value, ltr }: { label: string; value: string; ltr?: boolean }) {
    return (
        <div>
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-faint">{label}</p>
            <p className="mt-1 text-sm" dir={ltr ? 'ltr' : undefined}>{value}</p>
        </div>
    );
}
