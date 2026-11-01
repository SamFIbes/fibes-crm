import { createContext, useContext, useMemo, useState, type ReactNode } from 'react';

export type ThemeChoice = 'light' | 'dark';

const STORAGE_KEY = 'fibes-theme';

type ThemeValue = {
    theme: ThemeChoice;
    setTheme: (theme: ThemeChoice) => void;
    toggle: () => void;
};

const ThemeContext = createContext<ThemeValue | null>(null);

export function preferredTheme(): ThemeChoice {
    if (typeof window === 'undefined') {
        return 'light';
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function applyTheme(theme: ThemeChoice): void {
    document.documentElement.classList.toggle('dark', theme === 'dark');
}

function initialTheme(): ThemeChoice {
    if (typeof document === 'undefined') {
        return 'light';
    }

    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

export function ThemeProvider({ children }: { children: ReactNode }) {
    const [theme, setThemeState] = useState<ThemeChoice>(initialTheme);

    const value = useMemo<ThemeValue>(
        () => ({
            theme,
            setTheme: (next) => {
                setThemeState(next);
                localStorage.setItem(STORAGE_KEY, next);
                applyTheme(next);
            },
            toggle: () => {
                const next = theme === 'dark' ? 'light' : 'dark';
                setThemeState(next);
                localStorage.setItem(STORAGE_KEY, next);
                applyTheme(next);
            },
        }),
        [theme],
    );

    return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeValue {
    const value = useContext(ThemeContext);

    if (!value) {
        throw new Error('useTheme must be used within ThemeProvider');
    }

    return value;
}
