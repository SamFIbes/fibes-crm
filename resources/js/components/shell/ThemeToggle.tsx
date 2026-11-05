import { Moon, Sun } from 'lucide-react';
import { useI18n } from '@/lib/i18n';
import { useTheme } from '@/lib/theme';

export function ThemeToggle() {
    const { theme, toggle } = useTheme();
    const { t } = useI18n();
    const nextLabel = theme === 'dark' ? t('theme.switch_to_light') : t('theme.switch_to_dark');

    return (
        <button
            type="button"
            data-testid="theme-toggle"
            onClick={toggle}
            aria-label={nextLabel}
            title={nextLabel}
            className="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[var(--glass-border)] bg-[var(--glass)] text-foreground backdrop-blur-md transition-colors duration-200 hover:border-sky/40"
        >
            {theme === 'dark' ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
        </button>
    );
}
