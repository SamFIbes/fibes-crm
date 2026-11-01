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
        <div role="group" aria-label={t('language.switch')} className="inline-flex rounded-lg border border-border bg-card p-0.5">
            {(['en', 'ar'] as const).map((code) => (
                <button
                    key={code}
                    type="button"
                    data-testid={`locale-${code}`}
                    aria-pressed={locale === code}
                    onClick={() => choose(code)}
                    className={cn(
                        'rounded-md px-2.5 py-1 text-xs font-medium transition-colors duration-200',
                        locale === code ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {code === 'en' ? 'EN' : 'ع'}
                </button>
            ))}
        </div>
    );
}
