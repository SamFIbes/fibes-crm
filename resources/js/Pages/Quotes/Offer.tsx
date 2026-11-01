import { Head, router, usePage } from '@inertiajs/react';
import { GuestLayout } from '@/layouts/GuestLayout';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Line = { description: string; qty: string; total: string };

export default function Offer({
    quote,
    accept_url,
    reject_url,
    can_answer,
}: {
    quote: { id: number; number: string | null; status: string; currency: string; total: string; valid_until: string | null; customer: string | null; lines: Line[] };
    accept_url: string;
    reject_url: string;
    can_answer: boolean;
}) {
    const { t } = useI18n();
    const page = usePage<SharedProps>();

    return (
        <GuestLayout>
            <Head title={t('quotes.public_title')} />
            <div data-testid="quote-offer" className="mx-auto w-full max-w-xl">
                <h1 className="text-2xl font-semibold">{t('quotes.public_title')}</h1>
                <p className="mt-1 text-sm text-muted" dir="ltr">{quote.number} · {quote.currency} {quote.total}</p>
                {page.props.flash.status ? <p className="mt-3 text-sm">{page.props.flash.status}</p> : null}
                <ul className="mt-4 space-y-2">
                    {quote.lines.map((line, index) => (
                        <li key={index} className="flex justify-between gap-3 text-sm">
                            <span>{line.description}</span>
                            <span dir="ltr">{line.total}</span>
                        </li>
                    ))}
                </ul>
                <p className="mt-4 text-sm text-muted">{t('quotes.document_intro')}</p>
                {can_answer ? (
                    <div className="mt-4 flex gap-2">
                        <Button type="button" onClick={() => router.post(accept_url)}>{t('quotes.accept')}</Button>
                        <Button type="button" variant="outline" onClick={() => router.post(reject_url)}>{t('quotes.reject')}</Button>
                    </div>
                ) : null}
            </div>
        </GuestLayout>
    );
}
