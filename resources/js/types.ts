export type Locale = 'en' | 'ar';

export type TranslationTree = {
    [key: string]: string | TranslationTree;
};

export type AuthUser = {
    id: number;
    name: string;
    email: string;
    locale: Locale;
    two_factor_enabled: boolean;
    role: string;
};

export type SharedProps = {
    locale: Locale;
    dir: 'ltr' | 'rtl';
    translations: TranslationTree;
    auth: {
        user: AuthUser | null;
        nav: string[];
        abilities?: { customers_create?: boolean; tickets_create?: boolean; calls?: boolean; tasks_create?: boolean };
        view_as: { id: number; name: string } | null;
    };
    flash: {
        status?: string | null;
        recovery_codes?: string[] | null;
        link?: string | null;
        tv_token?: string | null;
        lead_matches?: {
            leads: { id: number; number: string | null; name: string | null; phone: string | null }[];
            customers: { id: number; name: string; account_no: string }[];
            possible: { id: number; number: string | null; name: string | null }[];
        } | null;
    };
    security: {
        must_change_password: boolean;
        two_factor_grace_days: number | null;
    };
    app: {
        name: string;
    };
    notifications?: {
        unread: number;
        items: { id: string; title: string; body: string; read: boolean; url?: string | null }[];
    };
    errors?: Record<string, string>;
};

export type ModuleKey = 'leads' | 'opportunities' | 'surveys' | 'quotes' | 'settings' | 'customers' | 'tickets' | 'tasks' | 'billing' | 'tech' | 'network' | 'audit';
