import { useI18n } from '@/lib/i18n';

/** Soft nebula behind the 2055 shell. Motion is CSS and stops under prefers-reduced-motion. */
export function Nebula() {
    return (
        <div className="nebula" aria-hidden>
            <span className="nebula-dust" />
            <span className="nebula-orb nebula-orb-mint" />
            <span className="nebula-orb nebula-orb-aqua" />
            <span className="nebula-orb nebula-orb-deep" />
        </div>
    );
}

export function LiveCluster() {
    const { t } = useI18n();

    return (
        <div className="hidden items-center gap-2 text-2xs font-semibold tracking-[0.16em] text-muted sm:flex" dir="ltr" data-testid="live-cluster">
            <span className="flex items-center gap-1" aria-hidden>
                {Array.from({ length: 7 }, (_, index) => (
                    <span key={index} className="size-1.5 rounded-full bg-aqua" style={{ opacity: 0.28 + index * 0.1 }} />
                ))}
            </span>
            <svg viewBox="0 0 72 16" className="h-4 w-16 text-aqua" aria-hidden>
                <polyline fill="none" stroke="currentColor" strokeWidth="1.4" strokeLinejoin="round" strokeLinecap="round" points="0,10 8,10 12,3 16,14 20,6 26,10 72,10" />
            </svg>
            <span>{t('auth.live')}</span>
        </div>
    );
}
