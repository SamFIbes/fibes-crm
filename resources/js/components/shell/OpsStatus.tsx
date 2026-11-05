import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useI18n } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

export function PulseChip() {
    const { t } = useI18n();
    const nav = usePage<SharedProps>().props.auth.nav ?? [];
    const live = nav.includes('network');
    const className = 'inline-flex h-10 items-center gap-2 rounded-full border border-[var(--glass-border)] bg-[var(--glass)] px-3 text-sm font-medium text-text backdrop-blur-md';
    const body = (
        <>
            <span className={cn('size-2 rounded-full', live ? 'pulse-live bg-mint' : 'bg-faint')} />
            {t('shell.pulse')}
        </>
    );

    if (!live) {
        return (
            <span className={cn(className, 'hidden text-muted sm:inline-flex')} data-testid="network-pulse" title={t('shell.pulse_quiet')}>
                {body}
            </span>
        );
    }

    return (
        <Link href="/network" className={cn(className, 'hidden sm:inline-flex')} data-testid="network-pulse">
            {body}
        </Link>
    );
}

export function OpsClock() {
    const { locale } = useI18n();
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const id = window.setInterval(() => setNow(new Date()), 1000);

        return () => window.clearInterval(id);
    }, []);

    const language = locale === 'ar' ? 'ar' : 'en';
    const time = new Intl.DateTimeFormat(language, { hour: 'numeric', minute: '2-digit', second: '2-digit' }).format(now);
    const date = new Intl.DateTimeFormat(language, { month: 'short', day: 'numeric', year: 'numeric' }).format(now);

    return (
        <div className="hidden text-end leading-tight lg:block" data-testid="ops-clock">
            <div className="text-sm font-semibold tabular-nums">{time}</div>
            <div className="text-2xs text-muted">{date}</div>
        </div>
    );
}
