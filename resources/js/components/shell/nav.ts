import { AlertTriangle, BarChart3, Boxes, CalendarCheck2, CalendarRange, ClipboardCheck, Coins, CreditCard, FileText, Headset, HeartHandshake, Kanban, LayoutDashboard, ListChecks, Map, MapPinned, Network, Package, Paperclip, Plug, ScrollText, Settings, ShieldCheck, Siren, Smartphone, Tags, Ticket, Trophy, Users, Zap, type LucideIcon } from 'lucide-react';

export type NavItem = {
    href: string;
    key: string;
    icon: LucideIcon;
};

export const navGroups: { label: string; items: NavItem[] }[] = [
    {
        label: 'nav.workspace',
        items: [
            { href: '/dashboard', key: 'dashboard', icon: LayoutDashboard },
            { href: '/customers', key: 'customers', icon: Users },
            { href: '/tickets', key: 'tickets', icon: Ticket },
            { href: '/dispatch', key: 'dispatch', icon: CalendarRange },
            { href: '/incidents', key: 'incidents', icon: Siren },
            { href: '/calls', key: 'calls', icon: Headset },
            { href: '/qa', key: 'qa', icon: ListChecks },
            { href: '/performance', key: 'performance', icon: Trophy },
            { href: '/reports', key: 'reports', icon: BarChart3 },
            { href: '/automations', key: 'automations', icon: Zap },
            { href: '/tasks', key: 'tasks', icon: CalendarCheck2 },
            { href: '/approvals', key: 'approvals', icon: ClipboardCheck },
        ],
    },
    {
        label: 'nav.money',
        items: [
            { href: '/billing', key: 'billing', icon: CreditCard },
            { href: '/collections', key: 'collections', icon: AlertTriangle },
            { href: '/retention', key: 'retention', icon: HeartHandshake },
            { href: '/rates', key: 'rates', icon: Coins },
            { href: '/catalog', key: 'catalog', icon: Tags },
        ],
    },
    {
        label: 'nav.field',
        items: [
            { href: '/tech', key: 'tech', icon: Smartphone },
            { href: '/network', key: 'network', icon: Network },
            { href: '/coverage', key: 'coverage', icon: Map },
            { href: '/geography', key: 'geography', icon: MapPinned },
        ],
    },
    {
        label: 'nav.admin_group',
        items: [
            { href: '/admin/users', key: 'users', icon: ShieldCheck },
            { href: '/admin/roles', key: 'roles', icon: ShieldCheck },
            { href: '/audit', key: 'audit', icon: ScrollText },
            { href: '/settings', key: 'settings', icon: Settings },
            { href: '/settings/integrations', key: 'integrations', icon: Plug },
            { href: '/imports', key: 'imports', icon: FileText },
            { href: '/files', key: 'files', icon: Paperclip },
            { href: '/inventory', key: 'inventory', icon: Boxes },
        ],
    },
    {
        label: 'nav.sales',
        items: [
            { href: '/leads', key: 'leads', icon: Users },
            { href: '/opportunities', key: 'opportunities', icon: Kanban },
            { href: '/surveys', key: 'surveys', icon: MapPinned },
            { href: '/quotes', key: 'quotes', icon: FileText },
            { href: '/orders', key: 'orders', icon: Package },
        ],
    },
];
