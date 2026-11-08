import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Drawer } from '@/components/ui/drawer';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Row = {
    id: number;
    number: string | null;
    type: string;
    status: string;
    customer: string | null;
    customer_ar: string | null;
    total: string | null;
    lbp: string | null;
    tax: string | null;
    currency?: string | null;
    exchange_rate?: string | null;
    customer_id: number;
    whish_url?: string | null;
    whatsapp_consent?: boolean;
};

type PaymentRow = {
    id: number;
    number: string | null;
    status: string;
    method: string;
    customer: string | null;
    customer_ar: string | null;
    amount: string;
    tender: string;
    currency: string;
    whatsapp: string | null;
};

type Match = { id: number; name_en: string; name_ar: string; account_no: string };

export default function BillingIndex({
    invoices,
    payments = [],
    can_issue,
    can_write_off,
    can_record = false,
    can_request = false,
    can_reverse = false,
    see_amounts,
    balance_only,
    vat,
    cash_session = null,
    whish_live = false,
    whatsapp_live = false,
}: {
    invoices: Row[];
    payments?: PaymentRow[];
    can_issue: boolean;
    can_write_off: boolean;
    can_record?: boolean;
    can_request?: boolean;
    can_reverse?: boolean;
    see_amounts: boolean;
    balance_only: boolean;
    vat: string;
    cash_session?: { id: number; kind: string; status: string; expected: string; counted: string | null } | null;
    whish_live?: boolean;
    whatsapp_live?: boolean;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const [desk, setDesk] = useState(false);
    const [confirmRun, setConfirmRun] = useState(false);
    const [confirmVoid, setConfirmVoid] = useState(false);
    const credit = useForm({ amount: '', invoice_id: '' });
    const refund = useForm({ customer_id: '', amount: '', confirm: false });
    const payment = useForm({ customer_id: '', amount: '', currency: 'USD', method: 'cash', reference: '', password: '', invoice_id: '' });
    const drawer = useForm({ counted: '', password: '' });
    const voidForm = useForm({ reason: '', invoice_id: '' });
    const [matches, setMatches] = useState<Match[]>([]);
    const [picked, setPicked] = useState<Match | null>(null);
    const canAct = can_issue || can_record || can_request || can_write_off || can_reverse;

    const lookup = async (value: string) => {
        if (value.trim().length < 2) {
            setMatches([]);
            return;
        }
        const response = await fetch(`/billing/lookup?q=${encodeURIComponent(value)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        setMatches(response.ok ? await response.json() : []);
    };

    const choose = (match: Match) => {
        setPicked(match);
        setMatches([]);
        payment.setData('customer_id', String(match.id));
        refund.setData('customer_id', String(match.id));
    };

    return (
        <AppShell>
            <Head title={t('billing.title')} />
            <div data-testid="billing-page">
                <PageHeader
                    title={t('billing.title')}
                    sub={t('billing.review_sub')}
                    actions={canAct ? <Button type="button" onClick={() => setDesk(true)}>{t('billing.open_desk')}</Button> : null}
                />
                <p className="mb-4 text-sm text-muted">{t('billing.vat', { percent: vat })} {t('billing.to_confirm')}</p>
                {page.props.flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                {balance_only ? <p className="text-sm text-muted">{t('billing.hidden')}</p> : null}
                <section aria-labelledby="invoice-review">
                    <h2 id="invoice-review" className="mb-2 text-sm font-semibold">{t('billing.review')}</h2>
                    {invoices.length === 0 ? <div className="card"><EmptyState icon={<Receipt className="size-5" />} title={t('billing.empty')} body={t('billing.subtitle')} /></div> : (
                        <div className="space-y-3">
                            {invoices.map((invoice) => (
                                <article key={invoice.id} className="card p-4" data-testid="invoice-row" data-status={invoice.status}>
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="text-xs text-muted" dir="ltr">{invoice.number}</p>
                                            <h3 className="text-lg font-semibold">{locale === 'ar' ? invoice.customer_ar || invoice.customer : invoice.customer}</h3>
                                            <p className="text-sm text-muted">{t(`billing.type_${invoice.type}`)} · {invoice.currency || 'USD'}</p>
                                            {see_amounts ? <a className="inline-flex min-h-11 items-center text-sm font-semibold text-accent-ink" href={`/invoices/${invoice.id}`} data-testid="invoice-print">{t('billing.print')}</a> : null}
                                            {see_amounts && invoice.total ? <p className="text-sm" dir="ltr">${invoice.total} · {invoice.lbp} {t('billing.lbp')}</p> : null}
                                            {see_amounts ? <p className="text-sm text-muted" dir="ltr">{invoice.exchange_rate ? t('billing.rate', { rate: invoice.exchange_rate }) : t('billing.rate_missing')}</p> : null}
                                            {invoice.whish_url ? <a className="inline-flex min-h-11 items-center text-sm font-semibold text-accent-ink" href={invoice.whish_url} target="_blank" rel="noreferrer">{t('payments.whish_open')}</a> : null}
                                        </div>
                                        <Badge tone={invoice.status === 'voided' || invoice.status === 'written_off' ? 'bad' : invoice.status === 'paid' ? 'good' : 'warn'}>{t(`billing.status_${invoice.status}`)}</Badge>
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
                <section className="mt-6" aria-labelledby="payment-review">
                    <h2 id="payment-review" className="mb-2 text-sm font-semibold">{t('billing.payment_evidence')}</h2>
                    {payments.length === 0 ? <p className="text-sm text-muted">{t('billing.no_payments')}</p> : (
                        <div className="space-y-3" data-testid="payment-list">
                            {payments.map((row) => (
                                <article key={row.id} className="card p-4" data-testid="payment-row" data-status={row.status}>
                                    <p className="text-xs text-muted" dir="ltr">{row.number}</p>
                                    <h3 className="text-lg font-semibold">{locale === 'ar' ? row.customer_ar || row.customer : row.customer}</h3>
                                    <p className="text-sm text-muted">{t(`payments.method_${row.method}`)} · {t(`payments.status_${row.status}`)} · {row.currency}</p>
                                    {see_amounts ? <p className="text-sm" dir="ltr">${row.amount} · {row.tender} {row.currency}</p> : null}
                                    <a className="inline-flex min-h-11 items-center text-sm font-semibold text-accent-ink" href={`/payments/${row.id}`} data-testid="receipt-link">{t('payments.receipt')}</a>
                                    {row.whatsapp ? <a className="ms-3 inline-flex min-h-11 items-center text-sm font-semibold text-accent-ink" href={row.whatsapp}>{t('payments.whatsapp')}</a> : null}
                                </article>
                            ))}
                        </div>
                    )}
                </section>
                <Drawer open={desk} onOpenChange={setDesk} title={t('billing.open_desk')} description={t('billing.desk_help')}>
                    <div className="grid gap-4 p-6">
                        {can_issue ? (
                            <form className="grid gap-2" onSubmit={(event) => { event.preventDefault(); if (confirmRun) router.post('/billing/run'); }}>
                                <h3 className="text-sm font-semibold">{t('billing.issue')}</h3>
                                <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={confirmRun} onChange={(event) => setConfirmRun(event.target.checked)} />{t('billing.confirm_run')}</label>
                                <Button type="submit" disabled={!confirmRun}>{t('billing.issue')}</Button>
                            </form>
                        ) : null}
                        {(can_record || can_request) ? (
                            <form data-testid="payment-form" className="grid gap-3" onSubmit={(event) => { event.preventDefault(); payment.post('/payments'); }}>
                                <h3 className="text-sm font-semibold">{t('payments.record')}</h3>
                                <Field id="pay-lookup" label={t('billing.find_subscriber')}>
                                    <Input id="pay-lookup" onChange={(event) => lookup(event.target.value)} />
                                </Field>
                                {matches.length > 0 ? (
                                    <ul className="rounded-tile border border-line">
                                        {matches.map((match) => (
                                            <li key={match.id}><button type="button" className="min-h-11 w-full px-3 text-start text-sm" onClick={() => choose(match)}>{locale === 'ar' ? match.name_ar : match.name_en} · <span dir="ltr">{match.account_no}</span></button></li>
                                        ))}
                                    </ul>
                                ) : null}
                                {picked ? <p className="text-sm font-semibold">{locale === 'ar' ? picked.name_ar : picked.name_en}</p> : null}
                                <Field id="pay-customer" label={t('payments.customer')}><Input id="pay-customer" dir="ltr" value={payment.data.customer_id} onChange={(event) => payment.setData('customer_id', event.target.value)} /></Field>
                                <Field id="pay-amount" label={t('billing.amount')}><Input id="pay-amount" dir="ltr" value={payment.data.amount} onChange={(event) => payment.setData('amount', event.target.value)} /></Field>
                                <Field id="pay-currency" label={t('payments.currency')}>
                                    <select id="pay-currency" className="h-11 w-full rounded-ctl border border-input bg-card px-3 text-sm" value={payment.data.currency} onChange={(event) => payment.setData('currency', event.target.value)}>
                                        <option value="USD">USD</option>
                                        <option value="LBP">LBP</option>
                                    </select>
                                </Field>
                                <Field id="pay-method" label={t('payments.method')}>
                                    <select id="pay-method" className="h-11 w-full rounded-ctl border border-input bg-card px-3 text-sm" value={payment.data.method} onChange={(event) => payment.setData('method', event.target.value)}>
                                        {['cash', 'whish', 'omt', 'bank_transfer', 'card', 'cheque'].map((method) => <option key={method} value={method}>{t(`payments.method_${method}`)}</option>)}
                                    </select>
                                </Field>
                                <Field id="pay-reference" label={t('payments.reference')}><Input id="pay-reference" dir="ltr" value={payment.data.reference} onChange={(event) => payment.setData('reference', event.target.value)} /></Field>
                                <Field id="pay-password" label={t('payments.password')}><Input id="pay-password" type="password" value={payment.data.password} onChange={(event) => payment.setData('password', event.target.value)} /></Field>
                                <Button type="submit">{t('payments.record')}</Button>
                            </form>
                        ) : null}
                        {can_issue ? (
                            <form className="grid gap-3" onSubmit={(event: FormEvent) => { event.preventDefault(); credit.post(`/invoices/${credit.data.invoice_id}/credit`); }}>
                                <h3 className="text-sm font-semibold">{t('billing.credit')}</h3>
                                <Field id="credit-invoice" label={t('billing.number')}>
                                    <select id="credit-invoice" className="h-11 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={credit.data.invoice_id} onChange={(event) => credit.setData('invoice_id', event.target.value)}>
                                        <option value="">{t('filters.any')}</option>
                                        {invoices.filter((invoice) => invoice.type === 'invoice' && invoice.status === 'issued').map((invoice) => <option key={invoice.id} value={invoice.id}>{invoice.number}</option>)}
                                    </select>
                                </Field>
                                <Field id="credit-amount" label={t('billing.amount')}><Input id="credit-amount" dir="ltr" value={credit.data.amount} onChange={(event) => credit.setData('amount', event.target.value)} /></Field>
                                <Button type="submit" variant="outline" disabled={!credit.data.invoice_id}>{t('billing.credit')}</Button>
                            </form>
                        ) : null}
                        {can_issue ? (
                            <form className="grid gap-3" onSubmit={(event) => { event.preventDefault(); if (!confirmVoid) return; voidForm.transform((data) => ({ reason: data.reason })); voidForm.post(`/invoices/${voidForm.data.invoice_id}/void`); }}>
                                <h3 className="text-sm font-semibold">{t('billing.void')}</h3>
                                <Field id="void-invoice" label={t('billing.number')}>
                                    <select id="void-invoice" className="h-11 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={voidForm.data.invoice_id} onChange={(event) => voidForm.setData('invoice_id', event.target.value)}>
                                        <option value="">{t('filters.any')}</option>
                                        {invoices.filter((invoice) => invoice.type === 'invoice' && invoice.status === 'issued').map((invoice) => <option key={invoice.id} value={invoice.id}>{invoice.number}</option>)}
                                    </select>
                                </Field>
                                <Field id="void-reason" label={t('billing.reason')}><Input id="void-reason" value={voidForm.data.reason} onChange={(event) => voidForm.setData('reason', event.target.value)} /></Field>
                                <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={confirmVoid} onChange={(event) => setConfirmVoid(event.target.checked)} />{t('billing.confirm_void')}</label>
                                <Button type="submit" variant="outline" disabled={!confirmVoid || !voidForm.data.invoice_id || voidForm.data.reason.trim() === ''}>{t('billing.void')}</Button>
                            </form>
                        ) : null}
                        {can_write_off ? (
                            <form className="grid gap-2" onSubmit={(event) => { event.preventDefault(); const id = (event.currentTarget.elements.namedItem('writeoff') as HTMLSelectElement).value; if (id) router.post(`/invoices/${id}/write-off`); }}>
                                <h3 className="text-sm font-semibold">{t('billing.write_off')}</h3>
                                <select name="writeoff" className="h-11 w-full rounded-ctl border border-input bg-card px-2 text-sm" defaultValue="">
                                    <option value="">{t('filters.any')}</option>
                                    {invoices.filter((invoice) => invoice.type === 'invoice' && invoice.status === 'issued').map((invoice) => <option key={invoice.id} value={invoice.id}>{invoice.number}</option>)}
                                </select>
                                <Button type="submit" variant="outline">{t('billing.write_off')}</Button>
                            </form>
                        ) : null}
                        {payments.some((row) => (can_record && row.status === 'pending') || (can_reverse && row.status === 'confirmed')) ? (
                            <div className="grid gap-2">
                                <h3 className="text-sm font-semibold">{t('billing.payment_actions')}</h3>
                                {payments.map((row) => (
                                    <div key={row.id} className="flex flex-wrap items-center gap-2 text-sm">
                                        <span dir="ltr">{row.number}</span>
                                        {can_record && row.status === 'pending' ? <Button type="button" size="sm" onClick={() => router.post(`/payments/${row.id}/confirm`, { password: payment.data.password })}>{t('payments.confirm')}</Button> : null}
                                        {can_reverse && row.status === 'confirmed' ? <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/payments/${row.id}/reverse`)}>{t('payments.reverse')}</Button> : null}
                                    </div>
                                ))}
                            </div>
                        ) : null}
                        {invoices.some((invoice) => invoice.type === 'invoice' && ['issued', 'partially_paid', 'overdue'].includes(invoice.status) && ((whish_live && can_record && !invoice.whish_url) || (whatsapp_live && invoice.whatsapp_consent && can_record))) ? (
                            <div className="grid gap-2">
                                {invoices.filter((invoice) => invoice.type === 'invoice' && ['issued', 'partially_paid', 'overdue'].includes(invoice.status)).map((invoice) => (
                                    <div key={invoice.id} className="flex flex-wrap gap-2">
                                        <span className="text-sm" dir="ltr">{invoice.number}</span>
                                        {whish_live && can_record && !invoice.whish_url ? <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/invoices/${invoice.id}/whish`, {}, { preserveScroll: true })}>{t('payments.whish_link')}</Button> : null}
                                        {whatsapp_live && invoice.whatsapp_consent && can_record ? <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/invoices/${invoice.id}/whatsapp`, {}, { preserveScroll: true })}>{t('payments.wa_send')}</Button> : null}
                                    </div>
                                ))}
                            </div>
                        ) : null}
                        {cash_session && (can_record || can_request) ? (
                            <form data-testid="cash-session" className="grid gap-3" onSubmit={(event) => { event.preventDefault(); drawer.post(`/cash-sessions/${cash_session.id}/close`); }}>
                                <p className="text-sm" dir="ltr">{t('payments.expected')}: ${cash_session.expected}</p>
                                <Field id="counted" label={t('payments.counted')}><Input id="counted" dir="ltr" value={drawer.data.counted} onChange={(event) => drawer.setData('counted', event.target.value)} /></Field>
                                <Field id="close-password" label={t('payments.password')}><Input id="close-password" type="password" value={drawer.data.password} onChange={(event) => drawer.setData('password', event.target.value)} /></Field>
                                <Button type="submit">{t('payments.close')}</Button>
                            </form>
                        ) : null}
                        {can_issue ? (
                            <form className="grid gap-3" onSubmit={(event) => { event.preventDefault(); if (refund.data.confirm) refund.post('/billing/refunds'); }}>
                                <h3 className="text-sm font-semibold">{t('billing.refund')}</h3>
                                <Field id="refund-customer" label={t('billing.customer')}>
                                    <Input id="refund-customer" dir="ltr" value={refund.data.customer_id} onChange={(event) => refund.setData('customer_id', event.target.value)} />
                                </Field>
                                <Field id="refund-amount" label={t('billing.amount')}>
                                    <Input id="refund-amount" dir="ltr" value={refund.data.amount} onChange={(event) => refund.setData('amount', event.target.value)} />
                                </Field>
                                <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={refund.data.confirm} onChange={(event) => refund.setData('confirm', event.target.checked)} />{t('billing.confirm_refund')}</label>
                                <Button type="submit" disabled={!refund.data.confirm}>{t('billing.refund')}</Button>
                            </form>
                        ) : null}
                    </div>
                </Drawer>
            </div>
        </AppShell>
    );
}
