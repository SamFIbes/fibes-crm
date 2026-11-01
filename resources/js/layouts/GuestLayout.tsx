import { motion, useReducedMotion } from 'framer-motion';
import type { ReactNode } from 'react';
import { BrandLogo } from '@/components/brand';
import { LanguageSwitch } from '@/components/shell/LanguageSwitch';
import { ThemeToggle } from '@/components/shell/ThemeToggle';
import { useI18n } from '@/lib/i18n';

export function GuestLayout({ children }: { children: ReactNode }) {
    const reduce = useReducedMotion();
    const { t } = useI18n();

    return (
        <div className="grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
            <div className="relative hidden overflow-hidden bg-navy text-white lg:block">
                <div className="absolute inset-0 bg-[radial-gradient(60%_50%_at_20%_15%,rgb(102_196_143/0.45),transparent_70%),radial-gradient(55%_45%_at_85%_80%,rgb(120_208_224/0.35),transparent_70%)]" />
                {reduce ? null : (
                    <motion.div
                        aria-hidden
                        className="absolute -end-40 -top-40 size-[560px] rounded-full bg-brand-gradient opacity-20 blur-3xl"
                        animate={{ scale: [1, 1.12, 1], rotate: [0, 30, 0] }}
                        transition={{ duration: 14, repeat: Infinity, ease: 'easeInOut' }}
                    />
                )}
                <svg aria-hidden className="absolute inset-0 size-full opacity-[0.07]">
                    <defs>
                        <pattern id="dots" width="22" height="22" patternUnits="userSpaceOnUse">
                            <circle cx="1" cy="1" r="1" fill="#fff" />
                        </pattern>
                    </defs>
                    <rect width="100%" height="100%" fill="url(#dots)" />
                </svg>
                <div className="relative flex h-full flex-col p-12">
                    <div className="text-sm font-semibold uppercase tracking-[0.18em] text-white/55">FIBES CRM</div>
                    <div className="my-auto">
                        <BrandLogo variant="full" tone="dark" height={210} animated className="-ms-3" />
                        <motion.h1
                            initial={reduce ? false : { opacity: 0, y: 16 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: reduce ? 0 : 1.1, duration: reduce ? 0 : 0.7, ease: [0.22, 1, 0.36, 1] }}
                            className="mt-10 max-w-md text-display font-semibold leading-[1.05] tracking-tight"
                        >
                            {t('auth.headline')} <span className="text-gradient">{t('auth.headline_accent')}</span>
                        </motion.h1>
                    </div>
                </div>
            </div>
            <div className="relative flex min-h-dvh flex-col bg-bg p-6 sm:p-10">
                <a href="#content" className="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-card focus:px-3 focus:py-2">
                    {t('a11y.skip')}
                </a>
                <header className="flex items-center justify-end gap-1">
                    <LanguageSwitch />
                    <ThemeToggle />
                </header>
                <div id="content" className="m-auto w-full max-w-md py-6">
                    <div className="mb-8 lg:hidden">
                        <BrandLogo variant="full" tone="auto" height={84} animated className="-ms-2" />
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
