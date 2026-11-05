import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { BrandLogo } from '@/components/brand';
import { LanguageSwitch } from '@/components/shell/LanguageSwitch';
import { ThemeToggle } from '@/components/shell/ThemeToggle';
import { LiveCluster, Nebula } from '@/components/shell/Nebula';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

export function GuestLayout({ children, mode = 'canvas' }: { children: ReactNode; mode?: 'canvas' | 'auth' }) {
    if (mode === 'auth') {
        return <AuthAtmosphere>{children}</AuthAtmosphere>;
    }

    return <GuestCanvas>{children}</GuestCanvas>;
}

function GuestCanvas({ children }: { children: ReactNode }) {
    const { t } = useI18n();

    return (
        <div className="orbital-stage relative min-h-dvh">
            <Nebula />
            <a href="#content" className="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-card focus:px-3 focus:py-2">
                {t('a11y.skip')}
            </a>
            <header className="relative z-10 flex items-center justify-between gap-3 px-4 py-4 sm:px-6">
                <BrandLogo variant="compact" tone="auto" height={40} />
                <div className="flex items-center gap-2">
                    <LanguageSwitch />
                    <ThemeToggle />
                </div>
            </header>
            <div id="content" className="relative z-10 mx-auto w-full max-w-3xl px-4 pb-12">
                <div className="auth-card px-5 py-6 sm:px-8">{children}</div>
            </div>
        </div>
    );
}

function AuthAtmosphere({ children }: { children: ReactNode }) {
    const { t } = useI18n();
    const appName = usePage<SharedProps>().props.app?.name ?? 'FIBES';
    const year = new Date().getFullYear();

    return (
        <div className="orbital-stage relative min-h-dvh">
            <Nebula />
            <a href="#content" className="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-card focus:px-3 focus:py-2">
                {t('a11y.skip')}
            </a>
            <header className="relative z-10 flex items-center justify-end gap-2 px-4 py-4 sm:px-6">
                <LiveCluster />
                <LanguageSwitch />
                <ThemeToggle />
            </header>
            <div className="relative z-10 flex min-h-[calc(100dvh-4.5rem)] items-center justify-center px-4 pb-10">
                <div className="auth-card w-full max-w-[440px] px-6 py-8 sm:px-8">
                    <div className="flex justify-center">
                        <BrandLogo variant="full" tone="auto" height={92} animated />
                    </div>
                    <div id="content" className="mt-6">
                        {children}
                    </div>
                    <p className="mt-8 text-center text-2xs text-faint">
                        © {year} {appName} · {t('auth.rights')} · {t('auth.secure')}
                    </p>
                </div>
            </div>
        </div>
    );
}
