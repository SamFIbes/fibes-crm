import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, PointerEvent as ReactPointerEvent, useEffect, useRef, useState } from 'react';
import { Smartphone } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { QuickTask } from '@/components/tasks/QuickTask';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { csrfToken, enqueue, flushQueue, readQueue } from '@/lib/jobQueue';

type Check = { id: number; label_en: string; label_ar: string; required: boolean; done: boolean };
type JobRow = {
    id: number;
    number: string;
    type: string;
    status: string;
    window_start: string | null;
    notes: string | null;
    materials: string | null;
    signed_by: string | null;
    maps: string | null;
    customer: { name_en: string; name_ar: string; account_no: string; phone: string | null; area_en: string | null; area_ar: string | null };
    checks: Check[];
    files: { id: number; name: string }[];
};

async function compress(file: File): Promise<File> {
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    canvas.getContext('2d')?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.7));
    bitmap.close();

    return new File([blob ?? file], 'photo.jpg', { type: 'image/jpeg' });
}

type VanItem = { id: number; name_en: string; name_ar: string; unit: string; serialized: boolean; on_hand: string; serials: { id: number; serial: string; mac: string | null }[] };

export default function TechIndex({ jobs, failures, van = [], can_collect = false, field_cash = false }: { jobs: JobRow[]; failures: string[]; van?: VanItem[]; can_collect?: boolean; field_cash?: boolean }) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const [queue, setQueue] = useState(0);

    useEffect(() => {
        setQueue(readQueue().length);
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(() => undefined);
        }
        const sync = () => {
            flushQueue(csrfToken()).then((left) => {
                setQueue(left.length);
                if (left.length === 0) router.reload({ only: ['jobs'] });
            });
        };
        window.addEventListener('online', sync);

        return () => window.removeEventListener('online', sync);
    }, []);

    const move = (job: JobRow, status: string, extra: Record<string, string> = {}) => {
        const url = `/tech/jobs/${job.id}/status`;
        const body = { status, ...extra };

        if (!navigator.onLine) {
            setQueue(enqueue({ key: `${job.id}-${status}-${Date.now()}`, url, body }).length);

            return;
        }

        router.post(url, body, { preserveScroll: true });
    };

    return (
        <AppShell>
            <Head title={t('tech_app.title')} />
            <div className="mx-auto max-w-lg" data-testid="tech-app">
                <h1 className="text-3xl font-semibold tracking-tight">{t('tech_app.title')}</h1>
                <p className="mt-1 text-sm text-muted">{t('tech_app.subtitle')}</p>
                {queue > 0 ? (
                    <button type="button" data-testid="sync-queue" className="mt-3 w-full rounded-tile bg-warn-bg px-3 py-2 text-sm" onClick={() => flushQueue(csrfToken()).then((left) => { setQueue(left.length); if (left.length === 0) router.reload(); })}>
                        {t('tech_app.queued', { count: queue })}
                    </button>
                ) : null}
                {jobs.length === 0 ? <div className="card mt-4"><EmptyState icon={<Smartphone className="size-5" />} title={t('tech_app.empty')} body={t('tech_app.subtitle')} /></div> : null}
                <div className="mt-4 space-y-4">
                    {jobs.map((job) => (
                        <article key={job.id} className="card space-y-3 p-4" data-testid="tech-job">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="text-xs text-faint" dir="ltr">{job.number}</p>
                                    <h2 className="text-lg font-semibold">{locale === 'ar' ? job.customer.name_ar : job.customer.name_en}</h2>
                                    <p className="text-sm text-muted">{t(`jobs.type_${job.type}`)} · {t(`jobs.status_${job.status}`)}</p>
                                    <p className="text-sm" dir="ltr">{job.window_start || '—'} · {job.customer.phone || (locale === 'ar' ? job.customer.area_ar : job.customer.area_en) || ''}</p>
                                </div>
                            </div>
                            {job.maps ? <a className="inline-flex text-sm font-semibold text-accent-ink" href={job.maps} target="_blank" rel="noreferrer">{t('tech_app.navigate')}</a> : null}
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" size="sm" onClick={() => move(job, 'en_route')}>{t('tech_app.start')}</Button>
                                <Button type="button" size="sm" variant="outline" onClick={() => move(job, 'on_site')}>{t('tech_app.arrive')}</Button>
                                <Button type="button" size="sm" variant="outline" onClick={() => move(job, 'done')}>{t('tech_app.finish')}</Button>
                            </div>
                            <ul className="space-y-1">
                                {job.checks.map((check) => (
                                    <li key={check.id}>
                                        <label className="flex items-center gap-2 text-sm">
                                            <input type="checkbox" checked={check.done} onChange={(event) => router.post(`/tech/jobs/${job.id}/checks/${check.id}`, { done: event.target.checked }, { preserveScroll: true })} />
                                            {locale === 'ar' ? check.label_ar : check.label_en}
                                        </label>
                                    </li>
                                ))}
                            </ul>
                            {page.props.auth.abilities?.tasks_create ? <QuickTask jobId={job.id} /> : null}
                            {job.type === 'survey' ? <Survey job={job} services={['ftth', 'fttb', 'ptmp', 'ptp', 'internal', 'dsl']} /> : null}
                            <Notes job={job} />
                            <Van job={job} van={van} offline={(body) => {
                                if (!navigator.onLine) {
                                    setQueue(enqueue({ key: `stock-${job.id}-${body.serial ?? body.item_id}-${Date.now()}`, url: `/tech/jobs/${job.id}/stock`, body }).length);
                                    return true;
                                }
                                return false;
                            }} />
                            <Photo jobId={job.id} compress={compress} />
                            <Signature jobId={job.id} />
                            <Fail job={job} failures={failures} onFail={(reason) => move(job, 'failed', { failure_reason: reason })} />
                            {can_collect && field_cash ? <Collect job={job} /> : null}
                        </article>
                    ))}
                </div>
            </div>
        </AppShell>
    );
}

