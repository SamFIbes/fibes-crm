import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Tags } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Product = {
    id: number;
    sku: string;
    kind: string;
    service_type: string | null;
    name_en: string;
    name_ar: string;
    offering: string | null;
    is_active: boolean;
    down_mbps: number | null;
    up_mbps: number | null;
    burst_mbps: number | null;
    price: string | null;
    price_lbp: string | null;
    price_note: string | null;
    cost_usd?: string | null;
};
type Price = {
    id: number;
    sku: string | null;
    name_en: string | null;
    name_ar: string | null;
    price: string;
    currency: string;
    valid_from: string | null;
    customer_type: string | null;
    note: string | null;
};
type Promo = {
    id: number;
    code: string;
    name_en: string;
    name_ar: string;
    type: string;
    value: string;
    valid_from: string | null;
    valid_to: string | null;
    stackable: boolean;
    requires_approval: boolean;
};
type Limit = { id: number; role: string; line_percent: string; line_amount: string; total_percent: string; total_amount: string };
type RequestRow = { id: number; product_id: number; price: string; valid_from: string | null; customer_type: string | null; status: string; note: string | null };

const kinds = ['plan', 'addon', 'equipment', 'installation', 'static_ip', 'one_time'] as const;
const promoTypes = ['percent', 'fixed', 'free_months', 'free_install', 'free_equipment'] as const;

