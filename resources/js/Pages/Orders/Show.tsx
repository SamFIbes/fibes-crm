import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/form/Field';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Step = { key: string; done: boolean; current: boolean };
type Line = { description: string; qty: string; total: string; recurring: boolean };
type JobRow = { id: number; number: string; type: string; status: string };

export default function OrderShow({
    order,
    can_move,
    can_activate,
    can_reserve,
}: {
    order: {
        id: number;
        number: string | null;
        type: string;
        status: string;
        customer: string | null;
        customer_ar: string | null;
        total: string;
        currency: string;
        sla_due: string | null;
        return_charge: string;
        notes: string | null;
        whatsapp: string | null;
        consent: boolean;
        next: string[];
        steps: Step[];
        lines: Line[];
        jobs: JobRow[];
    };
    can_move: boolean;
    can_activate: boolean;
    can_reserve: boolean;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const form = useForm({ to: order.next[0] ?? '', reason: '', radius_username: '', vlan: '', static_ip: '', mac: '', billing_confirmed: false });
    const name = locale === 'ar' ? order.customer_ar || order.customer : order.customer;

    return (
        <AppShell>
            <Head title={order.number ?? t('orders.title')} />
            <div data-testid="order-tracker">
                <div className="card px-5 py-4">
                <p className="text-sm text-muted"><Link href="/orders">{t('orders.title')}</Link></p>
                <div className="mt-2 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-3xl font-semibold" dir="ltr">{order.number}</h1>
                        <p className="mt-1 text-muted">{name} · {t(`orders.type_${order.type}`)}{order.sla_due ? ` · ${t('orders.sla')} ${order.sla_due}` : ''}</p>
                    </div>
                    <Badge tone={order.status === 'cancelled' || order.status === 'failed_install' ? 'bad' : order.status === 'pending_approval' ? 'warn' : 'good'}>{t(`orders.status_${order.status}`)}</Badge>
                </div>
                {page.props.flash.status ? <p className="mt-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                {page.props.flash.link ? <p className="mt-3 break-all text-sm" dir="ltr" data-testid="order-whatsapp">{page.props.flash.link}</p> : null}
                {!order.consent ? <p className="mt-3 text-sm text-muted">{t('orders.consent_off')}</p> : null}
                {order.whatsapp && !page.props.flash.link ? <a className="mt-2 inline-flex text-sm underline" dir="ltr" href={order.whatsapp}>{t('orders.whatsapp')}</a> : null}
                <ol className="mt-5 grid gap-2 sm:grid-cols-2 lg:grid-cols-4" data-testid="order-steps">
                    {order.steps.map((step) => (
                        <li key={step.key} className={step.current ? 'rail-active rounded-tile px-3 py-2 text-sm font-semibold' : step.done ? 'rounded-tile border border-mint/40 bg-[var(--glass)] px-3 py-2 text-sm' : 'rounded-tile border border-[var(--glass-border)] bg-[var(--glass)] px-3 py-2 text-sm text-muted'}>
                            {t(`orders.status_${step.key}`)}
                        </li>
                    ))}
                </ol>
                <ul className="mt-4 space-y-1 text-sm">
                    {order.lines.map((line, index) => (
                        <li key={index} className="flex justify-between gap-3"><span>{line.description}</span><span dir="ltr">{line.total}</span></li>
                    ))}
                </ul>
                {Number(order.return_charge) > 0 ? <p className="mt-2 text-sm">{t('orders.return_fee')} <span dir="ltr">${order.return_charge}</span></p> : null}
                <div className="mt-4 space-y-2">
                    {order.jobs.map((job) => (
                        <p key={job.id} className="text-sm" data-testid="order-job"><span dir="ltr">{job.number}</span> · {job.type} · {job.status}</p>
                    ))}
                </div>
                </div>
                {can_move && order.next.length > 0 ? (
                    <form className="card mt-5 grid gap-3 p-4" onSubmit={(event) => { event.preventDefault(); form.post(`/orders/${order.id}/transition`); }}>
                        <Field id="order-to" label={t('orders.status')}>
                            <select id="order-to" className="h-10 w-full rounded-tile border border-line bg-card px-3 text-sm" value={form.data.to} onChange={(event) => form.setData('to', event.target.value)}>
                                {order.next.map((step) => <option key={step} value={step}>{t(`orders.status_${step}`)}</option>)}
                            </select>
                        </Field>
                        <Field id="order-reason" label={t('orders.reason')} error={form.errors.reason}>
                            <Input id="order-reason" value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                        </Field>
                        {can_activate && order.next.includes('activated') ? (
                            <>
                                <Field id="radius" label={t('orders.username')} error={form.errors.radius_username}>
                                    <Input id="radius" dir="ltr" value={form.data.radius_username} onChange={(event) => form.setData('radius_username', event.target.value)} />
                                </Field>
                                <Field id="vlan" label={t('orders.vlan')}><Input id="vlan" dir="ltr" value={form.data.vlan} onChange={(event) => form.setData('vlan', event.target.value)} /></Field>
                                <Field id="ip" label={t('orders.ip')}><Input id="ip" dir="ltr" value={form.data.static_ip} onChange={(event) => form.setData('static_ip', event.target.value)} /></Field>
                                <Field id="mac" label={t('orders.mac')}><Input id="mac" dir="ltr" value={form.data.mac} onChange={(event) => form.setData('mac', event.target.value)} /></Field>
                            </>
                        ) : null}
                        {order.next.includes('completed') ? (
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={form.data.billing_confirmed} onChange={(event) => form.setData('billing_confirmed', event.target.checked)} />
                                {t('orders.billing')}
                            </label>
                        ) : null}
                        <p className="text-xs text-muted">{t('orders.billing_hint')}</p>
                        <Button type="submit">{t('orders.saved')}</Button>
                    </form>
                ) : null}
                {can_reserve ? <p className="mt-3 text-sm text-muted">{t('orders.reserve')}</p> : null}
                <button type="button" className="mt-4 text-sm underline" onClick={() => router.visit('/dispatch')}>{t('orders.job')}</button>
            </div>
        </AppShell>
    );
}
