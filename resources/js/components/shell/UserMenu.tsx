import { router, usePage } from '@inertiajs/react';
import { LogOut, Monitor, Shield } from 'lucide-react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useI18n } from '@/lib/i18n';
import { initials } from '@/lib/utils';
import type { SharedProps } from '@/types';

export function UserMenu() {
    const { auth } = usePage<SharedProps>().props;
    const { t } = useI18n();
    const user = auth.user;

    if (!user) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                data-testid="user-menu"
                className="inline-flex h-[46px] items-center gap-2 rounded-full border border-line bg-card px-1.5 pe-3 text-sm font-semibold shadow-card"
            >
                <span className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-good-bg text-xs font-bold text-good">
                    {initials(user.name)}
                </span>
                <span className="hidden max-w-32 truncate text-start md:inline">{user.name}</span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuLabel>
                    <span className="block text-sm font-medium text-foreground">{user.name}</span>
                    <span className="mt-0.5 block truncate text-xs" dir="ltr">
                        {user.email}
                    </span>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => router.visit('/account/security')}>
                    <Shield className="h-4 w-4" />
                    {t('user_menu.account')}
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => router.visit('/account/sessions')}>
                    <Monitor className="h-4 w-4" />
                    {t('user_menu.sessions')}
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => router.post('/logout')}>
                    <LogOut className="h-4 w-4" />
                    {t('user_menu.logout')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