export default function CatalogIndex({
    products,
    prices,
    requests,
    promotions,
    limits,
    campaigns,
    can_edit,
    can_price,
    can_request,
    can_promote,
    see_cost,
}: {
    products: Product[];
    prices: Price[];
    requests: RequestRow[];
    promotions: Promo[];
    limits: Limit[];
    campaigns: { id: number; name: string }[];
    can_edit: boolean;
    can_price: boolean;
    can_request: boolean;
    can_promote: boolean;
    see_cost: boolean;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const plans = products.filter((product) => product.kind === 'plan');
    const name = (row: { name_en: string | null; name_ar: string | null }) => (locale === 'ar' ? row.name_ar : row.name_en) || row.name_en;

    return (
        <AppShell>
            <Head title={t('catalog.title')} />
            <div data-testid="catalog-page">
                <PageHeader title={t('catalog.title')} sub={t('catalog.subtitle')} />
                {page.props.flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                <section className="grid gap-3 md:grid-cols-2">
                    {plans.map((plan) => (
                        <article key={plan.id} className="card p-5">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="text-xs text-faint" dir="ltr">{plan.sku}</p>
                                    <h2 className="text-lg font-semibold">{locale === 'ar' ? plan.name_ar : plan.name_en}</h2>
                                    <p className="text-sm text-muted" dir="ltr">{plan.down_mbps}/{plan.up_mbps} Mb{plan.burst_mbps ? ` · ${plan.burst_mbps}` : ''}</p>
                                </div>
                                {plan.price_note ? <Badge tone="warn">{t('catalog.confirm')}</Badge> : null}
                            </div>
                            <p className="mt-3 text-2xl font-semibold" dir="ltr">${plan.price ?? '—'}</p>
                            {plan.price_lbp ? <p className="text-sm text-muted" dir="ltr">{Number(plan.price_lbp).toLocaleString()} LBP</p> : null}
                            {see_cost && plan.cost_usd ? <p className="mt-1 text-xs text-faint" dir="ltr">{t('catalog.cost')} {plan.cost_usd}</p> : null}
                        </article>
                    ))}
                </section>
                {plans.length === 0 ? <div className="card"><EmptyState icon={<Tags className="size-5" />} title={t('catalog.empty')} body={t('catalog.subtitle')} /></div> : null}

                <h2 className="mb-2 mt-8 text-sm font-semibold">{t('catalog.products')}</h2>
                <ul className="card divide-y divide-line">
                    {products.map((product) => (
                        <li key={product.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                            <div>
                                <p className="font-semibold">{locale === 'ar' ? product.name_ar : product.name_en}</p>
                                <p className="text-muted">{t(`catalog.kind_${product.kind}`)} · <span dir="ltr">{product.sku}</span></p>
                            </div>
                            <p dir="ltr">{product.price ? `$${product.price}` : '—'}</p>
                        </li>
                    ))}
                </ul>

                <h2 className="mb-2 mt-8 text-sm font-semibold">{t('catalog.prices')}</h2>
                <ul className="card divide-y divide-line">
                    {prices.map((row) => (
                        <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                            <div>
                                <p className="font-semibold">{name(row)}</p>
                                <p className="text-muted" dir="ltr">{row.valid_from} · {row.customer_type || t('catalog.everyone')}</p>
                            </div>
                            <div className="text-end">
                                <p dir="ltr">${row.price}</p>
                                {row.note ? <p className="text-xs text-faint">{t('catalog.confirm')}</p> : null}
                            </div>
                        </li>
                    ))}
                </ul>

                <h2 className="mb-2 mt-8 text-sm font-semibold">{t('catalog.promotions')}</h2>
                <ul className="card divide-y divide-line">
                    {promotions.map((promo) => (
                        <li key={promo.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                            <div>
                                <p className="font-semibold" dir="ltr">{promo.code}</p>
                                <p className="text-muted">{locale === 'ar' ? promo.name_ar : promo.name_en}</p>
                            </div>
                            <Badge>{t(`catalog.${promo.type}`)} · {promo.value}</Badge>
                        </li>
                    ))}
                </ul>

                <Preview products={products} />

                {can_edit ? <ProductForm /> : null}
                {can_price ? <PriceForm products={products} action="/catalog/prices" label={t('catalog.add_price')} /> : null}
                {can_request ? <PriceForm products={products} action="/catalog/price-requests" label={t('catalog.request_price')} /> : null}
                {can_price ? (
                    <ul className="mt-4 space-y-2">
                        {requests.filter((row) => row.status === 'pending').map((row) => (
                            <li key={row.id} className="card flex flex-wrap items-center justify-between gap-2 p-4 text-sm">
                                <span dir="ltr">${row.price} · {row.valid_from}</span>
                                <Button type="button" size="sm" onClick={() => router.post(`/catalog/price-requests/${row.id}/apply`, {}, { preserveScroll: true })}>{t('catalog.apply')}</Button>
                            </li>
                        ))}
                    </ul>
                ) : null}
                {can_promote ? <PromoForm campaigns={campaigns} /> : null}
                <h2 className="mb-2 mt-8 text-sm font-semibold">{t('catalog.limits')}</h2>
                <ul className="card divide-y divide-line">
                    {limits.map((limit) => (
                        <li key={limit.id} className="px-4 py-3 text-sm">
                            <span className="font-semibold">{limit.role}</span>
                            <span className="ms-2 text-muted" dir="ltr">{t('catalog.line')} {limit.line_percent}% / ${limit.line_amount} · {t('catalog.total')} {limit.total_percent}% / ${limit.total_amount}</span>
                        </li>
                    ))}
                </ul>
                {can_edit ? (
                    <form className="mt-4" onSubmit={(event: FormEvent) => { event.preventDefault(); router.post('/catalog/migrate', {}, { preserveScroll: true }); }}>
                        <Button type="submit" variant="outline">{t('catalog.migrate')}</Button>
                    </form>
                ) : null}
            </div>
        </AppShell>
    );
}

function Preview({ products }: { products: Product[] }) {
    const { t } = useI18n();
    const form = useForm({ code: '', amount: '35.00', product_id: String(products[0]?.id ?? ''), on: '2026-11-01' });

    return (
        <form className="card mt-4 grid gap-3 p-5 md:grid-cols-4" onSubmit={(event: FormEvent) => { event.preventDefault(); form.post('/catalog/promotions/preview', { preserveScroll: true }); }}>
            <Field id="try-code" label={t('catalog.code')} error={form.errors.code}><Input id="try-code" dir="ltr" value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} /></Field>
            <Field id="try-amount" label={t('catalog.price')}><Input id="try-amount" dir="ltr" value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} /></Field>
            <Field id="try-on" label={t('catalog.from')}><Input id="try-on" dir="ltr" type="date" value={form.data.on} onChange={(event) => form.setData('on', event.target.value)} /></Field>
            <div className="flex items-end"><Button type="submit" variant="outline">{t('catalog.try_code')}</Button></div>
        </form>
    );
}

function ProductForm() {
    const { t } = useI18n();
    const form = useForm({
        sku: '', kind: 'plan', service_type: 'ftth', name_en: '', name_ar: '', offering: '',
        down_mbps: '8', up_mbps: '8', burst_mbps: '16', billing_cycle: 'monthly', needs_survey: true, is_public: true,
        price: '', valid_from: '2026-11-01', note: 'To confirm by Sam',
    });

    return (
        <form className="card mt-4 grid gap-3 p-5 md:grid-cols-3" onSubmit={(event: FormEvent) => { event.preventDefault(); form.post('/catalog/products', { preserveScroll: true, onSuccess: () => form.reset('sku', 'name_en', 'name_ar', 'price') }); }}>
            <h2 className="md:col-span-3 text-sm font-semibold">{t('catalog.add_product')}</h2>
            <Field id="sku" label={t('catalog.sku')}><Input id="sku" dir="ltr" value={form.data.sku} onChange={(event) => form.setData('sku', event.target.value)} required /></Field>
            <Field id="pname" label={t('catalog.name_en')}><Input id="pname" value={form.data.name_en} onChange={(event) => form.setData('name_en', event.target.value)} required /></Field>
            <Field id="pname-ar" label={t('catalog.name_ar')}><Input id="pname-ar" value={form.data.name_ar} onChange={(event) => form.setData('name_ar', event.target.value)} required /></Field>
            <Field id="kind" label={t('catalog.kind')}>
                <select id="kind" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.kind} onChange={(event) => form.setData('kind', event.target.value)}>
                    {kinds.map((kind) => <option key={kind} value={kind}>{t(`catalog.kind_${kind}`)}</option>)}
                </select>
            </Field>
            <Field id="cprice" label={t('catalog.price')}><Input id="cprice" dir="ltr" value={form.data.price} onChange={(event) => form.setData('price', event.target.value)} /></Field>
            <Field id="cfrom" label={t('catalog.from')}><Input id="cfrom" dir="ltr" type="date" value={form.data.valid_from} onChange={(event) => form.setData('valid_from', event.target.value)} /></Field>
            <div className="md:col-span-3"><Button type="submit">{t('catalog.add_product')}</Button></div>
        </form>
    );
}

function PriceForm({ products, action, label }: { products: Product[]; action: string; label: string }) {
    const { t, locale } = useI18n();
    const form = useForm({ product_id: String(products[0]?.id ?? ''), price: '', currency: 'USD', valid_from: '2026-11-01', customer_type: '', note: 'To confirm by Sam' });

    return (
        <form className="card mt-4 grid gap-3 p-5 md:grid-cols-3" onSubmit={(event: FormEvent) => { event.preventDefault(); form.post(action, { preserveScroll: true, onSuccess: () => form.reset('price') }); }}>
            <h2 className="md:col-span-3 text-sm font-semibold">{label}</h2>
            <Field id={`${action}-product`} label={t('catalog.products')}>
                <select id={`${action}-product`} className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.product_id} onChange={(event) => form.setData('product_id', event.target.value)}>
                    {products.map((product) => <option key={product.id} value={product.id}>{locale === 'ar' ? product.name_ar : product.name_en}</option>)}
                </select>
            </Field>
            <Field id={`${action}-price`} label={t('catalog.price')}><Input id={`${action}-price`} dir="ltr" value={form.data.price} onChange={(event) => form.setData('price', event.target.value)} required /></Field>
            <Field id={`${action}-from`} label={t('catalog.from')}><Input id={`${action}-from`} dir="ltr" type="date" value={form.data.valid_from} onChange={(event) => form.setData('valid_from', event.target.value)} required /></Field>
            <Field id={`${action}-who`} label={t('catalog.customer_type')}>
                <select id={`${action}-who`} className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.customer_type} onChange={(event) => form.setData('customer_type', event.target.value)}>
                    <option value="">{t('catalog.everyone')}</option>
                    <option value="individual">{t('catalog.individual')}</option>
                    <option value="business">{t('catalog.business')}</option>
                    <option value="reseller">{t('catalog.reseller')}</option>
                </select>
            </Field>
            <div className="flex items-end"><Button type="submit">{label}</Button></div>
        </form>
    );
}

