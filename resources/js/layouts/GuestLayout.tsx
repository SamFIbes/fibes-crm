import { motion, useReducedMotion } from 'framer-motion';
import type { ReactNode } from 'react';
import { ParticleField } from '@/components/auth/ParticleField';
import { BrandLogo } from '@/components/brand';
import { LanguageSwitch } from '@/components/shell/LanguageSwitch';
import { ThemeToggle } from '@/components/shell/ThemeToggle';
import { useI18n } from '@/lib/i18n';

export function GuestLayout({ children }: { children: ReactNode }) {
    const reduce = useReducedMotion();
    const { t } = useI18n();

    return (
        <div className="guest-shell relative isolate min-h-dvh overflow-x-clip">
            <div aria-hidden className="guest-grid pointer-events-none absolute inset-0">
                <div className="guest-hero-bg relative hidden overflow-hidden lg:block">
                    <div className="guest-aurora absolute inset-0">
                        <span />
                        <span />
                        <span />
                    </div>
                    <svg className="absolute inset-0 size-full opacity-[0.08]">
                        <defs>
                            <pattern id="guest-dots" width="22" height="22" patternUnits="userSpaceOnUse">
                                <circle cx="1" cy="1" r="1" fill="#fff" />
                            </pattern>
                        </defs>
                        <rect width="100%" height="100%" fill="url(#guest-dots)" />
                    </svg>
                    <div className="guest-orbit">
                        <span />
                        <span />
                    </div>
                </div>
            </div>
            <ParticleField />
            <div className="guest-grid relative z-10">
                <aside className="relative hidden text-white lg:flex lg:flex-col lg:justify-between lg:px-12 lg:py-12 xl:px-16">
                    <div dir="ltr" className="text-sm font-semibold uppercase tracking-[0.18em] text-white/60">
                        FIBES CRM
                    </div>
                    <div className="my-auto max-w-xl py-10">
                        <BrandLogo variant="full" tone="dark" height={128} animated />
                        <motion.p
                            initial={reduce ? false : { opacity: 0, y: 12 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: reduce ? 0 : 0.15, duration: reduce ? 0 : 0.55, ease: [0.22, 1, 0.36, 1] }}
                            className="mt-8 max-w-md text-display font-semibold leading-[1.08] tracking-tight"
                        >
                            {t('auth.headline')} <span className="text-gradient">{t('auth.headline_accent')}</span>
                        </motion.p>
                        <div className="guest-rule" aria-hidden />
                    </div>
                    <p dir="ltr" className="text-sm font-medium tracking-wide text-white/55">
                        FIBES
                    </p>
                </aside>
                <div className="guest-pane relative flex min-h-dvh flex-col px-4 sm:px-8">
                    <a href="#content" className="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-card focus:px-3 focus:py-2">
                        {t('a11y.skip')}
                    </a>
                    <header className="flex items-center justify-end gap-1">
                        <LanguageSwitch />
                        <ThemeToggle />
                    </header>
                    <div id="content" className="m-auto w-full max-w-md py-6">
                        <div className="mb-8 lg:hidden">
                            <BrandLogo variant="full" tone="auto" height={72} animated />
                        </div>
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}