function Collect({ job }: { job: JobRow }) {
    const { t } = useI18n();
    const form = useForm({ amount: '', currency: 'USD', password: '' });

    return (
        <form data-testid="collect-cash" className="grid gap-2" onSubmit={(event) => {
            event.preventDefault();
            const body = { amount: form.data.amount, currency: form.data.currency, password: form.data.password };
            if (!navigator.onLine) {
                enqueue({ key: `cash-${job.id}-${form.data.amount}-${form.data.currency}`, url: `/tech/jobs/${job.id}/cash`, body });
                return;
            }
            form.post(`/tech/jobs/${job.id}/cash`, { preserveScroll: true });
        }}>
            <p className="text-sm font-semibold">{t('tech_app.collect')}</p>
            <p className="text-xs text-muted">{t('tech_app.collect_help')}</p>
            <Field id={`cash-amount-${job.id}`} label={t('billing.amount')}><Input id={`cash-amount-${job.id}`} dir="ltr" value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} /></Field>
            <Field id={`cash-currency-${job.id}`} label={t('payments.currency')}>
                <select id={`cash-currency-${job.id}`} className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.currency} onChange={(event) => form.setData('currency', event.target.value)}>
                    <option value="USD">USD</option>
                    <option value="LBP">LBP</option>
                </select>
            </Field>
            <Field id={`cash-password-${job.id}`} label={t('payments.password')}><Input id={`cash-password-${job.id}`} type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} /></Field>
            <Button type="submit" size="sm">{t('tech_app.collect')}</Button>
        </form>
    );
}

