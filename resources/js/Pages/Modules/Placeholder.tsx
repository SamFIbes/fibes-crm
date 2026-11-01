import { Head } from '@inertiajs/react';
import { CreditCard, FileText, Kanban, MapPinned, Network, ScrollText, Settings, Smartphone, Ticket, Users } from 'lucide-react';
import { WeekBoard } from '@/components/calendar/WeekBoard';
import { EmptyState } from '@/components/feedback/EmptyState';
import { KanbanBoard } from '@/components/kanban/Board';
import { AppShell } from '@/components/shell/AppShell';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { ModuleKey } from '@/types';

const icons: Partial<Record<ModuleKey, typeof Users>> = {
    leads: Users,
    opportunities: Kanban,
    surveys: MapPinned,
    quotes: FileText,
    settings: Settings,
    customers: Users,
    tickets: Ticket,
    tasks: Kanban,
    billing: CreditCard,
    tech: Smartphone,
    network: Network,
    audit: ScrollText,
};

const ticketColumns = ['New', 'Assigned', 'In Progress', 'Pending', 'Escalated', 'Resolved'];

const week = {
    en: [
        ['mon', 'Mon'],
        ['tue', 'Tue'],
        ['wed', 'Wed'],
        ['thu', 'Thu'],
        ['fri', 'Fri'],
        ['sat', 'Sat'],
        ['sun', 'Sun'],
    ],
    ar: [
        ['mon', 'الاثنين'],
        ['tue', 'الثلاثاء'],
        ['wed', 'الأربعاء'],
        ['thu', 'الخميس'],
        ['fri', 'الجمعة'],
        ['sat', 'السبت'],
        ['sun', 'الأحد'],
    ],
} as const;

export default function Placeholder({ module }: { module: ModuleKey }) {
    const { t, locale } = useI18n();
    const Icon = icons[module] ?? Settings;
    const title = t(`modules.${module}_title`);
    const body = t(`modules.${module}_body`);

    return (
        <AppShell>
            <Head title={title} />
            <PageHeader title={title} sub={body} crumbs={<span className="uppercase tracking-[0.12em] text-faint">{t('modules.eyebrow')}</span>} />
            {module === 'tickets' ? (
                <KanbanBoard columns={ticketColumns.map((id) => ({ id, title: id, items: [] }))} renderCard={() => null} empty={body} />
            ) : null}
            {module === 'tasks' ? <WeekBoard days={week[locale].map(([id, label]) => ({ id, label }))} items={[]} empty={body} /> : null}
            {module === 'tickets' || module === 'tasks' ? null : (
                <div className="card border-dashed">
                    <EmptyState icon={<Icon className="size-5" aria-hidden="true" />} eyebrow={t('modules.eyebrow')} title={title} body={body} />
                </div>
            )}
        </AppShell>
    );
}