function PromoForm({ campaigns }: { campaigns: { id: number; name: string }[] }) {
    const { t } = useI18n();
    const form = useForm({
        code: '', name_en: '', name_ar: '', type: 'percent', value: '10', valid_from: '2026-11-01', valid_to: '',
        stackable: false, requires_approval: false, campaign_id: '',
    });

    return (
        <form className="card mt-4 grid gap-3 p-5 md:grid-cols-3" onSubmit={(event: FormEvent) => { event.preventDefault(); form.post('/catalog/promotions', { preserveScroll: true, onSuccess: () => form.reset('code', 'name_en', 'name_ar') }); }}>
            <h2 className="md:col-span-3 text-sm font-semibold">{t('catalog.promotions')}</h2>
            <Field id="promo-code" label={t('catalog.code')}><Input id="promo-code" dir="ltr" value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} required /></Field>
            <Field id="promo-en" label={t('catalog.name_en')}><Input id="promo-en" value={form.data.name_en} onChange={(event) => form.setData('name_en', event.target.value)} required /></Field>
            <Field id="promo-ar" label={t('catalog.name_ar')}><Input id="promo-ar" value={form.data.name_ar} onChange={(event) => form.setData('name_ar', event.target.value)} required /></Field>
            <Field id="promo-type" label={t('catalog.type')}>
                <select id="promo-type" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}>
                    {promoTypes.map((type) => <option key={type} value={type}>{t(`catalog.${type}`)}</option>)}
                </select>
            </Field>
            <Field id="promo-value" label={t('catalog.value')}><Input id="promo-value" dir="ltr" value={form.data.value} onChange={(event) => form.setData('value', event.target.value)} required /></Field>
            <Field id="promo-from" label={t('catalog.from')}><Input id="promo-from" dir="ltr" type="date" value={form.data.valid_from} onChange={(event) => form.setData('valid_from', event.target.value)} required /></Field>
            <Field id="promo-until" label={t('catalog.until')}><Input id="promo-until" dir="ltr" type="date" value={form.data.valid_to} onChange={(event) => form.setData('valid_to', event.target.value)} /></Field>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.stackable} onChange={(event) => form.setData('stackable', event.target.checked)} />{t('catalog.stackable')}</label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.requires_approval} onChange={(event) => form.setData('requires_approval', event.target.checked)} />{t('catalog.approval')}</label>
            <Field id="promo-campaign" label={t('leads.campaign')}>
                <select id="promo-campaign" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.campaign_id} onChange={(event) => form.setData('campaign_id', event.target.value)}>
                    <option value="">—</option>
                    {campaigns.map((campaign) => <option key={campaign.id} value={campaign.id}>{campaign.name}</option>)}
                </select>
            </Field>
            <div className="md:col-span-3"><Button type="submit">{t('catalog.save_promo')}</Button></div>
        </form>
    );
}
