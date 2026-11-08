export type SlaSide = { state: string; remaining_seconds: number };
export type Sla = { response: SlaSide; resolve: SlaSide } | null;

export function slaConfigured(sla: Sla): boolean {
    return sla !== null && sla.response !== undefined && sla.resolve !== undefined;
}

export function attentionRank(row: { status: string; assignee: string | null; sla: Sla }): number {
    const closed = row.status === 'resolved' || row.status === 'closed';

    if (!closed && row.sla?.resolve.state === 'breached') {
        return 0;
    }

    if (!closed && row.sla?.response.state === 'breached') {
        return 1;
    }

    if (!closed && !row.assignee) {
        return 2;
    }

    return 3;
}

export function formatRemaining(seconds: number): string {
    const sign = seconds < 0 ? '-' : '';
    const abs = Math.abs(seconds);
    const hours = Math.floor(abs / 3600);
    const minutes = Math.floor((abs % 3600) / 60);

    return hours > 0 ? `${sign}${hours}h ${minutes}m` : `${sign}${minutes}m`;
}
