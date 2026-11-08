import { Menu } from 'lucide-react';
import { CommandPalette } from '@/components/shell/CommandPalette';
import { LanguageSwitch } from '@/components/shell/LanguageSwitch';
import { NotificationCenter } from '@/components/shell/NotificationCenter';
import { ThemeToggle } from '@/components/shell/ThemeToggle';
import { UserMenu } from '@/components/shell/UserMenu';
import { useI18n } from '@/lib/i18n';

export function TopBar({ onOpenMenu }: { onOpenMenu: () => void }) {
    const { t } = useI18n();

    return (
        <header className="sticky top-0 z-30 mb-5 flex min-w-0 flex-wrap items-center gap-2 bg-transparent">
            <button
                type="button"
                className="icon-btn md:hidden"
                onClick={onOpenMenu}
                aria-label={t('nav.open_menu')}
            >
                <Menu className="h-5 w-5" />
            </button>
            <div className="min-w-0 flex-1">
                <CommandPalette />
            </div>
            <NotificationCenter />
            <LanguageSwitch />
            <ThemeToggle />
            <UserMenu />
        </header>
    );
}
