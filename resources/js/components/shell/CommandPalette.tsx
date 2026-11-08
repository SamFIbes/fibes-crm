import { router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { CommandDialog, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { filterCommands, type CommandItem as Item } from '@/lib/commands';
import { useI18n } from '@/lib/i18n';
import { navGroups } from '@/components/shell/nav';
import { useTheme } from '@/lib/theme';
import type { SharedProps } from '@/types';

function shortcutLabel(): string {
    if (typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgent)) {
        return '⌘K';
    }

    return 'Ctrl+K';
}

export function CommandPalette() {
    const { t } = useI18n();
    const { setTheme } = useTheme();
    const { auth } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const shortcut = shortcutLabel();

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setOpen((current) => !current);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const items = useMemo<Item[]>(() => {
        const close = () => setOpen(false);
        const allowed = new Set(auth.nav ?? []);
        const nav: Item[] = navGroups
            .flatMap((group) => group.items)
            .filter((item) => allowed.has(item.key))
            .map((item) => ({
                id: `nav-${item.key}`,
                label: t(`nav.${item.key}`),
                group: 'navigation' as const,
                keywords: `${item.key} ${item.href}`,
                perform: () => {
                    close();
                    router.visit(item.href);
                },
            }));

        if (auth.abilities?.tickets_create) {
            nav.push({
                id: 'new-ticket',
                label: t('tickets.new'),
                group: 'navigation',
                keywords: 'new ticket create تذكرة',
                perform: () => {
                    close();
                    router.visit('/tickets?new=1');
                },
            });
        }

        if (auth.abilities?.calls) {
            nav.push({
                id: 'calls',
                label: t('calls.open'),
                group: 'navigation',
                keywords: 'call phone whatsapp مكالمة',
                perform: () => {
                    close();
                    router.visit('/calls');
                },
            });
        }

        if (auth.abilities?.customers_create) {
            nav.push({
                id: 'new-customer',
                label: t('customers.new'),
                group: 'navigation',
                keywords: 'new customer create عميل',
                perform: () => {
                    close();
                    router.visit('/customers?new=1');
                },
            });
        }

        const preferences: Item[] = [
            {
                id: 'theme-light',
                label: t('theme.switch_to_light'),
                group: 'preferences',
                keywords: 'theme light dark',
                perform: () => {
                    setTheme('light');
                    close();
                },
            },
            {
                id: 'theme-dark',
                label: t('theme.switch_to_dark'),
                group: 'preferences',
                keywords: 'theme light dark',
                perform: () => {
                    setTheme('dark');
                    close();
                },
            },
            {
                id: 'lang-en',
                label: t('language.en'),
                group: 'preferences',
                keywords: 'language english',
                perform: () => {
                    close();
                    router.post('/locale', { locale: 'en' }, { preserveScroll: true });
                },
            },
            {
                id: 'lang-ar',
                label: t('language.ar'),
                group: 'preferences',
                keywords: 'language arabic العربية',
                perform: () => {
                    close();
                    router.post('/locale', { locale: 'ar' }, { preserveScroll: true });
                },
            },
        ];

        const account: Item[] = auth.user
            ? [
                  {
                      id: 'security',
                      label: t('user_menu.account'),
                      group: 'account',
                      keywords: 'security 2fa password',
                      perform: () => {
                          close();
                          router.visit('/account/security');
                      },
                  },
                  {
                      id: 'logout',
                      label: t('user_menu.logout'),
                      group: 'account',
                      keywords: 'logout sign out',
                      perform: () => {
                          close();
                          router.post('/logout');
                      },
                  },
              ]
            : [];

        return [...nav, ...preferences, ...account];
    }, [auth.abilities, auth.nav, auth.user, setTheme, t]);

    const filtered = filterCommands(items, query);
    const groups = ['navigation', 'preferences', 'account'] as const;

    return (
        <>
            <button
                type="button"
                data-testid="command-trigger"
                onClick={() => {
                    setQuery('');
                    setOpen(true);
                }}
                className="inline-flex h-[46px] w-full max-w-[460px] items-center gap-2.5 rounded-full border border-line bg-card px-4 text-sm font-medium text-muted shadow-card transition-colors duration-200 hover:border-line-2"
            >
                <Search className="h-4 w-4 shrink-0" aria-hidden="true" />
                <span className="flex-1 truncate text-start">{t('command.placeholder')}</span>
                <kbd className="hidden rounded border border-border bg-secondary px-1.5 py-0.5 text-[10px] font-medium sm:inline">{shortcut}</kbd>
            </button>
            <CommandDialog
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    if (!next) {
                        setQuery('');
                    }
                }}
                title={t('command.hint')}
                description={t('command.placeholder')}
            >
                <CommandInput
                    autoFocus
                    value={query}
                    onValueChange={setQuery}
                    placeholder={t('command.placeholder')}
                    aria-label={t('command.placeholder')}
                />
                <CommandList>
                    <CommandEmpty>{t('command.empty')}</CommandEmpty>
                    {groups.map((group) => {
                        const groupItems = filtered.filter((item) => item.group === group);

                        if (groupItems.length === 0) {
                            return null;
                        }

                        return (
                            <CommandGroup key={group} heading={t(`command.${group}`)}>
                                {groupItems.map((item) => (
                                    <CommandItem key={item.id} value={item.id} onSelect={item.perform}>
                                        {item.label}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        );
                    })}
                </CommandList>
            </CommandDialog>
        </>
    );
}
