import { Head } from '@inertiajs/react';
import { OnboardingBoard } from '@/components/onboarding/OnboardingBoard';
import { AppShell } from '@/components/shell/AppShell';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { BoardFilters } from '@/lib/onboarding';

export default function OnboardingIndex({
    can_edit,
    can_manage,
    stale_days,
    filters,
}: {
    can_edit: boolean;
    can_manage: boolean;
    stale_days: number;
    filters: Partial<BoardFilters>;
}) {
    const { t } = useI18n();

    return (
        <AppShell>
            <Head title={t('onboarding.title')} />
            <div data-testid="onboarding-page">
                <PageHeader title={t('onboarding.title')} sub={t('onboarding.subtitle')} />
                <OnboardingBoard
                    initialFilters={filters}
                    initialCanEdit={can_edit}
                    initialCanManage={can_manage}
                    initialStaleDays={stale_days}
                />
            </div>
        </AppShell>
    );
}
