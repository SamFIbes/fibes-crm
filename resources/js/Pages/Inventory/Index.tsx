import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Boxes } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SolidCard, SolidSelect, solidField, solidFieldStyle } from '@/components/ui/classic';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Item = { id: number; sku: string; name_en: string; name_ar: string; unit: string; serialized: boolean; reorder_level: string; unit_cost_usd: string; on_hand: string; low: boolean };
type Place = { id: number; name: string; kind: string };
type Movement = { id: number; kind: string; quantity: string; unit_cost_usd: string; reason: string | null; item: string | null; customer?: string | null; node?: string | null; job?: string | null; expense?: string | null };
type LinkChoice = { id: number; name?: string; name_en?: string; name_ar?: string; account_no?: string; kind?: string; number?: string; title?: string };
type ErpState = { live: boolean; reason: string };
type StockRequestRow = { id: number; number: string | null; status: string; purpose: string; quantity: string; item: string | null; erp_name: string | null; last_error: string | null };
type Count = { id: number; note: string | null; lines: { item: string | null; expected: string; counted: string; variance: string }[] } | null;

function places(list: Place[], value: string, onChange: (value: string) => void, label: string) {
    return (
        <select aria-label={label} className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={value} onChange={(event) => onChange(event.target.value)}>
            {list.map((place) => <option key={place.id} value={place.id}>{place.name}</option>)}
        </select>
    );
}

function optionalSelect(list: LinkChoice[], value: string, onChange: (value: string) => void, label: string, locale: string, empty: string) {
    return (
        <SolidSelect aria-label={label} value={value} onChange={(event) => onChange(event.target.value)}>
            <option value="">{empty}</option>
            {list.map((row) => {
                const name = locale === 'ar' && row.name_ar ? row.name_ar : (row.name_en ?? row.name ?? row.title ?? row.number ?? String(row.id));
                const extra = row.number && row.title ? ` · ${row.number}` : row.kind ? ` · ${row.kind}` : row.account_no ? ` · ${row.account_no}` : '';
                return <option key={row.id} value={row.id}>{name}{extra}</option>;
            })}
        </SolidSelect>
    );
}

