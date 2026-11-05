import { Link, router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

export function NotificationCenter() {
    const { t } = useI18n();
    const page = usePage<SharedProps>();
    const notices = page.props.notifications ?? { unread: 0, items: [] };
    const [open, setOpen] = useState(false);

    useEffect(() => {
        const timer = window.setInterval(() => {
            router.reload({ only: ['notifications'] });
        }, 30000);

        return () => window.clearInterval(timer);
    }, []);

    return (
        <div className="relative">
            <button
                type="button"
                data-testid="notifications"
                aria-expanded={open}
                aria-label={t('notifications.open')}
                className="relative inline-flex h-10 w-10 items-center justify-center rounded-full border border-[var(--glass-border)] bg-[var(--glass)] text-foreground backdrop-blur-md hover:border-sky/40"
                onClick={() => setOpen((value) => !value)}
            >
                <Bell className="h-4 w-4" />
                {notices.unread > 0 ? <span className="absolute end-1.5 top-1.5 size-2 rounded-full bg-bad" /> : null}
            </button>
            {open ? (
                <div className="glass absolute end-0 z-40 mt-2 w-80 p-3" data-testid="notification-panel">
                    <div className="mb-2 flex items-center justify-between gap-2">
                        <p className="text-sm font-semibold">{t('notifications.title')}</p>
                        <button type="button" className="text-xs font-semibold text-accent-ink" onClick={() => router.post('/notifications/read')}>
                            {t('notifications.mark_all')}
                        </button>
                    </div>
                    {notices.items.length === 0 ? <p className="py-6 text-center text-sm text-muted">{t('notifications.empty')}</p> : null}
                    <ul className="max-h-80 space-y-2 overflow-y-auto">
                        {notices.items.map((item) => (
                            <li key={item.id} className="rounded-tile bg-card-2 px-3 py-2">
                                <p className="text-sm font-semibold">{item.title}</p>
                                <p className="mt-0.5 text-xs text-muted">{item.body}</p>
                                {item.url ? <Link href={item.url} className="mt-1 inline-flex text-xs font-semibold text-accent-ink">{t('approvals.open')}</Link> : null}
                                {item.read ? null : (
                                    <button type="button" className="mt-1 text-xs font-semibold text-accent-ink" onClick={() => router.post(`/notifications/${item.id}/read`)}>
                                        {t('notifications.mark_read')}
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}
