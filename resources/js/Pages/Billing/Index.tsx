import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    const credit = useForm({ amount: '' });
    const refund = useForm({ customer_id: '', amount: '' });
    const payment = useForm({ customer_id: '', amount: '', currency: 'USD', method: 'cash', reference: '', password: '', invoice_id: '' });
    const drawer = useForm({ counted: '', password: '' });

    return (
        <AppShell>
            <Head title={t('billing.title')} />
            <div data-testid="billing-page">
                <PageHeader title={t('billing.title')} sub={t('billing.subtitle')} actions={can_issue ? <Button type="button" onClick={() => router.post('/billing/run')}>{t('billing.issue')}</Button> : null} />
                <p className="mb-4 text-sm text-muted">{t('billing.vat', { percent: vat })} {t('billing.to_confirm')}</p>
                {page.props.flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                {balance_only ? <p className="text-sm text-muted">{t('billing.hidden')}</p> : null}
                {invoices.length === 0 ? <div className="card"><EmptyState icon={<Receipt className="size-5" />} title={t('billing.empty')} body={t('billing.subtitle')} /></div> : (
                    <div className="space-y-3">
                        {invoices.map((invoice) => (
                            <article key={invoice.id} className="card p-4" data-testid="invoice-row" data-status={invoice.status}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p className="text-xs text-faint" dir="ltr">{invoice.number}</p>
                                        <h2 className="text-lg font-semibold">{locale === 'ar' ? invoice.customer_ar || invoice.customer : invoice.customer}</h2>
                                        <p className="text-sm text-muted">{t(`billing.type_${invoice.type}`)}</p>
                                        {see_amounts ? <a className="text-sm font-semibold text-accent-ink" href={`/invoices/${invoice.id}`} data-testid="invoice-print">{t('billing.print')}</a> : null}
                                        {see_amounts && invoice.total ? <p className="text-sm" dir="ltr">${invoice.total} · {invoice.lbp} {t('billing.lbp')}</p> : null}
                                    </div>
                                    <Badge tone={invoice.status === 'voided' || invoice.status === 'written_off' ? 'bad' : invoice.status === 'paid' ? 'good' : 'warn'}>{t(`billing.status_${invoice.status}`)}</Badge>
                                </div>
                                {can_issue && invoice.type === 'invoice' && invoice.status === 'issued' ? (
                                    <form className="mt-3 flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); credit.post(`/invoices/${invoice.id}/credit`); }}>
                                        <Field id={`credit-${invoice.id}`} label={t('billing.amount')}>
                                            <Input id={`credit-${invoice.id}`} dir="ltr" value={credit.data.amount} onChange={(event) => credit.setData('amount', event.target.value)} />
                                        </Field>
                                        <Button type="submit" size="sm" variant="outline">{t('billing.credit')}</Button>
                                        <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/invoices/${invoice.id}/void`, { reason: 'Issued in error' })}>{t('billing.void')}</Button>
                                        {can_write_off ? <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/invoices/${invoice.id}/write-off`)}>{t('billing.write_off')}</Button> : null}
                                    </form>
                                ) : null}
                                {invoice.type === 'invoice' && ['issued', 'partially_paid', 'overdue'].includes(invoice.status) ? (
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {whish_live && can_record ? (
                                            invoice.whish_url ? <a className="text-sm font-semibold text-accent-ink" href={invoice.whish_url} target="_blank" rel="noreferrer">{t('payments.whish_open')}</a> : <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/invoices/${invoice.id}/whish`, {}, { preserveScroll: true })}>{t('payments.whish_link')}</Button>
                                        ) : null}
                                        {whatsapp_live && invoice.whatsapp_consent && can_record ? (
                                            <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/invoices/${invoice.id}/whatsapp`, {}, { preserveScroll: true })}>{t('payments.wa_send')}</Button>
                                        ) : null}
                                    </div>
                                ) : null}
                            </article>
                        ))}
                    </div>
                )}
                {payments.length > 0 ? (
                    <div className="mt-6 space-y-3" data-testid="payment-list">
                        {payments.map((row) => (
                            <article key={row.id} className="card p-4" data-testid="payment-row" data-status={row.status}>
                                <p className="text-xs text-faint" dir="ltr">{row.number}</p>
                                <h2 className="text-lg font-semibold">{locale === 'ar' ? row.customer_ar || row.customer : row.customer}</h2>
                                <p className="text-sm text-muted">{t(`payments.method_${row.method}`)} · {t(`payments.status_${row.status}`)}</p>
                                {see_amounts ? <p className="text-sm" dir="ltr">${row.amount} · {row.tender} {row.currency}</p> : null}
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <a className="text-sm font-semibold text-accent-ink" href={`/payments/${row.id}`} data-testid="receipt-link">{t('payments.receipt')}</a>
                                    {row.whatsapp ? <a className="text-sm font-semibold text-accent-ink" href={row.whatsapp}>{t('payments.whatsapp')}</a> : null}
                                    {can_record && row.status === 'pending' ? <Button type="button" size="sm" onClick={() => router.post(`/payments/${row.id}/confirm`, { password: payment.data.password })}>{t('payments.confirm')}</Button> : null}
                                    {can_reverse && row.status === 'confirmed' ? <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/payments/${row.id}/reverse`)}>{t('payments.reverse')}</Button> : null}
                                </div>
                            </article>
                        ))}
                    </div>
                ) : null}
                {(can_record || can_request) ? (
                    <form data-testid="payment-form" className="card mt-6 grid gap-3 p-4 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); payment.post('/payments'); }}>
                        <h2 className="sm:col-span-2 text-sm font-semibold">{t('payments.record')}</h2>
                        <Field id="pay-customer" label={t('payments.customer')}><Input id="pay-customer" dir="ltr" value={payment.data.customer_id} onChange={(event) => payment.setData('customer_id', event.target.value)} /></Field>
                        <Field id="pay-amount" label={t('billing.amount')}><Input id="pay-amount" dir="ltr" value={payment.data.amount} onChange={(event) => payment.setData('amount', event.target.value)} /></Field>
                        <Field id="pay-currency" label={t('payments.currency')}>
                            <select id="pay-currency" className="h-10 w-full rounded-ctl border border-input bg-card px-3 text-sm" value={payment.data.currency} onChange={(event) => payment.setData('currency', event.target.value)}>
                                <option value="USD">USD</option>
                                <option value="LBP">LBP</option>
                            </select>
                        </Field>
                        <Field id="pay-method" label={t('payments.method')}>
                            <select id="pay-method" className="h-10 w-full rounded-ctl border border-input bg-card px-3 text-sm" value={payment.data.method} onChange={(event) => payment.setData('method', event.target.value)}>
                                {['cash', 'whish', 'omt', 'bank_transfer', 'card', 'cheque'].map((method) => <option key={method} value={method}>{t(`payments.method_${method}`)}</option>)}
                            </select>
                        </Field>
                        <Field id="pay-reference" label={t('payments.reference')}><Input id="pay-reference" dir="ltr" value={payment.data.reference} onChange={(event) => payment.setData('reference', event.target.value)} /></Field>
                        <Field id="pay-password" label={t('payments.password')}><Input id="pay-password" type="password" value={payment.data.password} onChange={(event) => payment.setData('password', event.target.value)} /></Field>
                        <Button type="submit">{t('payments.record')}</Button>
                    </form>
                ) : null}
                {cash_session && (can_record || can_request) ? (
                    <form data-testid="cash-session" className="card mt-4 grid gap-3 p-4 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); drawer.post(`/cash-sessions/${cash_session.id}/close`); }}>
                        <p className="sm:col-span-2 text-sm" dir="ltr">{t('payments.expected')}: ${cash_session.expected}</p>
                        <Field id="counted" label={t('payments.counted')}><Input id="counted" dir="ltr" value={drawer.data.counted} onChange={(event) => drawer.setData('counted', event.target.value)} /></Field>
                        <Field id="close-password" label={t('payments.password')}><Input id="close-password" type="password" value={drawer.data.password} onChange={(event) => drawer.setData('password', event.target.value)} /></Field>
                        <Button type="submit">{t('payments.close')}</Button>
                    </form>
                ) : null}
                {can_issue ? (
                    <form className="card mt-6 grid gap-3 p-4 sm:grid-cols-2" onSubmit={(event) => { event.preventDefault(); refund.post('/billing/refunds'); }}>
                        <Field id="refund-customer" label={t('billing.customer')}>
                            <Input id="refund-customer" dir="ltr" value={refund.data.customer_id} onChange={(event) => refund.setData('customer_id', event.target.value)} />
                        </Field>
                        <Field id="refund-amount" label={t('billing.amount')}>
                            <Input id="refund-amount" dir="ltr" value={refund.data.amount} onChange={(event) => refund.setData('amount', event.target.value)} />
                        </Field>
                        <Button type="submit">{t('billing.refund')}</Button>
                    </form>
                ) : null}
            </div>
        </AppShell>
    );
}
