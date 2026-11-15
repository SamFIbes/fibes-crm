import { csrfToken } from '@/lib/jobQueue';

export type BoardFilters = {
    q: string;
    assignee: string;
    area_id: string;
    stale: string;
    mine: string;
};

export type Stage = {
    key: string;
    name_en: string;
    name_ar: string;
    position: number;
    is_terminal: boolean;
    count: number;
};

export type Person = { id: number; name: string };
export type Area = { id: number; name_en: string; name_ar: string };
export type Attention = { total: number; mine: number; stale_days: number };

export type BoardMeta = {
    stages: Stage[];
    people: Person[];
    areas: Area[];
    stale_days: number;
    can_edit: boolean;
    can_manage: boolean;
    attention: { total: number; mine: number };
};

export type Card = {
    id: number;
    title: string;
    area_id: number | null;
    area_en: string | null;
    area_ar: string | null;
    client_name: string | null;
    source_kind: string | null;
    source_id: number | null;
    salesperson_name: string | null;
    salesperson_id: number | null;
    stage_key: string;
    stage_entered_at: string | null;
    last_activity_at: string | null;
    assigned_to: number | null;
    assignee_name: string | null;
    days_in_stage: number;
    task_title_en: string | null;
    task_title_ar: string | null;
    stale: boolean;
    followup_due: boolean;
    followup_on: string | null;
    lead_id: number | null;
    customer_id: number | null;
    language: string | null;
};

export type CardEvent = {
    id: number;
    occurred_at: string;
    actor_id: number | null;
    actor_name: string | null;
    kind: string;
    from_stage: string | null;
    to_stage: string | null;
    detail: string | null;
};

export type CardDetail = Card & {
    client_phone: string | null;
    client_email: string | null;
    events: CardEvent[];
    followup: { note?: string | null } | null;
    lead_number: string | null;
    customer_account_no: string | null;
    next_stages: (string | { key: string })[];
};

export type ColumnResponse = {
    items: Card[];
    next_cursor: number | null;
    has_more: boolean;
};

export class OnboardingRequestError extends Error {
    status: number;
    errors: Record<string, string[]>;

    constructor(status: number, errors: Record<string, string[]> = {}) {
        super(`Onboarding request failed (${status})`);
        this.name = 'OnboardingRequestError';
        this.status = status;
        this.errors = errors;
    }
}

export function filterQuery(filters: BoardFilters): URLSearchParams {
    const query = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
        if (value !== '' && value !== null && value !== undefined) query.set(key, String(value));
    });
    return query;
}

export async function jsonFetch<T = unknown>(url: string, options: RequestInit = {}): Promise<T> {
    const method = (options.method ?? 'GET').toUpperCase();
    const headers = new Headers(options.headers);
    headers.set('Accept', 'application/json');
    if (method !== 'GET' && method !== 'HEAD') {
        headers.set('Content-Type', 'application/json');
        headers.set('X-XSRF-TOKEN', csrfToken());
    }

    const response = await fetch(url, {
        ...options,
        method,
        headers,
        credentials: 'include',
    });

    if (!response.ok) {
        let errors: Record<string, string[]> = {};
        if (response.status === 422) {
            try {
                const payload: unknown = await response.json();
                if (payload && typeof payload === 'object' && 'errors' in payload) {
                    const raw = payload.errors;
                    if (raw && typeof raw === 'object') {
                        errors = Object.fromEntries(
                            Object.entries(raw)
                                .filter((entry): entry is [string, string[]] => Array.isArray(entry[1]) && entry[1].every((item: unknown) => typeof item === 'string')),
                        );
                    }
                }
            } catch {
                // A malformed error response is still a failed request.
            }
        }
        throw new OnboardingRequestError(response.status, errors);
    }

    if (response.status === 204) return undefined as T;
    return response.json() as Promise<T>;
}
