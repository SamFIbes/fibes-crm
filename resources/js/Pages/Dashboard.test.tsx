import { act, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Dashboard from './Dashboard';

type Options = {
    only: string[];
    onStart: () => void;
    onFinish: () => void;
    onSuccess: () => void;
    onError: () => void;
    onHttpException: (response: { status: number }) => boolean | void;
    onNetworkError: () => void;
};

const h = vi.hoisted(() => ({
    reload: vi.fn(),
    handlers: {} as Record<string, Array<() => void>>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: {
        reload: h.reload,
        get: vi.fn(),
        on: (event: string, callback: () => void) => {
            (h.handlers[event] ??= []).push(callback);

            return () => {
                h.handlers[event] = (h.handlers[event] ?? []).filter((fn) => fn !== callback);
            };
        },
    },
    useForm: () => ({ data: { keys: [], from: '', to: '' }, setData: vi.fn(), post: vi.fn(), processing: false }),
    usePage: () => ({ props: { auth: { user: { name: 'Synthetic User' }, nav: [] } } }),
}));
vi.mock('@/components/shell/AppShell', () => ({ AppShell: ({ children }: { children: ReactNode }) => <>{children}</> }));
vi.mock('@/components/shell/nav', () => ({ navGroups: [] }));
vi.mock('@/components/dashboard/KpiStrip', () => ({ KpiStrip: () => null }));
vi.mock('@/components/dashboard/PeriodBar', () => ({ PeriodBar: () => null }));
vi.mock('@/components/dashboard/QueueList', () => ({ QueueList: () => null }));
vi.mock('@/components/dashboard/QueueLoad', () => ({ QueueLoad: () => null }));
vi.mock('@/components/dashboard/SectionGrid', () => ({ SectionGrid: () => null }));
vi.mock('@/components/ui/page-header', () => ({
    PageHeader: ({ title, actions }: { title: string; actions: ReactNode }) => (
        <div>
            <h1>{title}</h1>
            {actions}
        </div>
    ),
}));
vi.mock('@/lib/i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }));

const emit = (event: string) => act(() => (h.handlers[event] ?? []).forEach((fn) => fn()));
const tick = () =>
    act(() => {
        vi.advanceTimersByTime(5000);
    });
const setHidden = (value: boolean) => Object.defineProperty(document, 'hidden', { configurable: true, value });
const last = (): Options => h.reload.mock.calls.at(-1)![0] as Options;
const status = () => screen.getByTestId('dashboard-status').textContent;

function mount() {
    return render(<Dashboard home="sales" tiles={[]} choices={[]} from="2026-11-01" to="2026-11-10" refresh={5} attention={{ queues: [] }} />);
}

describe('Dashboard polling', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        h.reload.mockClear();
        h.handlers = {};
        setHidden(false);
    });

    afterEach(() => {
        vi.useRealTimers();
        setHidden(false);
    });

    it('reloads the tiles and queues on each tick', () => {
        mount();
        tick();

        expect(h.reload).toHaveBeenCalledTimes(1);
        expect(last().only).toEqual(['tiles', 'attention']);
    });

    it('shows the failure badge on a server error instead of Inertia’s error page', () => {
        mount();
        tick();
        let result: boolean | void = undefined;
        act(() => {
            result = last().onHttpException({ status: 500 });
        });

        expect(result).toBe(false);
        expect(screen.getByRole('alert')).toHaveTextContent('dashboard.reload_failed');
    });

    it('shows the failure badge on a network error', () => {
        mount();
        tick();
        act(() => last().onNetworkError());

        expect(screen.getByRole('alert')).toBeInTheDocument();
    });

    it('skips ticks while the tab is hidden', () => {
        mount();
        setHidden(true);
        tick();
        expect(h.reload).not.toHaveBeenCalled();

        setHidden(false);
        tick();
        expect(h.reload).toHaveBeenCalledTimes(1);
    });

    it('skips ticks while another visit is running, so it never cancels a navigation', () => {
        mount();
        emit('start');
        tick();
        expect(h.reload).not.toHaveBeenCalled();

        emit('finish');
        tick();
        expect(h.reload).toHaveBeenCalledTimes(1);
    });

    it('announces only the recovery after a failure, not every poll', () => {
        mount();
        tick();
        act(() => {
            last().onStart();
            last().onSuccess();
            last().onFinish();
        });
        expect(status()).toBe('');

        tick();
        act(() => {
            last().onHttpException({ status: 503 });
        });
        expect(status()).toBe('');

        tick();
        act(() => last().onSuccess());
        expect(status()).toBe('dashboard.up_to_date');
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('stops polling when the page is left', () => {
        const view = mount();
        view.unmount();
        tick();

        expect(h.reload).not.toHaveBeenCalled();
    });
});
