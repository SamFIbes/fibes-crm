import { createInertiaApp, usePage, type ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { useEffect, type ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { FlashListener, Toaster } from '@/components/feedback/Toaster';
import { I18nProvider, useI18n } from '@/lib/i18n';
import { ThemeProvider } from '@/lib/theme';
import type { SharedProps } from '@/types';
import '../css/app.css';
import 'sonner/dist/styles.css';

function DocumentAttributes() {
    const { locale, dir } = useI18n();

    useEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = dir;
    }, [locale, dir]);

    return null;
}

function Providers({ children }: { children: ReactNode }) {
    const { props } = usePage<SharedProps>();

    return (
        <I18nProvider locale={props.locale ?? 'en'} dir={props.dir ?? 'ltr'} translations={props.translations ?? {}}>
            <ThemeProvider>
                <DocumentAttributes />
                <FlashListener />
                <Toaster />
                {children}
            </ThemeProvider>
        </I18nProvider>
    );
}

createInertiaApp({
    title: (title) => (title ? `${title} · FIBES` : 'FIBES'),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob(['./Pages/**/*.tsx', '!./Pages/**/*.test.tsx']),
        ) as Promise<ResolvedComponent>,
    setup({ el, App, props }) {
        if (!el) {
            return;
        }

        createRoot(el).render(
            <App {...props}>
                {({ Component, props: pageProps, key }) => (
                    <Providers>
                        <Component key={key} {...pageProps} />
                    </Providers>
                )}
            </App>,
        );
    },
    progress: {
        color: '#66C48F',
        showSpinner: false,
    },
});
