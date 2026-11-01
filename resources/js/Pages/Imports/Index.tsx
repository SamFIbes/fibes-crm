import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Batch = { id: number; type: string; status: string; counts: string };

export default function ImportsIndex({ batches, types }: { batches: Batch[]; types: string[] }) {
    const { t } = useI18n();
    const page = usePage<SharedProps>();
    const form = useForm({
        type: 'customers',
        csv: 'name,phone\n',
        mapping: { name: 'name', phone: 'phone', plan: 'plan', sku: 'sku', price: 'price', latitude: 'latitude', longitude: 'longitude' },
    });
    const krypton = useForm({
        csv: 'Name,Username,Status,Phone Number,Mobile Number\n',
        apply: false,
    });

    return (
        <AppShell>
            <Head title={t('imports.title')} />
            <div data-testid="imports-page">
                <PageHeader title={t('imports.title')} sub={t('imports.subtitle')} />
                {page.props.flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line" data-testid="import-status">{page.props.flash.status}</p> : null}
                {page.props.flash.link ? <p className="mb-4 text-sm" dir="ltr"><a className="font-semibold text-accent-ink" href={page.props.flash.link}>{page.props.flash.link}</a></p> : null}
                <form className="card grid gap-3 p-4" onSubmit={(event) => event.preventDefault()}>
                    <label className="text-sm">
                        {t('imports.type')}
                        <select className="ms-2 h-10 rounded-ctl border border-input bg-card px-2" value={form.data.type} onChange={(event) => form.setData('type', event.target.value)} data-testid="import-type">
                            {types.map((type) => <option key={type} value={type}>{type}</option>)}
                        </select>
                    </label>
                    <label className="text-sm">
                        {t('imports.file')}
                        <textarea className="mt-1 h-28 w-full rounded-ctl border border-input bg-card p-2 text-sm" dir="ltr" value={form.data.csv} onChange={(event) => form.setData('csv', event.target.value)} data-testid="import-csv" />
                    </label>
                    <label className="text-sm">{t('imports.name_column')}<input className="ms-2 h-10 rounded-ctl border border-input bg-card px-2" dir="ltr" value={form.data.mapping.name} onChange={(event) => form.setData('mapping', { ...form.data.mapping, name: event.target.value })} /></label>
                    <label className="text-sm">{t('imports.phone_column')}<input className="ms-2 h-10 rounded-ctl border border-input bg-card px-2" dir="ltr" value={form.data.mapping.phone} onChange={(event) => form.setData('mapping', { ...form.data.mapping, phone: event.target.value })} /></label>
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" onClick={() => form.post('/imports/preview')}>{t('imports.preview')}</Button>
                        <Button type="button" onClick={() => form.post('/imports')}>{t('imports.apply')}</Button>
                    </div>
                </form>
                <form className="card mt-4 grid gap-3 p-4" onSubmit={(event) => event.preventDefault()}>
                    <label className="text-sm">
                        {t('imports.krypton')}
                        <textarea className="mt-1 h-28 w-full rounded-ctl border border-input bg-card p-2 text-sm" dir="ltr" value={krypton.data.csv} onChange={(event) => krypton.setData('csv', event.target.value)} data-testid="krypton-csv" />
                    </label>
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" onClick={() => { krypton.transform((data) => ({ ...data, apply: false })); krypton.post('/imports/krypton'); }}>{t('imports.preview')}</Button>
                        <Button type="button" onClick={() => { krypton.transform((data) => ({ ...data, apply: true })); krypton.post('/imports/krypton'); }}>{t('imports.apply')}</Button>
                    </div>
                </form>
                <ul className="mt-4 space-y-2">
                    {batches.map((batch) => (
                        <li key={batch.id} className="card flex items-center justify-between px-4 py-3 text-sm" data-testid="import-batch">
                            <span dir="ltr">{batch.type} · {batch.status}</span>
                            {batch.status === 'applied' ? <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/imports/${batch.id}/undo`)}>{t('imports.undo')}</Button> : null}
                        </li>
                    ))}
                </ul>
            </div>
        </AppShell>
    );
}