function Survey({ job, services }: { job: JobRow; services: string[] }) {
    const { t } = useI18n();
    const form = useForm({
        feasible: true,
        reason: '',
        los: '',
        signal_dbm: '',
        cable_m: '',
        mounting_point: '',
        riser_ok: false,
        power_ok: false,
        materials: '',
        recommended_service_type: services[0] ?? 'ftth',
    });

    return (
        <form data-testid="survey-form" className="grid gap-2" onSubmit={(event) => { event.preventDefault(); form.post(`/tech/jobs/${job.id}/survey`, { preserveScroll: true }); }}>
            <p className="text-sm font-semibold">{t('coverage.survey')}</p>
            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.data.feasible} onChange={(event) => form.setData('feasible', event.target.checked)} />
                {form.data.feasible ? t('coverage.feasible') : t('coverage.not_feasible')}
            </label>
            <Field id={`los-${job.id}`} label={t('coverage.los')}><Input id={`los-${job.id}`} value={form.data.los} onChange={(event) => form.setData('los', event.target.value)} /></Field>
            <Field id={`signal-${job.id}`} label={t('coverage.signal')}><Input id={`signal-${job.id}`} dir="ltr" value={form.data.signal_dbm} onChange={(event) => form.setData('signal_dbm', event.target.value)} /></Field>
            <Field id={`cable-${job.id}`} label={t('coverage.cable')}><Input id={`cable-${job.id}`} dir="ltr" value={form.data.cable_m} onChange={(event) => form.setData('cable_m', event.target.value)} /></Field>
            <Field id={`mount-${job.id}`} label={t('coverage.mount')}><Input id={`mount-${job.id}`} value={form.data.mounting_point} onChange={(event) => form.setData('mounting_point', event.target.value)} /></Field>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.riser_ok} onChange={(event) => form.setData('riser_ok', event.target.checked)} />{t('coverage.riser')}</label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.power_ok} onChange={(event) => form.setData('power_ok', event.target.checked)} />{t('coverage.power')}</label>
            <Field id={`materials-${job.id}`} label={t('coverage.materials')}><Input id={`materials-${job.id}`} value={form.data.materials} onChange={(event) => form.setData('materials', event.target.value)} /></Field>
            <Field id={`plan-${job.id}`} label={t('coverage.plan')}>
                <select id={`plan-${job.id}`} className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.recommended_service_type} onChange={(event) => form.setData('recommended_service_type', event.target.value)}>
                    {services.map((service) => <option key={service} value={service}>{t(`customers.${service}`)}</option>)}
                </select>
            </Field>
            <Button type="submit" size="sm">{t('coverage.save_survey')}</Button>
        </form>
    );
}

function Notes({ job }: { job: JobRow }) {
    const { t } = useI18n();
    const form = useForm({ notes: job.notes ?? '', materials: job.materials ?? '' });

    return (
        <form className="grid gap-2" onSubmit={(event) => { event.preventDefault(); form.patch(`/tech/jobs/${job.id}`, { preserveScroll: true }); }}>
            <Field id={`notes-${job.id}`} label={t('tech_app.notes')}><textarea id={`notes-${job.id}`} className="min-h-16 w-full rounded-ctl border border-input bg-card p-2 text-sm" value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} /></Field>
            <Field id={`materials-${job.id}`} label={t('tech_app.materials')}><textarea id={`materials-${job.id}`} className="min-h-16 w-full rounded-ctl border border-input bg-card p-2 text-sm" value={form.data.materials} onChange={(event) => form.setData('materials', event.target.value)} /></Field>
            <Button type="submit" variant="outline" size="sm">{t('tech_app.save_notes')}</Button>
        </form>
    );
}

function Photo({ jobId, compress }: { jobId: number; compress: (file: File) => Promise<File> }) {
    const { t } = useI18n();
    const form = useForm<{ file: File | null }>({ file: null });

    return (
        <form className="flex flex-wrap items-center gap-2" onSubmit={async (event) => {
            event.preventDefault();
            if (!form.data.file) return;
            form.setData('file', await compress(form.data.file));
            form.post(`/tech/jobs/${jobId}/photos`, { forceFormData: true, preserveScroll: true });
        }}>
            <input aria-label={t('tech_app.photo')} type="file" accept="image/*" capture="environment" onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)} />
            <Button type="submit" size="sm" variant="outline">{t('tech_app.photo')}</Button>
        </form>
    );
}

function Signature({ jobId }: { jobId: number }) {
    const { t } = useI18n();
    const pad = useRef<HTMLCanvasElement>(null);
    const drawing = useRef(false);
    const name = useForm({ signed_by: '' });

    const point = (event: ReactPointerEvent<HTMLCanvasElement>) => {
        const canvas = pad.current;
        const ctx = canvas?.getContext('2d');
        if (!canvas || !ctx) return;
        const rect = canvas.getBoundingClientRect();
        const x = event.clientX - rect.left;
        const y = event.clientY - rect.top;
        if (event.type === 'pointerdown') {
            drawing.current = true;
            ctx.beginPath();
            ctx.moveTo(x, y);
        } else if (drawing.current) {
            ctx.lineTo(x, y);
            ctx.stroke();
        }
        if (event.type === 'pointerup' || event.type === 'pointerleave') drawing.current = false;
    };

    const save = (event: FormEvent) => {
        event.preventDefault();
        const canvas = pad.current;
        canvas?.toBlob((blob) => {
            if (!blob) return;
            name.transform(() => ({ signed_by: name.data.signed_by, file: new File([blob], 'signature.png', { type: 'image/png' }) }));
            name.post(`/tech/jobs/${jobId}/signature`, { forceFormData: true, preserveScroll: true });
        }, 'image/png');
    };

    return (
        <form className="grid gap-2" onSubmit={save}>
            <Field id={`sign-name-${jobId}`} label={t('tech_app.signed_by')}><Input id={`sign-name-${jobId}`} value={name.data.signed_by} onChange={(event) => name.setData('signed_by', event.target.value)} required /></Field>
            <canvas ref={pad} width={320} height={120} data-testid="signature-pad" className="w-full rounded-ctl border border-line bg-card" onPointerDown={point} onPointerMove={point} onPointerUp={point} onPointerLeave={point} />
            <Button type="submit" size="sm" variant="outline">{t('tech_app.save_signature')}</Button>
        </form>
    );
}

