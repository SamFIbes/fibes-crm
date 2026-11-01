import { FileText, Kanban, LayoutDashboard, MapPinned, Settings, Shield, Users, type LucideIcon } from 'lucide-react';

export type NavKey = 'dashboard' | 'leads' | 'opportunities' | 'surveys' | 'quotes' | 'settings' | 'users' | 'roles';

export const navigation: { key: NavKey; href: string; icon: LucideIcon }[] = [
    { key: 'dashboard', href: '/dashboard', icon: LayoutDashboard },
    { key: 'leads', href: '/leads', icon: Users },
    { key: 'opportunities', href: '/opportunities', icon: Kanban },
    { key: 'surveys', href: '/surveys', icon: MapPinned },
    { key: 'quotes', href: '/quotes', icon: FileText },
    { key: 'settings', href: '/settings', icon: Settings },
    { key: 'users', href: '/admin/users', icon: Users },
    { key: 'roles', href: '/admin/roles', icon: Shield },
];
