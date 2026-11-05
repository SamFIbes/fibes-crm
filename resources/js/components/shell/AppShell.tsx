import { usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { router } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { page as pageMotion, prefersReducedMotion } from '@/lib/motion';
import { useI18n } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';
import { Nebula } from './Nebula';
import { Sidebar } from './Sidebar';
import { TopBar } from './TopBar';

const STORAGE_KEY = 'fibes-sidebar';

export function AppShell({ children }: { children: ReactNode }) {
    const inertia = usePage<SharedProps>();
    const { t } = useI18n();
    const [collapsed, setCollapsed] = useState(false);
    const [mobileOpen, setMobileOpen] = useState(false);
    const reduce = prefersReducedMotion();

    useEffect(() => {
        setCollapsed(localStorage.getItem(STORAGE_KEY) === 'collapsed');
    }, []);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            if (target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) {
                return;
            }
            if ((event.key === 't' || event.key === 'T') && !event.metaKey && !event.ctrlKey && inertia.props.auth.abilities?.tickets_create) {
                event.preventDefault();
                router.visit('/tickets?new=1');
            }
            if (event.key === 'F2' && inertia.props.auth.abilities?.calls) {
                event.preventDefault();
                router.visit('/calls');
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [inertia.props.auth.abilities]);

    const toggle = () => {
        setCollapsed((current) => {
            const next = !current;
            localStorage.setItem(STORAGE_KEY, next ? 'collapsed' : 'expanded');

            return next;
        });
    };

    return (
        <div className="relative min-h-dvh">
            <Nebula />
            <a href="#content" className="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-[80] focus:rounded-lg focus:bg-card focus:px-3 focus:py-2">
                {t('a11y.skip')}
            </a>
            <Sidebar collapsed={collapsed} mobileOpen={mobileOpen} onToggle={toggle} onClose={() => setMobileOpen(false)} />
            <div className={cn('relative z-10 transition-[padding] duration-300', collapsed ? 'lg:ps-[80px]' : 'lg:ps-[248px]')}>
                <div className="mx-auto max-w-[1640px] px-4 pb-10 sm:px-6">
                    <TopBar onOpenMenu={() => setMobileOpen(true)} />
                    {inertia.props.auth.view_as ? (
                        <p className="mb-3 flex items-center justify-between gap-3 rounded-tile border border-line bg-card px-4 py-2 text-sm">
                            <span>{t('admin.viewing_as', { name: inertia.props.auth.view_as.name })}</span>
                            <button type="button" className="font-semibold text-accent-ink" onClick={() => router.delete('/admin/view-as')}>
                                {t('admin.stop_viewing')}
                            </button>
                        </p>
                    ) : null}
                    {typeof inertia.props.security?.two_factor_grace_days === 'number' && inertia.props.security.two_factor_grace_days > 0 ? (
                        <p className="mb-3 rounded-tile border border-line bg-card px-4 py-2 text-sm">{t('security.grace_banner', { days: inertia.props.security.two_factor_grace_days })}</p>
                    ) : null}
                    <main id="content">
                        <AnimatePresence mode="wait" initial={false}>
                            <motion.div key={inertia.url} variants={reduce ? undefined : pageMotion} initial={reduce ? false : 'initial'} animate="enter" exit="exit">
                                {children}
                            </motion.div>
                        </AnimatePresence>
                    </main>
                </div>
            </div>
        </div>
    );
}
