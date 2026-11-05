import { Link, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { ChevronsLeft } from 'lucide-react';
import { BrandLogo, BrandMark } from '@/components/brand';
import { spring } from '@/lib/motion';
import { useI18n } from '@/lib/i18n';
import { cn, initials } from '@/lib/utils';
import type { SharedProps } from '@/types';
import { navGroups } from './nav';

export function Sidebar({
    collapsed,
    mobileOpen,
    onToggle,
    onClose,
}: {
    collapsed: boolean;
    mobileOpen: boolean;
    onToggle: () => void;
    onClose: () => void;
}) {
    const page = usePage<SharedProps>();
    const { t, dir } = useI18n();
    const path = page.url.split('?')[0];
    const allowed = new Set(page.props.auth.nav ?? ['dashboard']);
    const groups = navGroups
        .map((group) => ({ ...group, items: group.items.filter((item) => allowed.has(item.key)) }))
        .filter((group) => group.items.length > 0);
    const compact = collapsed && !mobileOpen;
    const user = page.props.auth.user;

    return (
        <>
            <AnimatePresence>
                {mobileOpen ? (
                    <motion.button type="button" aria-label={t('nav.close_menu')} className="fixed inset-0 z-40 bg-black/50 lg:hidden" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onClick={onClose} />
                ) : null}
            </AnimatePresence>
            <motion.aside
                aria-label={t('a11y.main')}
                animate={{ width: compact ? 80 : 248 }}
                transition={spring}
                className={cn(
                    'fixed inset-y-0 start-0 z-50 flex flex-col overflow-hidden border-e border-white/10 bg-side text-side-text shadow-[16px_0_50px_-28px_rgb(0_0_0/0.75)]',
                    mobileOpen ? 'translate-x-0' : 'max-lg:ltr:-translate-x-full max-lg:rtl:translate-x-full',
                )}
            >
                <div className="pointer-events-none absolute -top-20 -end-16 size-56 rounded-full bg-[radial-gradient(circle,rgb(117_205_200/0.22),transparent_68%)]" />
                <div className={cn('relative flex items-center gap-2 ps-3 pe-8', compact ? 'h-[76px] justify-center px-0' : 'min-h-[84px] py-3')}>
                    <Link href="/dashboard" aria-label="FIBES" onClick={onClose} dir="ltr" className="relative shrink-0">
                        {compact ? <BrandMark size={40} glow /> : <BrandLogo variant="compact" tone="dark" height={44} label="FIBES" />}
                    </Link>
                </div>
                {compact ? null : <div className="relative -mt-2 px-4 pb-2 text-2xs font-semibold uppercase tracking-[0.16em] text-side-dim">{t('shell.desk')}</div>}
                <nav className="relative flex-1 space-y-4 overflow-y-auto px-2.5 pt-1">
                    {groups.map((group) => (
                        <div key={group.label}>
                            {compact ? <div className="mx-auto mb-2 h-px w-6 bg-side-line" /> : <div className="px-3 pb-1.5 text-2xs font-bold uppercase tracking-[0.12em] text-side-faint">{t(group.label)}</div>}
                            <ul className="space-y-0.5">
                                {group.items.map((item) => {
                                    const active = path === item.href || (item.href !== '/dashboard' && path.startsWith(item.href));
                                    const Icon = item.icon;

                                    return (
                                        <li key={item.href}>
                                            <Link
                                                href={item.href}
                                                onClick={onClose}
                                                aria-current={active ? 'page' : undefined}
                                                title={compact ? t(`nav.${item.key}`) : undefined}
                                                data-nav={item.key}
                                                className={cn(
                                                    'relative flex h-10 items-center gap-3 rounded-[12px] px-3 text-md font-medium',
                                                    active ? 'rail-active' : 'text-side-dim hover:bg-side-hover hover:text-white',
                                                    compact && 'justify-center px-0',
                                                )}
                                            >
                                                <Icon className={cn('relative size-[18px] shrink-0', active && 'text-navy')} />
                                                {compact ? null : <span className="relative truncate">{t(`nav.${item.key}`)}</span>}
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    ))}
                </nav>
                {user ? (
                    <div className={cn('relative flex items-center gap-2 border-t border-side-line px-3 py-3', compact && 'justify-center px-2')}>
                        <span className="grid size-9 shrink-0 place-items-center rounded-full bg-brand-gradient text-xs font-bold text-navy">{initials(user.name)}</span>
                        {compact ? null : (
                            <div className="min-w-0">
                                <div className="truncate text-sm font-semibold">{user.name}</div>
                                <div className="truncate text-2xs capitalize text-side-dim">{user.role.replaceAll('_', ' ')}</div>
                            </div>
                        )}
                    </div>
                ) : null}
                <button
                    type="button"
                    onClick={onToggle}
                    aria-pressed={collapsed}
                    aria-label={collapsed ? t('nav.expand') : t('nav.collapse')}
                    data-testid="sidebar-collapse"
                    className="absolute top-6 end-1 hidden size-7 place-items-center rounded-lg text-side-dim hover:bg-white/10 hover:text-white lg:grid"
                >
                    <ChevronsLeft className={cn('size-4 transition-transform', collapsed !== (dir === 'rtl') && 'rotate-180')} />
                </button>
            </motion.aside>
        </>
    );
}
