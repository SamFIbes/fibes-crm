import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useRef } from 'react';
import { GuestLayout } from '@/layouts/GuestLayout';
import { Field } from '@/components/form/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Payload = {
    id: number;
    number: string | null;
    status: string;
    body: string;
    term_months: number;
    fee: string;
    signed: boolean;
    sha256: string | null;
};

export default function SignContract({
    contract,
    otp_url,
    sign_url,
    scan_url,
}: {
    contract: Payload;
    otp_url: string;
    sign_url: string;
    scan_url: string | null;
}) {
    const { t } = useI18n();
    const page = usePage<SharedProps>();
    const pad = useRef<HTMLCanvasElement>(null);
    const form = useForm({ signer_name: '', consent: false, otp: '', signature: '' });
    const drawing = useRef(false);

    const point = (event: React.PointerEvent<HTMLCanvasElement>) => {
        const canvas = pad.current;
        if (!canvas) {
            return null;
        }
        const rect = canvas.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const signature = pad.current?.toDataURL('image/png') ?? '';
        form.transform((data) => ({ ...data, signature }));
        form.post(sign_url);
    };

    return (
        <GuestLayout>
            <Head title={t('quotes.sign_title')} />
            <div data-testid="contract-sign" className="mx-auto w-full max-w-xl">
                <h1 className="text-2xl font-semibold">{t('quotes.sign_title')}</h1>
                <p className="mt-1 text-sm text-muted" dir="ltr">{contract.number}</p>
                {page.props.flash.status ? <p className="mt-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                <article className="prose mt-4 max-w-none rounded-tile bg-white p-4 text-sm text-navy ring-1 ring-line" dangerouslySetInnerHTML={{ __html: contract.body }} />
                <p className="mt-3 text-sm text-muted">{t('quotes.terms_note')} <span dir="ltr">{contract.term_months} · ${contract.fee}</span></p>
                {contract.signed ? <p className="mt-4 break-all text-sm" dir="ltr">{contract.sha256}</p> : (
                    <form className="mt-4 space-y-4" onSubmit={submit}>
                        <Field id="signer" label={t('quotes.signer')} error={form.errors.signer_name}>
                            <Input id="signer" value={form.data.signer_name} onChange={(event) => form.setData('signer_name', event.target.value)} />
                        </Field>
                        <label className="flex items-start gap-2 text-sm">
                            <input type="checkbox" checked={form.data.consent} onChange={(event) => form.setData('consent', event.target.checked)} />
                            {t('quotes.consent')}
                        </label>
                        {form.errors.consent ? <p className="text-sm text-destructive">{form.errors.consent}</p> : null}
                        <canvas
                            ref={pad}
                            data-testid="signature-pad"
                            width={520}
                            height={160}
                            className="w-full touch-none rounded-tile bg-white ring-1 ring-line"
                            onPointerDown={(event) => {
                                drawing.current = true;
                                const ctx = pad.current?.getContext('2d');
                                const at = point(event);
                                if (ctx && at) {
                                    ctx.strokeStyle = '#0E0F2D';
                                    ctx.lineWidth = 2;
                                    ctx.beginPath();
                                    ctx.moveTo(at.x, at.y);
                                }
                            }}
                            onPointerMove={(event) => {
                                if (!drawing.current) {
                                    return;
                                }
                                const ctx = pad.current?.getContext('2d');
                                const at = point(event);
                                if (ctx && at) {
                                    ctx.lineTo(at.x, at.y);
                                    ctx.stroke();
                                }
                            }}
                            onPointerUp={() => {
                                drawing.current = false;
                            }}
                        />
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" variant="outline" onClick={() => pad.current?.getContext('2d')?.clearRect(0, 0, 520, 160)}>{t('quotes.clear')}</Button>
                            <Button type="button" variant="outline" onClick={() => router.post(otp_url, {}, { preserveScroll: true })}>{t('quotes.send_code')}</Button>
                        </div>
                        <Field id="otp" label={t('quotes.otp')} error={form.errors.otp}>
                            <Input id="otp" dir="ltr" inputMode="numeric" value={form.data.otp} onChange={(event) => form.setData('otp', event.target.value)} />
                        </Field>
                        {form.errors.signature ? <p className="text-sm text-destructive">{form.errors.signature}</p> : null}
                        <Button type="submit">{t('quotes.sign')}</Button>
                        {scan_url ? <p className="text-sm text-muted">{t('quotes.scan')}</p> : null}
                    </form>
                )}
            </div>
        </GuestLayout>
    );
}
