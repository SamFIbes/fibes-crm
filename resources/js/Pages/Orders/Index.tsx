import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Package } from 'lucide-react';
import { FormEvent } from 'react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { needsSignOff } from '@/lib/orders';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type OrderRow = { id: number; number: string | null; type: string; status: string; customer: string | null; customer_ar: string | null; total: string; currency: string; sla_due: string | null };
type Purchase = { id: number; number: string | null; status: string; erp_ref: string | null; total: string | null; lines: { id: number; item: string | null; qty: string; received: string; cost: string | null }[] };
type Item = { id: number; sku: string; name_en: string; name_ar: string; cost: string | null };

const tone: Record<string, 'neutral' | 'warn' | 'good' | 'bad'> = {
    draft: 'neutral', pending_approval: 'warn', submitted: 'neutral', credit_check: 'warn', ready_to_schedule: 'warn',
    scheduled: 'good', in_progress: 'good', installed: 'good', activated: 'good', completed: 'good', on_hold: 'warn', cancelled: 'bad', failed_install: 'bad',
    approved: 'good', sent: 'good', partially_received: 'warn', received: 'good',
};

export default function OrdersIndex({
    orders,
    purchases,
    suppliers,
    items,
    can_create,
    can_purchase,
    can_receive,
    see_cost,
}: {
    orders: OrderRow[];
    purchases: Purchase[];
    suppliers: { id: number; name: string }[];
    items: Item[];
    locations: { id: number; name: string; kind: string }[];
    can_create: boolean;
    can_purchase: boolean;
    can_receive: boolean;
    see_cost: boolean;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const form = useForm({ customer_id: '', type: 'termination', unit_price: '', notes: '' });
    const purchase = useForm<{ supplier_id: string | number; erp_ref: string; lines: { item_id: string | number; qty: string; unit_cost: string }[] }>({
        supplier_id: suppliers[0]?.id ?? '',
        erp_ref: '',
        lines: [{ item_id: items[0]?.id ?? '', qty: '1', unit_cost: see_cost ? (items[0]?.cost ?? '0') : '0' }],
    });
    const signOff = needsSignOff(form.data.type, form.data.unit_price !== '');

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.post('/orders');
    };

    return (
        <AppShell>
            <Head title={t('orders.title')} />
            <div data-testid="orders-page">
                <PageHeader title={t('orders.title')} sub={t('orders.subtitle')} />
                <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm text-muted ring-1 ring-line">{t('orders.to_confirm')}</p>
                {page.props.flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                {can_create ? (
                    <form className="card mb-6 grid gap-4 p-4 sm:grid-cols-2" data-testid="order-form" onSubmit={save}>
                        <Field id="order-customer" label={t('orders.customer')} error={form.errors.customer_id}>
                            <Input id="order-customer" dir="ltr" value={form.data.customer_id} onChange={(event) => form.setData('customer_id', event.target.value)} />
                        </Field>
                        <Field id="order-type" label={t('orders.type')}>
                            <select id="order-type" className="h-10 w-full rounded-tile border border-line bg-card px-3 text-sm" value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}>
                                {['upgrade', 'downgrade', 'relocation', 'addon', 'termination', 'reactivation'].map((type) => <option key={type} value={type}>{t(`orders.type_${type}`)}</option>)}
                            </select>
                        </Field>
                        <Field id="order-price" label={t('orders.price')} error={form.errors.unit_price}>
                            <Input id="order-price" dir="ltr" value={form.data.unit_price} onChange={(event) => form.setData('unit_price', event.target.value)} />
                        </Field>
                        <Field id="order-notes" label={t('orders.reason')}>
                            <Input id="order-notes" value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} />
                        </Field>
                        {signOff ? <p className="sm:col-span-2"><Badge tone="warn">{t('orders.pending_note')}</Badge></p> : null}
                        <Button type="submit">{t('orders.new')}</Button>
                    </form>
                ) : null}
                {orders.length === 0 ? <div className="card"><EmptyState icon={<Package className="size-5" />} title={t('orders.empty')} body={t('orders.subtitle')} /></div> : (
                    <div className="space-y-3">
                        {orders.map((order) => (
                            <article key={order.id} className="card p-4" data-testid="order-row" data-status={order.status}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="text-xs text-faint" dir="ltr">{order.number}</p>
                                        <h2 className="text-lg font-semibold">{locale === 'ar' ? order.customer_ar || order.customer : order.customer}</h2>
                                        <p className="text-sm text-muted">{t(`orders.type_${order.type}`)} · <span dir="ltr">{order.currency} {order.total}</span>{order.sla_due ? ` · ${t('orders.sla')} ${order.sla_due}` : ''}</p>
                                    </div>
                                    <Badge tone={tone[order.status] ?? 'neutral'}>{t(`orders.status_${order.status}`)}</Badge>
                                </div>
                                <Link className="mt-3 inline-flex text-sm underline" href={`/orders/${order.id}`}>{t('orders.open')}</Link>
                            </article>
                        ))}
                    </div>
                )}
                {can_purchase || can_receive ? (
                    <section className="mt-8" data-testid="purchase-orders">
                        <h2 className="mb-2 text-lg font-semibold">{t('purchasing.title')}</h2>
                        <p className="mb-3 text-sm text-muted">{t('purchasing.to_confirm')}</p>
                        {can_purchase ? (
                            <form className="card mb-4 grid gap-3 p-4 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); purchase.post('/purchasing'); }}>
                                <Field id="po-supplier" label={t('purchasing.supplier')}>
                                    <select id="po-supplier" className="h-10 w-full rounded-tile border border-line bg-card px-3 text-sm" value={purchase.data.supplier_id} onChange={(event) => purchase.setData('supplier_id', event.target.value)}>
                                        {suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}
                                    </select>
                                </Field>
                                <Field id="po-erp" label={t('purchasing.erp')}>
                                    <Input id="po-erp" dir="ltr" value={purchase.data.erp_ref} onChange={(event) => purchase.setData('erp_ref', event.target.value)} />
                                </Field>
                                <Field id="po-item" label={t('purchasing.item')}>
                                    <select id="po-item" className="h-10 w-full rounded-tile border border-line bg-card px-3 text-sm" value={purchase.data.lines[0].item_id} onChange={(event) => purchase.setData('lines', [{ ...purchase.data.lines[0], item_id: Number(event.target.value) }])}>
                                        {items.map((item) => <option key={item.id} value={item.id}>{locale === 'ar' ? item.name_ar : item.name_en}</option>)}
                                    </select>
                                </Field>
                                <Field id="po-qty" label={t('purchasing.qty')}>
                                    <Input id="po-qty" dir="ltr" value={purchase.data.lines[0].qty} onChange={(event) => purchase.setData('lines', [{ ...purchase.data.lines[0], qty: event.target.value }])} />
                                </Field>
                                {see_cost ? (
                                    <Field id="po-cost" label={t('purchasing.cost')}>
                                        <Input id="po-cost" dir="ltr" value={purchase.data.lines[0].unit_cost} onChange={(event) => purchase.setData('lines', [{ ...purchase.data.lines[0], unit_cost: event.target.value }])} />
                                    </Field>
                                ) : <p className="text-sm text-muted">{t('purchasing.hidden_cost')}</p>}
                                <Button type="submit">{t('purchasing.submit')}</Button>
                            </form>
                        ) : null}
                        {purchases.length === 0 ? <div className="card"><EmptyState icon={<Package className="size-5" />} title={t('purchasing.empty')} body={t('purchasing.to_confirm')} /></div> : (
                            <ul className="card divide-y divide-line">
                                {purchases.map((po) => (
                                    <li key={po.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm" data-testid="po-row">
                                        <span dir="ltr">{po.number}{po.erp_ref ? ` · ${po.erp_ref}` : ''}{po.total ? ` · $${po.total}` : ''}</span>
                                        <span className="flex items-center gap-2">
                                            <Badge tone={tone[po.status] ?? 'neutral'}>{t(`purchasing.status_${po.status}`)}</Badge>
                                            {can_purchase && po.status === 'draft' ? <Button type="button" size="sm" variant="outline" onClick={() => purchase.post(`/purchasing/${po.id}/submit`)}>{t('purchasing.submit')}</Button> : null}
                                            {can_purchase && po.status === 'approved' ? <Button type="button" size="sm" variant="outline" onClick={() => purchase.post(`/purchasing/${po.id}/send`)}>{t('purchasing.send')}</Button> : null}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <a className="mt-3 inline-flex text-sm underline" href="/purchasing/export">{t('purchasing.export')}</a>
                    </section>
                ) : null}
            </div>
        </AppShell>
    );
}
