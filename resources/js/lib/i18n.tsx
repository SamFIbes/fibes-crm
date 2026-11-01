import { createContext, useContext, useMemo, type ReactNode } from 'react';
import type { Locale, TranslationTree } from '@/types';

type I18nValue = {
    locale: Locale;
    dir: 'ltr' | 'rtl';
    t: (key: string, replace?: Record<string, string | number>) => string;
};

const I18nContext = createContext<I18nValue | null>(null);

export function directionFor(locale: string): 'ltr' | 'rtl' {
    return locale === 'ar' ? 'rtl' : 'ltr';
}

export function translate(
    tree: unknown,
    key: string,
    replace: Record<string, string | number> = {},
): string {
    const value = key.split('.').reduce<unknown>((node, part) => {
        if (node && typeof node === 'object' && part in (node as Record<string, unknown>)) {
            return (node as Record<string, unknown>)[part];
        }

        return undefined;
    }, tree);

    if (typeof value !== 'string') {
        return key;
    }

    return value.replace(/:([A-Za-z0-9_]+)/g, (_, token: string) => {
        const replacement = replace[token];

        return replacement === undefined ? `:${token}` : String(replacement);
    });
}

export function I18nProvider({
    locale,
    dir,
    translations,
    children,
}: {
    locale: Locale;
    dir: 'ltr' | 'rtl';
    translations: TranslationTree;
    children: ReactNode;
}) {
    const value = useMemo<I18nValue>(
        () => ({
            locale,
            dir,
            t: (key, replace) => translate(translations, key, replace),
        }),
        [locale, dir, translations],
    );

    return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nValue {
    const value = useContext(I18nContext);

    if (!value) {
        throw new Error('useI18n must be used within I18nProvider');
    }

    return value;
}
