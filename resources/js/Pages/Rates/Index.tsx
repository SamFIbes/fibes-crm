import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Currency = { code: string; name_en: string; name_ar: string; symbol: string };
type Rate = { id: number; effective_on: string; usd_lbp: string; confirmed: boolean; note: string | null; author: string | null };

export default function RatesIndex({ currencies, rates, needs_confirmation, can_manage }: { currencies: Currency[]; rates: Rate[]; needs_confirmation: boolean; can_manage: boolean }) {
    const { t, locale } = useI18n();
    const { flash } = usePage<SharedProps>().props;
    const form = useForm({ effective_on: '', usd_lbp: '', note: '' });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.post('/rates', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <AppShell>
            <Head title={t('rates.title')} />
            <PageHeader title={t('rates.title')} sub={t('rates.subtitle')} />
            {needs_confirmation ? <p className="mb-4 rounded-tile bg-warn-bg px-3 py-2 text-sm text-warn">{t('rates.confirm')}</p> : null}
            {flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{flash.status}</p> : null}
            <div className="mb-4 flex flex-wrap gap-2" data-testid="rates-page">
                {currencies.map((currency) => (
                    <span key={currency.code} className="rounded-full bg-card px-3 py-1 text-sm ring-1 ring-line">
                        {currency.code} · {locale === 'ar' ? currency.name_ar : currency.name_en}
                    </span>
                ))}
            </div>
            {can_manage ? (
                <form onSubmit={save} className="card mb-4 grid gap-3 p-4 md:grid-cols-4">
                    <Field id="effective_on" label={t('rates.effective')} error={form.errors.effective_on}>
                        <Input id="effective_on" dir="ltr" type="date" value={form.data.effective_on} onChange={(event) => form.setData('effective_on', event.target.value)} required />
                    </Field>
                    <Field id="usd_lbp" label={t('rates.usd_lbp')} error={form.errors.usd_lbp}>
                        <Input id="usd_lbp" dir="ltr" inputMode="decimal" value={form.data.usd_lbp} onChange={(event) => form.setData('usd_lbp', event.target.value)} required />
                    </Field>
                    <Field id="note" label={t('rates.note')}>
                        <Input id="note" value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} />
                    </Field>
                    <div className="flex items-end">
                        <Button type="submit" disabled={form.processing}>{t('rates.record')}</Button>
                    </div>
                </form>
            ) : null}
            <div className="card overflow-x-auto">
                <table className="w-full text-start">
                    <thead>
                        <tr className="border-b border-line text-2xs font-bold uppercase tracking-[0.1em] text-faint">
                            <th className="px-4 py-3 text-start">{t('rates.effective')}</th>
                            <th className="px-4 py-3 text-start">{t('rates.usd_lbp')}</th>
                            <th className="px-4 py-3 text-start">{t('rates.by')}</th>
                            <th className="px-4 py-3 text-start">{t('rates.note')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rates.map((rate) => (
                            <tr key={rate.id} className="border-b border-line last:border-0">
                                <td className="px-4 py-3 text-sm" dir="ltr">{rate.effective_on}</td>
                                <td className="px-4 py-3 text-sm tabular" dir="ltr">{rate.usd_lbp}</td>
                                <td className="px-4 py-3 text-sm">{rate.author ?? '—'}</td>
                                <td className="px-4 py-3 text-sm text-muted">
                                    {rate.confirmed ? <Badge tone="good">{t('rates.confirmed')}</Badge> : <Badge tone="warn">{t('rates.placeholder')}</Badge>}
                                    {rate.note ? <span className="ms-2">{rate.note}</span> : null}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppShell>
    );
}