function Van({ job, van, offline }: { job: JobRow; van: VanItem[]; offline: (body: Record<string, string | number | boolean | null>) => boolean }) {
    const { t, locale } = useI18n();
    const form = useForm({ item_id: String(van[0]?.id ?? ''), quantity: '1', serial: '', install: false });
    const item = van.find((row) => String(row.id) === form.data.item_id);

    const scan = async () => {
        const detectorCtor = (window as unknown as { BarcodeDetector?: new (opts?: { formats?: string[] }) => { detect: (source: CanvasImageSource) => Promise<{ rawValue: string }[]> } }).BarcodeDetector;
        if (!detectorCtor || !navigator.mediaDevices?.getUserMedia) return;
        const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        const video = document.createElement('video');
        video.srcObject = stream;
        await video.play();
        const detector = new detectorCtor({ formats: ['code_128', 'qr_code', 'ean_13'] });
        const found = await detector.detect(video);
        stream.getTracks().forEach((track) => track.stop());
        if (found[0]?.rawValue) form.setData('serial', found[0].rawValue);
    };

    if (van.length === 0) return null;

    return (
        <form className="grid gap-2" data-testid="van-stock" onSubmit={(event) => {
            event.preventDefault();
            const body = { item_id: Number(form.data.item_id), quantity: form.data.quantity, serial: form.data.serial || null, install: form.data.install };
            if (offline(body)) return;
            form.post(`/tech/jobs/${job.id}/stock`, { preserveScroll: true });
        }}>
            <h3 className="text-sm font-semibold">{t('inventory.van')}</h3>
            <ul className="text-xs text-muted">
                {van.map((row) => <li key={row.id}>{locale === 'ar' ? row.name_ar : row.name_en} · <span dir="ltr">{row.on_hand}</span></li>)}
            </ul>
            <select aria-label={t('inventory.item')} className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.item_id} onChange={(event) => form.setData('item_id', event.target.value)}>
                {van.map((row) => <option key={row.id} value={row.id}>{locale === 'ar' ? row.name_ar : row.name_en}</option>)}
            </select>
            {item?.serialized ? (
                <div className="flex gap-2">
                    <Input dir="ltr" aria-label={t('inventory.serial')} value={form.data.serial} onChange={(event) => form.setData('serial', event.target.value)} placeholder={item.serials[0]?.serial} />
                    <Button type="button" size="sm" variant="outline" onClick={() => scan().catch(() => undefined)}>{t('inventory.scan')}</Button>
                </div>
            ) : <Input dir="ltr" aria-label={t('inventory.quantity')} value={form.data.quantity} onChange={(event) => form.setData('quantity', event.target.value)} />}
            {job ? <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.install} onChange={(event) => form.setData('install', event.target.checked)} /> {t('inventory.install')}</label> : null}
            <Button type="submit" size="sm" variant="outline">{t('inventory.use')}</Button>
        </form>
    );
}

function Fail({ failures, onFail }: { job: JobRow; failures: string[]; onFail: (reason: string) => void }) {
    const { t } = useI18n();
    const [reason, setReason] = useState(failures[0] ?? 'other');

    return (
        <div className="flex flex-wrap items-center gap-2">
            <select aria-label={t('jobs.failure')} className="h-9 rounded-ctl border border-input bg-card px-2 text-sm" value={reason} onChange={(event) => setReason(event.target.value)}>
                {failures.map((item) => <option key={item} value={item}>{t(`jobs.failure_${item}`)}</option>)}
            </select>
            <Button type="button" size="sm" variant="danger" onClick={() => onFail(reason)}>{t('tech_app.fail')}</Button>
        </div>
    );
}
