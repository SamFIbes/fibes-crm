export type QueuedJob = {
    key: string;
    url: string;
    body: Record<string, string | number | boolean | null>;
};

const storageKey = 'fibes-job-queue';

export function readQueue(): QueuedJob[] {
    try {
        const raw = localStorage.getItem(storageKey);

        return raw ? (JSON.parse(raw) as QueuedJob[]) : [];
    } catch {
        return [];
    }
}

export function enqueue(item: QueuedJob): QueuedJob[] {
    const next = [...readQueue().filter((row) => row.key !== item.key), item];
    localStorage.setItem(storageKey, JSON.stringify(next));

    return next;
}

export function removeQueued(key: string): QueuedJob[] {
    const next = readQueue().filter((row) => row.key !== key);
    localStorage.setItem(storageKey, JSON.stringify(next));

    return next;
}

export async function flushQueue(csrf: string): Promise<QueuedJob[]> {
    let pending = readQueue();

    for (const item of [...pending]) {
        try {
            const response = await fetch(item.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrf,
                    'Idempotency-Key': item.key,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(item.body),
            });

            if (response.ok || response.status === 422) {
                pending = removeQueued(item.key);
            }
        } catch {
            break;
        }
    }

    return pending;
}

export function csrfToken(): string {
    const match = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=')[1] ?? '') : '';
}
