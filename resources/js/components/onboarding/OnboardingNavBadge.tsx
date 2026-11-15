import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useI18n } from '@/lib/i18n';
import { jsonFetch, type Attention } from '@/lib/onboarding';

export function OnboardingNavBadge({ compact = false }: { compact?: boolean }) {
    const { t } = useI18n();
    const { auth } = usePage<{ auth: { nav?: string[] } }>().props;
    const permitted = auth?.nav?.includes('onboarding') ?? false;
    const [attention, setAttention] = useState<Attention | null>(null);

    useEffect(() => {
        if (!permitted) {
            setAttention(null);
            return;
        }

        let live = true;
        let request = 0;
        const refresh = () => {
            if (document.visibilityState !== 'visible') return;
            const current = ++request;
            void jsonFetch<Attention>('/onboarding/attention')
                .then((result) => {
                    if (live && current === request) setAttention(result);
                })
                .catch(() => {
                    if (live && current === request) setAttention(null);
                });
        };
        const onVisibility = () => {
            if (document.visibilityState === 'visible') refresh();
        };
        refresh();
        const interval = window.setInterval(refresh, 5 * 60 * 1000);
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            live = false;
            window.clearInterval(interval);
            window.removeEventListener('focus', refresh);
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [permitted]);

    if (!permitted || !attention || attention.total <= 0) return null;

    return (
        <span
            className="inline-flex items-center gap-1"
            aria-label={`${attention.total} ${t('onboarding.need_followup')}, ${attention.mine} ${t('onboarding.yours')}`}
        >
            <span aria-hidden="true" className={compact ? 'min-w-5 rounded-full bg-bad px-1.5 py-0.5 text-center text-2xs font-semibold text-white tabular' : 'rounded-full bg-bad px-2 py-1 text-2xs font-semibold text-white tabular'}>{attention.total}</span>
            {attention.mine > 0 && !compact ? <span aria-hidden="true" className="rounded-full px-2 py-1 text-2xs font-semibold ring-1 ring-line tabular">{attention.mine}</span> : null}
        </span>
    );
}
