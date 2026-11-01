import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { quoteNeedsApproval } from '@/lib/quotes';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Product = { id: number; sku: string; name_en: string; name_ar: string; kind: string; price: string | null; recurring: boolean };
type Line = { description: string; qty: string; unit_price: string; discount_pct: string; line_total: string; recurring: boolean };
type QuoteRow = {
    id: number;
    number: string | null;
    version: number;
    status: string;
    language: string;
    currency: string;
    total: string;
    rate: string;
    valid_until: string | null;
    customer: string | null;
    approver: string | null;
    printable: boolean;
    lines: Line[];
};
type ContractRow = { id: number; number: string | null; status: string; quote_number: string | null; term_months: number; signer_name: string | null; sha256: string | null; signed: boolean };

const tone: Record<string, 'neutral' | 'warn' | 'good' | 'bad'> = {
    draft: 'neutral',
    pending_approval: 'warn',
    approved: 'good',
    sent: 'good',
    accepted: 'good',
    rejected: 'bad',
    expired: 'bad',
};

export default function QuotesIndex({
    quotes,
    contracts,
    products,
    limit,
    can_create,
    can_contract,
    vat,
    term_months,
    early_fee,
}: {
    quotes: QuoteRow[];
    contracts: ContractRow[];
    products: Product[];
    limit: { percent: string; amount: string } | null;
    can_create: boolean;
    can_contract: boolean;
    vat: string;
    term_months: number;
    early_fee: string;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const form = useForm({
        customer_name: '',
        customer_email: '',
        customer_phone: '',
        language: locale === 'ar' ? 'ar' : 'en',
        currency: 'USD',
        apply_vat: true,
        discount_pct: '0',
        qty: '1',
        product_id: products[0]?.id ?? '',
        promo_code: '',
        submit: false,
    });
    const product = products.find((item) => item.id === Number(form.data.product_id));
    const percent = Number(form.data.discount_pct || 0);
    const amount = Number(product?.price || 0) * Number(form.data.qty || 1) * percent / 100;
    const needs = quoteNeedsApproval({
        percent,
        amount,
        limitPercent: limit ? Number(limit.percent) : null,
        limitAmount: limit ? Number(limit.amount) : null,
        custom: false,
    });

    const save = (send: boolean) => {
        form.transform((data) => ({ ...data, submit: send }));
        form.post('/quotes', { preserveScroll: true });
    };

    return (
        <AppShell>
            <Head title={t('quotes.title')} />
            <div data-testid="quotes-page">
                <PageHeader title={t('quotes.title')} sub={t('quotes.subtitle')} />
                <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm text-muted ring-1 ring-line">{t('quotes.to_confirm')}</p>
                {page.props.flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                {page.props.flash.link ? (
                    <p className="mb-4 break-all rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line" dir="ltr" data-testid="quote-link">
                        {page.props.flash.link}
                    </p>
                ) : null}
                {can_create ? (
                    <form className="card mb-6 grid gap-4 p-4 sm:grid-cols-2" data-testid="quote-form" onSubmit={(event) => { event.preventDefault(); save(true); }}>
                        <Field id="quote-customer" label={t('quotes.name')} error={form.errors.customer_name}>
                            <Input id="quote-customer" value={form.data.customer_name} onChange={(event) => form.setData('customer_name', event.target.value)} />
                        </Field>
                        <Field id="quote-email" label={t('quotes.email')} error={form.errors.customer_email}>
                            <Input id="quote-email" type="email" value={form.data.customer_email} onChange={(event) => form.setData('customer_email', event.target.value)} />
                        </Field>
                        <Field id="quote-phone" label={t('quotes.phone')} error={form.errors.customer_phone}>
                            <Input id="quote-phone" dir="ltr" value={form.data.customer_phone} onChange={(event) => form.setData('customer_phone', event.target.value)} />
                        </Field>
                        <Field id="quote-product" label={t('quotes.product')} error={form.errors.product_id}>
                            <select id="quote-product" className="h-10 w-full rounded-tile border border-line bg-card px-3 text-sm" value={form.data.product_id} onChange={(event) => form.setData('product_id', Number(event.target.value))}>
                                {products.map((item) => (
                                    <option key={item.id} value={item.id}>
                                        {(locale === 'ar' ? item.name_ar : item.name_en)}{item.price ? ` · $${item.price}` : ''}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field id="quote-qty" label={t('quotes.qty')}>
                            <Input id="quote-qty" dir="ltr" value={form.data.qty} onChange={(event) => form.setData('qty', event.target.value)} />
                        </Field>
                        <Field id="quote-discount" label={t('quotes.discount')} error={form.errors.discount_pct} hint={limit ? `${limit.percent}% / $${limit.amount}` : undefined}>
                            <Input id="quote-discount" dir="ltr" value={form.data.discount_pct} onChange={(event) => form.setData('discount_pct', event.target.value)} />
                        </Field>
                        <Field id="quote-currency" label={t('quotes.currency')}>
                            <select id="quote-currency" className="h-10 w-full rounded-tile border border-line bg-card px-3 text-sm" value={form.data.currency} onChange={(event) => form.setData('currency', event.target.value)}>
                                <option value="USD">USD</option>
                                <option value="LBP">LBP</option>
                            </select>
                        </Field>
                        <Field id="quote-language" label={t('quotes.language')}>
                            <select id="quote-language" className="h-10 w-full rounded-tile border border-line bg-card px-3 text-sm" value={form.data.language} onChange={(event) => form.setData('language', event.target.value)}>
                                <option value="en">English</option>
                                <option value="ar">العربية</option>
                            </select>
                        </Field>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                            <input type="checkbox" checked={form.data.apply_vat} onChange={(event) => form.setData('apply_vat', event.target.checked)} />
                            {t('quotes.vat')} ({vat}%)
                            <span className="text-muted">{t('quotes.vat_hint')}</span>
                        </label>
                        {needs ? <p className="sm:col-span-2"><Badge tone="warn">{t('quotes.approval_needed')}</Badge></p> : null}
                        <div className="flex flex-wrap gap-2 sm:col-span-2">
                            <Button type="submit">{t('quotes.submit')}</Button>
                            <Button type="button" variant="outline" onClick={() => save(false)}>{t('quotes.save')}</Button>
                        </div>
                    </form>
                ) : null}
                {quotes.length === 0 ? <div className="card"><EmptyState icon={<FileText className="size-5" />} title={t('quotes.empty')} body={t('quotes.subtitle')} /></div> : (
                    <div className="space-y-3">
                        {quotes.map((quote) => (
                            <article key={quote.id} className="card p-4" data-testid="quote-row" data-status={quote.status}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="text-xs text-faint" dir="ltr">{quote.number} · {t('quotes.version', { version: quote.version })}</p>
                                        <h2 className="text-lg font-semibold">{quote.customer || quote.lines[0]?.description}</h2>
                                        <p className="text-sm text-muted" dir="ltr">{quote.currency} {quote.total}{quote.valid_until ? ` · ${quote.valid_until}` : ''}</p>
                                    </div>
                                    <Badge tone={tone[quote.status] ?? 'neutral'}>{t(`quotes.status_${quote.status}`)}</Badge>
                                </div>
                                {quote.status === 'pending_approval' ? <p className="mt-2 text-sm">{t('quotes.pending_note')}</p> : null}
                                {quote.approver ? <p className="mt-2 text-sm">{t('quotes.approved_by', { name: quote.approver })}</p> : null}
                                <ul className="mt-3 space-y-1 text-sm">
                                    {quote.lines.map((line, index) => (
                                        <li key={index} className="flex justify-between gap-3">
                                            <span>{line.description} · {line.recurring ? t('quotes.recurring') : t('quotes.once')}</span>
                                            <span dir="ltr">{line.line_total}</span>
                                        </li>
                                    ))}
                                </ul>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {quote.printable ? <a className="inline-flex h-8 items-center rounded-tile px-3 text-sm ring-1 ring-line" href={`/quotes/${quote.id}/print`}>{t('quotes.print')}</a> : null}
                                    {can_create && quote.status === 'approved' ? (
                                        <>
                                            <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/quotes/${quote.id}/send`, { channel: 'link' }, { preserveScroll: true })}>{t('quotes.office')}</Button>
                                            <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/quotes/${quote.id}/send`, { channel: 'whatsapp' }, { preserveScroll: true })}>{t('quotes.send_whatsapp')}</Button>
                                            <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/quotes/${quote.id}/send`, { channel: 'email' }, { preserveScroll: true })}>{t('quotes.send_email')}</Button>
                                        </>
                                    ) : null}
                                    {can_create && (quote.status === 'approved' || quote.status === 'sent') ? (
                                        <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/quotes/${quote.id}/revise`, {}, { preserveScroll: true })}>{t('quotes.revise')}</Button>
                                    ) : null}
                                    {can_create && quote.status === 'sent' ? (
                                        <Button type="button" size="sm" onClick={() => router.post(`/quotes/${quote.id}/accept`, {}, { preserveScroll: true })}>{t('quotes.accept')}</Button>
                                    ) : null}
                                    {can_contract && quote.status === 'accepted' ? (
                                        <Button type="button" size="sm" onClick={() => router.post(`/quotes/${quote.id}/contract`, { term_months }, { preserveScroll: true })}>{t('quotes.make_contract')}</Button>
                                    ) : null}
                                </div>
                            </article>
                        ))}
                    </div>
                )}
                <section className="mt-8">
                    <h2 className="mb-2 text-sm font-semibold">{t('quotes.contracts')}</h2>
                    <p className="mb-3 text-sm text-muted">{t('quotes.term_hint')} {t('quotes.fee_hint')} <span dir="ltr">${early_fee}</span></p>
                    {contracts.length === 0 ? <div className="card"><EmptyState icon={<FileText className="size-5" />} title={t('quotes.contracts_empty')} body={t('quotes.subtitle')} /></div> : (
                        <ul className="card divide-y divide-line">
                            {contracts.map((contract) => (
                                <li key={contract.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm" data-testid="contract-row">
                                    <span dir="ltr">{contract.number} · {contract.quote_number} · {contract.term_months}</span>
                                    <span className="flex items-center gap-2">
                                        <Badge tone={contract.signed ? 'good' : 'warn'}>{t(`quotes.status_${contract.status === 'signed' ? 'accepted' : 'draft'}`)}</Badge>
                                        {!contract.signed && can_contract ? <a className="underline" href={`/contracts/${contract.id}/sign`}>{t('quotes.sign')}</a> : null}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppShell>
    );
}
