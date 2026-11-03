import { Head } from '@inertiajs/react';
import { AppShell } from '@/components/shell/AppShell';
import { TopologyCanvas } from '@/components/topology/TopologyCanvas';
import { useI18n } from '@/lib/i18n';
import { statusCounts, type TopologyProvider } from '@/lib/providerTopology';

const DOT = {
    active: 'bg-[#1d8656]',
    recent: 'bg-[#e9953f]',
    error: 'bg-[#de5539]',
} as const;

export default function TopologyIndex({ providers }: { providers: TopologyProvider[] }) {
    const { t } = useI18n();
    const counts = statusCounts(providers);
    const sample = providers.some((provider) => provider.sample);

    return (
        <AppShell>
            <Head title={t('topology.title')} />
            <section className="card overflow-hidden" data-testid="topology-page">
                <header className="flex flex-wrap items-start justify-between gap-4 px-5 py-4">
                    <div className="min-w-0">
                        <h1 className="text-xl font-semibold tracking-tight">{t('topology.title')}</h1>
                        <p className="mt-1 text-sm text-muted tabular" data-testid="topology-summary">
                            {t('topology.summary', counts)}
                        </p>
                        {sample ? (
                            <p className="mt-1 max-w-xl text-xs text-faint" data-testid="topology-sample-note">
                                {t('topology.sample')}
                            </p>
                        ) : null}
                    </div>
                    <ul className="flex items-center gap-4 text-sm text-muted" aria-label={t('topology.legend')}>
                        {(['active', 'recent', 'error'] as const).map((status) => (
                            <li key={status} className="flex items-center gap-1.5">
                                <span className={`size-2 rounded-full ${DOT[status]}`} />
                                {t(`topology.${status}`)}
                            </li>
                        ))}
                    </ul>
                </header>
                <ul className="sr-only">
                    {providers.map((provider) => {
                        const key = `topology.p_${provider.key}`;
                        const translated = t(key);
                        const label = translated === key ? provider.key : translated;

                        return (
                            <li key={provider.key}>
                                {label}, {t(`topology.${provider.status}`)}
                                {provider.sample ? `, ${t('topology.sample_mark')}` : ''}
                            </li>
                        );
                    })}
                </ul>
                <div className="h-[min(740px,calc(100dvh-22rem))] min-h-[440px] border-t border-line">
                    <TopologyCanvas providers={providers} />
                </div>
            </section>
        </AppShell>
    );
}