export default function InventoryIndex({ items, locations, movements, jobs, count, can_move, can_adjust, can_reserve, erp = { live: false, reason: 'credentials' }, customers = [], nodes = [], expenses = [], requests = [] }: {
    items: Item[];
    locations: Place[];
    movements: Movement[];
    jobs: { id: number; number: string }[];
    count: Count;
    can_move: boolean;
    can_adjust: boolean;
    can_reserve: boolean;
    erp?: ErpState;
    customers?: LinkChoice[];
    nodes?: LinkChoice[];
    expenses?: LinkChoice[];
    requests?: StockRequestRow[];
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const first = String(locations[0]?.id ?? '');
    const firstItem = String(items[0]?.id ?? '');
    const receive = useForm({ item_id: firstItem, location_id: first, quantity: '1', serials_text: '' });
    const transfer = useForm({ item_id: firstItem, from_location_id: first, to_location_id: String(locations[1]?.id ?? first), quantity: '1', serial: '' });
    const returned = useForm({ item_id: firstItem, location_id: first, quantity: '1', serial: '' });
    const writeOff = useForm({ item_id: firstItem, location_id: first, quantity: '1', serial: '', reason: '', disposition: 'faulty' });
    const reserve = useForm({ job_id: String(jobs[0]?.id ?? ''), item_id: firstItem, location_id: first, quantity: '1' });
    const counted = useForm({ location_id: first, note: '', item_id: firstItem, counted_qty: '0' });
    const blankLink = { customer_id: '', network_node_id: '', job_id: '', expense_id: '', expense_title: '', expense_amount: '' };
    const erpRequest = useForm({ item_id: firstItem, location_id: first, quantity: '1', purpose: 'Purchase', required_by: '', ...blankLink });
    const issue = useForm({ item_id: firstItem, location_id: first, quantity: '1', serial: '', ...blankLink });
    const name = (item: Item) => (locale === 'ar' ? item.name_ar : item.name_en);

    const postReceive = (event: FormEvent) => {
        event.preventDefault();
        const serials = receive.data.serials_text.split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
            const [serial, mac] = line.split(',');
            return { serial: serial.trim(), mac: mac?.trim() || null };
        });
        receive.transform((data) => ({ item_id: Number(data.item_id), location_id: Number(data.location_id), quantity: data.quantity, serials }));
        receive.post('/inventory/receive', { preserveScroll: true });
    };

    return (
        <AppShell>
            <Head title={t('inventory.title')} />
            <PageHeader title={t('inventory.title')} sub={t('inventory.subtitle')} />
            {page.props.flash.status ? <p className="mb-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
            <div data-testid="inventory-page" className="space-y-4">
                {can_move ? (
                    <form className="contents" onSubmit={(event) => { event.preventDefault(); erpRequest.post('/inventory/erp-request', { preserveScroll: true }); }}>
                        <SolidCard className="grid gap-3 p-5 md:grid-cols-2" >
                            <h2 className="md:col-span-2 text-sm font-semibold">{t('inventory.erp_request')}</h2>
                            <p className="md:col-span-2 text-sm text-muted">{erp.live ? t('integrations.erp_body') : t(erp.reason === 'credentials' ? 'integrations.off_reason_credentials' : `integrations.off_reason_${erp.reason === 'ready' ? 'credentials' : erp.reason}`)}</p>
                            {page.props.errors?.erp ? <p className="md:col-span-2 text-sm text-bad" role="alert">{page.props.errors.erp}</p> : null}
                            <Field id="erp-item" label={t('inventory.item')}>
                                <SolidSelect id="erp-item" value={erpRequest.data.item_id} onChange={(event) => erpRequest.setData('item_id', event.target.value)}>
                                    {items.map((item) => <option key={item.id} value={item.id}>{name(item)}</option>)}
                                </SolidSelect>
                            </Field>
                            <Field id="erp-place" label={t('inventory.location')}>{optionalSelect(locations, erpRequest.data.location_id, (value) => erpRequest.setData('location_id', value), t('inventory.location'), locale, t('inventory.none'))}</Field>
                            <Field id="erp-qty" label={t('inventory.quantity')}><input id="erp-qty" className={solidField} style={solidFieldStyle} dir="ltr" value={erpRequest.data.quantity} onChange={(event) => erpRequest.setData('quantity', event.target.value)} /></Field>
                            <Field id="erp-purpose" label={t('inventory.purpose')}>
                                <SolidSelect id="erp-purpose" value={erpRequest.data.purpose} onChange={(event) => erpRequest.setData('purpose', event.target.value)}>
                                    <option value="Purchase">{t('integrations.purpose_purchase')}</option>
                                    <option value="Material Transfer">{t('integrations.purpose_transfer')}</option>
                                    <option value="Material Issue">{t('integrations.purpose_issue')}</option>
                                </SolidSelect>
                            </Field>
                            <Field id="erp-by" label={t('inventory.required_by')}><input id="erp-by" className={solidField} style={solidFieldStyle} type="date" dir="ltr" value={erpRequest.data.required_by} onChange={(event) => erpRequest.setData('required_by', event.target.value)} /></Field>
                            <Field id="erp-customer" label={t('inventory.customer')}>{optionalSelect(customers, erpRequest.data.customer_id, (value) => erpRequest.setData('customer_id', value), t('inventory.customer'), locale, t('inventory.none'))}</Field>
                            <Field id="erp-node" label={t('inventory.node')}>{optionalSelect(nodes, erpRequest.data.network_node_id, (value) => erpRequest.setData('network_node_id', value), t('inventory.node'), locale, t('inventory.none'))}</Field>
                            <Field id="erp-job" label={t('jobs.new')}>{optionalSelect(jobs, erpRequest.data.job_id, (value) => erpRequest.setData('job_id', value), t('jobs.new'), locale, t('inventory.none'))}</Field>
                            <Field id="erp-expense" label={t('inventory.expense')}>{optionalSelect(expenses, erpRequest.data.expense_id, (value) => erpRequest.setData('expense_id', value), t('inventory.expense'), locale, t('inventory.none'))}</Field>
                            <Field id="erp-expense-title" label={t('inventory.expense_title')}><input id="erp-expense-title" className={solidField} style={solidFieldStyle} value={erpRequest.data.expense_title} onChange={(event) => erpRequest.setData('expense_title', event.target.value)} /></Field>
                            <div className="md:col-span-2">
                                <Button type="submit" data-testid="erp-request" disabled={!erp.live || erpRequest.processing}>{t('inventory.erp_request')}</Button>
                                {!erp.live ? <p className="mt-2 text-sm text-muted">{t('inventory.erp_off')}</p> : null}
                            </div>
                        </SolidCard>
                    </form>
                ) : null}
                {can_move ? (
                    <form className="contents" onSubmit={(event) => { event.preventDefault(); issue.post('/inventory/issue', { preserveScroll: true }); }}>
                        <SolidCard className="grid gap-3 p-5 md:grid-cols-2">
                            <h2 className="md:col-span-2 text-sm font-semibold">{t('inventory.issue_stock')}</h2>
                            <p className="md:col-span-2 text-sm text-muted">{t('inventory.link_help')}</p>
                            <Field id="is-item" label={t('inventory.item')}>
                                <SolidSelect id="is-item" value={issue.data.item_id} onChange={(event) => issue.setData('item_id', event.target.value)}>
                                    {items.map((item) => <option key={item.id} value={item.id}>{name(item)}</option>)}
                                </SolidSelect>
                            </Field>
                            <Field id="is-place" label={t('inventory.location')}>{optionalSelect(locations, issue.data.location_id, (value) => issue.setData('location_id', value), t('inventory.location'), locale, t('inventory.none'))}</Field>
                            <Field id="is-qty" label={t('inventory.quantity')}><input id="is-qty" className={solidField} style={solidFieldStyle} dir="ltr" value={issue.data.quantity} onChange={(event) => issue.setData('quantity', event.target.value)} /></Field>
                            <Field id="is-serial" label={t('inventory.serial')}><input id="is-serial" className={solidField} style={solidFieldStyle} dir="ltr" value={issue.data.serial} onChange={(event) => issue.setData('serial', event.target.value)} /></Field>
                            <Field id="is-customer" label={t('inventory.customer')}>{optionalSelect(customers, issue.data.customer_id, (value) => issue.setData('customer_id', value), t('inventory.customer'), locale, t('inventory.none'))}</Field>
                            <Field id="is-node" label={t('inventory.node')}>{optionalSelect(nodes, issue.data.network_node_id, (value) => issue.setData('network_node_id', value), t('inventory.node'), locale, t('inventory.none'))}</Field>
                            <Field id="is-job" label={t('jobs.new')}>{optionalSelect(jobs, issue.data.job_id, (value) => issue.setData('job_id', value), t('jobs.new'), locale, t('inventory.none'))}</Field>
                            <Field id="is-expense" label={t('inventory.expense')}>{optionalSelect(expenses, issue.data.expense_id, (value) => issue.setData('expense_id', value), t('inventory.expense'), locale, t('inventory.none'))}</Field>
                            <Field id="is-expense-title" label={t('inventory.expense_title')}><input id="is-expense-title" className={solidField} style={solidFieldStyle} value={issue.data.expense_title} onChange={(event) => issue.setData('expense_title', event.target.value)} /></Field>
                            <Field id="is-expense-amount" label={t('inventory.expense_amount')}><input id="is-expense-amount" className={solidField} style={solidFieldStyle} dir="ltr" value={issue.data.expense_amount} onChange={(event) => issue.setData('expense_amount', event.target.value)} /></Field>
                            <div className="md:col-span-2"><Button type="submit" variant="soft">{t('inventory.issue_stock')}</Button></div>
                        </SolidCard>
                    </form>
                ) : null}
                <SolidCard className="p-5">
                    <h2 className="text-sm font-semibold">{t('inventory.requests')}</h2>
                    {requests.length === 0 ? <p className="mt-2 text-sm text-muted">{t('inventory.no_requests')}</p> : (
                        <ul className="mt-2 divide-y divide-line text-sm">
                            {requests.map((row) => (
                                <li key={row.id} className="py-2" dir="ltr">{row.number} · {row.item} · {row.quantity} · {row.status}{row.erp_name ? ` · ${row.erp_name}` : ''}{row.last_error ? ` · ${row.last_error}` : ''}</li>
                            ))}
                        </ul>
                    )}
                </SolidCard>
                {items.length === 0 ? <div className="card"><EmptyState icon={<Boxes className="size-5" />} title={t('inventory.empty')} body={t('inventory.subtitle')} /></div> : (
                    <ul className="card divide-y divide-line">
                        {items.map((item) => (
                            <li key={item.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                                <div>
                                    <p className="font-semibold">{name(item)}</p>
                                    <p className="text-xs text-muted" dir="ltr">{item.sku} · {t(`inventory.unit_${item.unit}`)} · ${item.unit_cost_usd}</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span dir="ltr">{item.on_hand}</span>
                                    {item.low ? <Badge tone="warn">{t('inventory.low')}</Badge> : <Badge tone="good">{t('inventory.ok')}</Badge>}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {can_move ? (
                    <form className="card grid gap-3 p-5 md:grid-cols-2" onSubmit={postReceive}>
                        <h2 className="md:col-span-2 text-sm font-semibold">{t('inventory.receive')}</h2>
                        <Field id="recv-item" label={t('inventory.item')}>
                            <select id="recv-item" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={receive.data.item_id} onChange={(event) => receive.setData('item_id', event.target.value)}>
                                {items.map((item) => <option key={item.id} value={item.id}>{name(item)}</option>)}
                            </select>
                        </Field>
                        <Field id="recv-place" label={t('inventory.location')}>{places(locations, receive.data.location_id, (value) => receive.setData('location_id', value), t('inventory.location'))}</Field>
                        <Field id="recv-qty" label={t('inventory.quantity')}><Input id="recv-qty" dir="ltr" value={receive.data.quantity} onChange={(event) => receive.setData('quantity', event.target.value)} /></Field>
                        <Field id="recv-serial" label={t('inventory.serials')}><textarea id="recv-serial" className="min-h-20 w-full rounded-ctl border border-input bg-card p-2 text-sm" dir="ltr" value={receive.data.serials_text} onChange={(event) => receive.setData('serials_text', event.target.value)} placeholder={t('inventory.serial_hint')} /></Field>
                        <div className="md:col-span-2"><Button type="submit">{t('inventory.receive')}</Button></div>
                    </form>
                ) : null}
                {can_move ? (
                    <form className="card grid gap-3 p-5 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); transfer.post('/inventory/transfer', { preserveScroll: true }); }}>
                        <h2 className="md:col-span-2 text-sm font-semibold">{t('inventory.transfer')}</h2>
                        <Field id="tr-item" label={t('inventory.item')}>
                            <select id="tr-item" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={transfer.data.item_id} onChange={(event) => transfer.setData('item_id', event.target.value)}>
                                {items.map((item) => <option key={item.id} value={item.id}>{name(item)}</option>)}
                            </select>
                        </Field>
                        <Field id="tr-serial" label={t('inventory.serial')}><Input id="tr-serial" dir="ltr" value={transfer.data.serial} onChange={(event) => transfer.setData('serial', event.target.value)} /></Field>
                        <Field id="tr-from" label={t('inventory.from')}>{places(locations, transfer.data.from_location_id, (value) => transfer.setData('from_location_id', value), t('inventory.from'))}</Field>
                        <Field id="tr-to" label={t('inventory.to')}>{places(locations, transfer.data.to_location_id, (value) => transfer.setData('to_location_id', value), t('inventory.to'))}</Field>
                        <Field id="tr-qty" label={t('inventory.quantity')}><Input id="tr-qty" dir="ltr" value={transfer.data.quantity} onChange={(event) => transfer.setData('quantity', event.target.value)} /></Field>
                        <div className="flex items-end"><Button type="submit" variant="outline">{t('inventory.transfer')}</Button></div>
                    </form>
                ) : null}
                {can_move ? (
                    <form className="card grid gap-3 p-5 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); returned.post('/inventory/return', { preserveScroll: true }); }}>
                        <h2 className="md:col-span-2 text-sm font-semibold">{t('inventory.return')}</h2>
                        <Field id="rt-item" label={t('inventory.item')}>
                            <select id="rt-item" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={returned.data.item_id} onChange={(event) => returned.setData('item_id', event.target.value)}>
                                {items.map((item) => <option key={item.id} value={item.id}>{name(item)}</option>)}
                            </select>
                        </Field>
                        <Field id="rt-place" label={t('inventory.location')}>{places(locations, returned.data.location_id, (value) => returned.setData('location_id', value), t('inventory.location'))}</Field>
                        <Field id="rt-qty" label={t('inventory.quantity')}><Input id="rt-qty" dir="ltr" value={returned.data.quantity} onChange={(event) => returned.setData('quantity', event.target.value)} /></Field>
                        <Field id="rt-serial" label={t('inventory.serial')}><Input id="rt-serial" dir="ltr" value={returned.data.serial} onChange={(event) => returned.setData('serial', event.target.value)} /></Field>
                        <div className="md:col-span-2"><Button type="submit" variant="outline">{t('inventory.return')}</Button></div>
                    </form>
                ) : null}
                {can_adjust ? (
                    <form className="card grid gap-3 p-5 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); writeOff.post('/inventory/write-off', { preserveScroll: true }); }}>
                        <h2 className="md:col-span-2 text-sm font-semibold">{t('inventory.write_off')}</h2>
                        <Field id="wo-item" label={t('inventory.item')}>
                            <select id="wo-item" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={writeOff.data.item_id} onChange={(event) => writeOff.setData('item_id', event.target.value)}>
                                {items.map((item) => <option key={item.id} value={item.id}>{name(item)}</option>)}
                            </select>
                        </Field>
                        <Field id="wo-place" label={t('inventory.location')}>{places(locations, writeOff.data.location_id, (value) => writeOff.setData('location_id', value), t('inventory.location'))}</Field>
                        <Field id="wo-serial" label={t('inventory.serial')}><Input id="wo-serial" dir="ltr" value={writeOff.data.serial} onChange={(event) => writeOff.setData('serial', event.target.value)} /></Field>
                        <Field id="wo-reason" label={t('inventory.reason')} error={writeOff.errors.reason}><Input id="wo-reason" value={writeOff.data.reason} onChange={(event) => writeOff.setData('reason', event.target.value)} required /></Field>
                        <Field id="wo-qty" label={t('inventory.quantity')}><Input id="wo-qty" dir="ltr" value={writeOff.data.quantity} onChange={(event) => writeOff.setData('quantity', event.target.value)} /></Field>
                        <div className="md:col-span-2"><Button type="submit" variant="danger">{t('inventory.write_off')}</Button></div>
                    </form>
                ) : null}
                {can_reserve ? (
                    <form className="card grid gap-3 p-5 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); reserve.post('/inventory/reservations', { preserveScroll: true }); }}>
                        <h2 className="md:col-span-2 text-sm font-semibold">{t('inventory.reserve')}</h2>
                        <Field id="rs-job" label={t('jobs.new')}>
                            <select id="rs-job" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={reserve.data.job_id} onChange={(event) => reserve.setData('job_id', event.target.value)}>
                                {jobs.map((job) => <option key={job.id} value={job.id}>{job.number}</option>)}
                            </select>
                        </Field>
                        <Field id="rs-qty" label={t('inventory.quantity')}><Input id="rs-qty" dir="ltr" value={reserve.data.quantity} onChange={(event) => reserve.setData('quantity', event.target.value)} /></Field>
                        <div className="md:col-span-2"><Button type="submit" variant="outline">{t('inventory.reserve')}</Button></div>
                    </form>
                ) : null}
                <form className="card grid gap-3 p-5 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); counted.transform((data) => ({ location_id: Number(data.location_id), note: data.note, lines: [{ item_id: Number(data.item_id), counted_qty: data.counted_qty }] })); counted.post('/inventory/counts', { preserveScroll: true }); }}>
                    <h2 className="md:col-span-2 text-sm font-semibold">{t('inventory.count')}</h2>
                    <Field id="ct-place" label={t('inventory.location')}>{places(locations, counted.data.location_id, (value) => counted.setData('location_id', value), t('inventory.location'))}</Field>
                    <Field id="ct-item" label={t('inventory.item')}>
                        <select id="ct-item" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={counted.data.item_id} onChange={(event) => counted.setData('item_id', event.target.value)}>
                            {items.map((item) => <option key={item.id} value={item.id}>{name(item)}</option>)}
                        </select>
                    </Field>
                    <Field id="ct-qty" label={t('inventory.counted')}><Input id="ct-qty" dir="ltr" value={counted.data.counted_qty} onChange={(event) => counted.setData('counted_qty', event.target.value)} /></Field>
                    <div className="flex items-end"><Button type="submit" variant="outline">{t('inventory.count')}</Button></div>
                </form>
                {count ? (
                    <section className="card p-5">
                        <h2 className="text-sm font-semibold">{t('inventory.variance')}</h2>
                        <ul className="mt-2 space-y-1 text-sm">
                            {count.lines.map((line, index) => (
                                <li key={index} dir="ltr">{line.item}: {line.counted} − {line.expected} = {line.variance}</li>
                            ))}
                        </ul>
                    </section>
                ) : null}
                <section className="card p-5">
                    <h2 className="text-sm font-semibold">{t('inventory.ledger')}</h2>
                    {movements.length === 0 ? <p className="mt-2 text-sm text-muted">{t('inventory.no_moves')}</p> : (
                        <ul className="mt-2 divide-y divide-line text-sm">
                            {movements.map((row) => (
                                <li key={row.id} className="py-2" dir="ltr">{t(`inventory.kind_${row.kind}`)} · {row.item} · {row.quantity} · ${row.unit_cost_usd}{row.reason ? ` · ${row.reason}` : ''}{row.customer ? ` · ${row.customer}` : ''}{row.node ? ` · ${row.node}` : ''}{row.job ? ` · ${row.job}` : ''}{row.expense ? ` · ${row.expense}` : ''}</li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppShell>
    );
}
