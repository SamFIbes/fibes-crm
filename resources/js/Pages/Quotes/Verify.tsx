import { Head } from '@inertiajs/react';
import { GuestLayout } from '@/layouts/GuestLayout';
import { useI18n } from '@/lib/i18n';

export default function Verify({
    quote,
    sha256,
}: {
    quote: { number: string | null; status: string; total: string; currency: string; version: number };
    sha256: string | null;
}) {
    const { t } = useI18n();

    return (
        <GuestLayout>
            <Head title={t('quotes.verify_title')} />
            <div data-testid="quote-verify" className="mx-auto w-full max-w-xl">
                <h1 className="text-2xl font-semibold">{t('quotes.verify_title')}</h1>
                <p className="mt-2">{t('quotes.verify_ok')}</p>
                <p className="mt-3 text-sm" dir="ltr">{quote.number} · v{quote.version} · {quote.currency} {quote.total}</p>
                <p className="text-sm text-muted">{t(`quotes.status_${quote.status}`)}</p>
                {sha256 ? <p className="mt-3 break-all text-xs" dir="ltr">{sha256}</p> : null}
            </div>
        </GuestLayout>
    );
}
