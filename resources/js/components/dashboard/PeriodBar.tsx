import { CalendarDays } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { formatPeriod } from '@/lib/dashboard';
import { useI18n } from '@/lib/i18n';

type PeriodField = 'from' | 'to';

const inputClass =
    'h-11 min-w-0 rounded-full border-0 bg-transparent px-2 text-sm text-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

export function PeriodBar({
    from,
    to,
    applied,
    onChange,
    onSubmit,
}: {
    from: string;
    to: string;
    /** The period the figures on screen are for; the label shows this, not unsaved edits. */
    applied?: { from: string; to: string };
    onChange: (field: PeriodField, value: string) => void;
    onSubmit: () => void;
}) {
    const { t, locale } = useI18n();

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        onSubmit();
    };

    return (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-4" data-testid="dashboard-period">
            <p className="inline-flex min-h-11 items-center gap-2 text-sm text-muted">
                <CalendarDays className="size-4 shrink-0" aria-hidden="true" />
                <span className="sr-only">{t('dashboard.period')}</span>
                <span className="font-semibold text-text" data-testid="period-label">{formatPeriod(applied?.from ?? from, applied?.to ?? to, locale)}</span>
            </p>
            <form
                onSubmit={submit}
                className="flex flex-wrap items-center gap-1 rounded-[28px] border border-line bg-card p-1 sm:rounded-full"
            >
                <label className="flex items-center gap-2 ps-3 text-xs text-muted">
                    <span>{t('reports.from')}</span>
                    <input className={inputClass} type="date" value={from} onChange={(event) => onChange('from', event.target.value)} />
                </label>
                <span className="hidden h-6 w-px bg-line sm:block" aria-hidden="true" />
                <label className="flex items-center gap-2 ps-3 text-xs text-muted">
                    <span>{t('reports.to')}</span>
                    <input className={inputClass} type="date" value={to} onChange={(event) => onChange('to', event.target.value)} />
                </label>
                <Button type="submit">{t('reports.apply')}</Button>
            </form>
        </div>
    );
}
