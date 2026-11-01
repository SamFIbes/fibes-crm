import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/lib/i18n';

export function PageSkeleton() {
    const { t } = useI18n();

    return (
        <div className="mx-auto max-w-5xl" aria-busy="true" aria-live="polite" aria-label={t('a11y.loading')}>
            <Skeleton className="h-4 w-24" />
            <Skeleton className="mt-3 h-8 w-64 max-w-full" />
            <Skeleton className="mt-3 h-4 w-full max-w-xl" />
            <div className="mt-8 grid gap-4 md:grid-cols-2">
                <Skeleton className="h-40 rounded-2xl" />
                <Skeleton className="h-40 rounded-2xl" />
            </div>
        </div>
    );
}
