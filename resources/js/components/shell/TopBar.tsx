import { Menu } from 'lucide-react';
import { CommandPalette } from '@/components/shell/CommandPalette';
import { LanguageSwitch } from '@/components/shell/LanguageSwitch';
import { NotificationCenter } from '@/components/shell/NotificationCenter';
import { OpsClock, PulseChip } from '@/components/shell/OpsStatus';
import { ThemeToggle } from '@/components/shell/ThemeToggle';
import { UserMenu } from '@/components/shell/UserMenu';
import { useI18n } from '@/lib/i18n';

export function TopBar({ onOpenMenu }: { onOpenMenu: () => void }) {
    const { t } = useI18n();

    return (
        <header className="sticky top-3 z-30 mb-3 flex h-14 items-center gap-2 px-1 md:gap-3">
            <button
                type="button"
                className="inline-flex h-10 w-10 items-center justify-center rounded-full border border-[var(--glass-border)] bg-[var(--glass)] text-foreground backdrop-blur-md md:hidden"
                onClick={onOpenMenu}
                aria-label={t('nav.open_menu')}
            >
                <Menu className="h-5 w-5" />
            </button>
            <div className="min-w-0 flex-1">
                <CommandPalette />
            </div>
            <PulseChip />
            <OpsClock />
            <NotificationCenter />
            <LanguageSwitch />
            <ThemeToggle />
            <UserMenu />
        </header>
    );
}
