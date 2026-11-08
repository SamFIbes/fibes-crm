import { router } from '@inertiajs/react';
import { useI18n } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export function LanguageSwitch() {
    const { locale, t } = useI18n();

    const choose = (next: 'en' | 'ar') => {
        if (next === locale) {
            return;
        }

        router.post('/locale', { locale: next }, { preserveScroll: true });
    };

    return (
        <div role="group" aria-label={t('language.switch')} className="inline-flex h-11 min-h-11 items-center rounded-full border border-line bg-card p-1 shadow-card">
            {(['en', 'ar'] as const).map((code) => (
                <button
                    key={code}
                    type="button"
                    data-testid={`locale-${code}`}
                    aria-pressed={locale === code}
                    onClick={() => choose(code)}
                    className={cn(
                        'min-h-9 min-w-11 rounded-full px-2.5 text-xs font-semibold transition-colors duration-200',
                        locale === code ? 'bg-accent text-on-accent' : 'text-muted hover:text-text',
                    )}
                >
                    {code === 'en' ? 'EN' : 'ع'}
                </button>
            ))}
        </div>
    );
}
