import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import TopologyIndex from '@/Pages/Topology/Index';
import { I18nProvider } from '@/lib/i18n';
import { ThemeProvider } from '@/lib/theme';
import type { TranslationTree } from '@/types';

const flow = vi.hoisted(() => ({
    zoomIn: vi.fn(),
    zoomOut: vi.fn(),
    fitView: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
}));

vi.mock('@/components/shell/AppShell', () => ({
    AppShell: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@xyflow/react', () => ({
    ReactFlow: ({ children }: { children?: ReactNode }) => <div data-testid="topology-canvas">{children}</div>,
    Panel: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
    Handle: () => null,
    Position: { Left: 'left', Right: 'right', Top: 'top', Bottom: 'bottom' },
    useReactFlow: () => flow,
}));

const copy = {
    topology: {
        title: 'Provider Topology',
        summary: ':active active · :recent recent · :error error',
        sample: 'Sample data. These stand-ins follow connections the CRM already has.',
        sample_mark: 'Sample',
        active: 'Active',
        recent: 'Recent',
        error: 'Error',
        legend: 'Status legend',
        zoom_in: 'Zoom in',
        zoom_out: 'Zoom out',
        fit: 'Fit view',
        hub: 'FIBES',
        canvas: 'Provider topology graph',
        p_whish: 'Whish',
        p_omt: 'OMT',
        p_krypton: 'Krypton',
        p_web_form: 'Website forms',
    },
} as TranslationTree;

describe('provider topology page', () => {
    it('shows the counts, the legend, and the zoom controls', async () => {
        const user = userEvent.setup();
        flow.zoomIn.mockReset();

        render(
            <I18nProvider locale="en" dir="ltr" translations={copy}>
                <ThemeProvider>
                    <TopologyIndex
                        providers={[
                            { key: 'omt', group: 'payments', status: 'active', sample: false },
                            { key: 'whish', group: 'payments', status: 'recent', sample: true },
                            { key: 'krypton', group: 'network', status: 'error', sample: false },
                            { key: 'web_form', group: 'leads', status: 'active', sample: false },
                        ]}
                    />
                </ThemeProvider>
            </I18nProvider>,
        );

        expect(screen.getByRole('heading', { name: 'Provider Topology' })).toBeInTheDocument();
        expect(screen.getByTestId('topology-summary')).toHaveTextContent('2 active · 1 recent · 1 error');
        expect(screen.getByText('Active')).toBeInTheDocument();
        expect(screen.getByText('Recent')).toBeInTheDocument();
        expect(screen.getByText('Error')).toBeInTheDocument();
        expect(screen.getByTestId('topology-sample-note')).toHaveTextContent('Sample data');
        expect(screen.getByText(/Website forms, Active/)).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Zoom in' }));
        expect(flow.zoomIn).toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Zoom out' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Fit view' })).toBeInTheDocument();
    });
});
