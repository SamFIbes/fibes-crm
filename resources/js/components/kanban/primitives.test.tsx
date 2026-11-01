import { render, renderHook, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { WeekBoard } from '@/components/calendar/WeekBoard';
import { Drawer } from '@/components/ui/drawer';
import { I18nProvider } from '@/lib/i18n';
import { DragPortal, useDragLock } from '@/lib/dnd';
import { KanbanBoard } from './Board';

describe('drag helpers', () => {
    it('locks the page while a drag is active and portals the preview', () => {
        const { rerender } = renderHook(({ active }) => useDragLock(active), { initialProps: { active: true } });
        expect(document.documentElement).toHaveClass('is-dragging');
        rerender({ active: false });
        expect(document.documentElement).not.toHaveClass('is-dragging');

        render(
            <DragPortal>
                <span>preview</span>
            </DragPortal>,
        );
        expect(document.body).toHaveTextContent('preview');
    });
});

describe('board primitives', () => {
    it('renders an empty ticket board and a week calendar', () => {
        render(
            <KanbanBoard
                columns={[
                    { id: 'New', title: 'New', items: [] },
                    { id: 'Resolved', title: 'Resolved', items: [{ id: 't1', title: 'Sample' }] },
                ]}
                renderCard={(item: { id: string; title?: string }) => <span>{item.title}</span>}
                empty="Nothing here"
            />,
        );

        expect(screen.getByTestId('kanban-board')).toBeInTheDocument();
        expect(screen.getByText('Sample')).toBeInTheDocument();
        expect(screen.getByText('Nothing here')).toBeInTheDocument();

        render(<WeekBoard days={[{ id: 'mon', label: 'Mon' }]} items={[{ id: 'a', dayId: 'mon', title: 'Callback' }]} />);
        expect(screen.getByTestId('calendar')).toBeInTheDocument();
        expect(screen.getByText('Callback')).toBeInTheDocument();
    });

    it('opens a drawer from the end of the screen', () => {
        render(
            <I18nProvider locale="ar" dir="rtl" translations={{ nav: { close_menu: 'إغلاق' } }}>
                <Drawer open title="Details" onOpenChange={() => undefined}>
                    <p>Body</p>
                </Drawer>
            </I18nProvider>,
        );

        expect(screen.getByTestId('drawer')).toHaveAttribute('dir', 'rtl');
        expect(screen.getByText('Body')).toBeInTheDocument();
    });
});
