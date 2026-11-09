import { Link } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { useI18n } from '@/lib/i18n';

export type SectionLink = {
    key: string;
    href: string;
    icon: ComponentType<{ className?: string; 'aria-hidden'?: boolean | 'true' }>;
};

export function SectionGrid({ links }: { links: SectionLink[] }) {
    const { t } = useI18n();

    if (links.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="fd-go-to-heading" data-testid="dashboard-sections" className="card mt-8 p-6">
            <h2 id="fd-go-to-heading" className="text-lg font-semibold">{t('dashboard.go_to')}</h2>
            <ul className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                {links.map((item) => {
                    const Icon = item.icon;

                    return (
                        <li key={item.key} className="flex min-w-0">
                            <Link
                                href={item.href}
                                data-nav={item.key}
                                className="flex min-h-11 min-w-0 flex-1 items-center gap-2 rounded-tile border border-line px-3 py-2 text-sm font-semibold hover:border-line-2 hover:bg-well focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <Icon className="size-4 shrink-0 text-text" aria-hidden="true" />
                                <span className="truncate">{t(`nav.${item.key}`)}</span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
