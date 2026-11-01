import { beforeEach, describe, expect, it, vi } from 'vitest';
import { enqueue, flushQueue, readQueue } from './jobQueue';

describe('job queue', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.unstubAllGlobals();
    });

    it('keeps one cash collection when the same key is queued twice', () => {
        enqueue({ key: 'cash-1', url: '/tech/jobs/1/cash', body: { amount: '10', currency: 'USD', password: 'secret' } });
        enqueue({ key: 'cash-1', url: '/tech/jobs/1/cash', body: { amount: '10', currency: 'USD', password: 'secret' } });
        expect(readQueue()).toHaveLength(1);
    });

    it('keeps a status change until the network accepts it', async () => {
        enqueue({ key: 'abc', url: '/tech/jobs/1/status', body: { status: 'en_route' } });
        expect(readQueue()).toHaveLength(1);

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, status: 200 }));
        const left = await flushQueue('token');

        expect(left).toHaveLength(0);
        expect(readQueue()).toHaveLength(0);
    });
});
