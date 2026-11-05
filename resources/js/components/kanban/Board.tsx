import { DndContext, DragOverlay, PointerSensor, pointerWithin, useDraggable, useDroppable, useSensor, useSensors, type DragEndEvent, type DragStartEvent } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { useRef, useState, type ReactNode } from 'react';
import { autoScroll, DragPortal, useDragLock, useEdgeScroll } from '@/lib/dnd';
import { cn } from '@/lib/utils';

export type KanbanItem = { id: string };

export type KanbanColumn<T extends KanbanItem> = {
    id: string;
    title: string;
    items: T[];
};

export function KanbanBoard<T extends KanbanItem>({
    columns,
    renderCard,
    onMove,
    empty,
}: {
    columns: KanbanColumn<T>[];
    renderCard: (item: T, overlay?: boolean) => ReactNode;
    onMove?: (itemId: string, toColumnId: string) => void;
    empty?: ReactNode;
}) {
    const boardRef = useRef<HTMLDivElement>(null);
    const [activeId, setActiveId] = useState<string | null>(null);
    useDragLock(Boolean(activeId));
    useEdgeScroll(boardRef, Boolean(activeId));
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }));
    const active = columns.flatMap((column) => column.items).find((item) => item.id === activeId) ?? null;

    const columnFor = (id: string): string | null => {
        if (id.startsWith('col:')) {
            return id.slice(4);
        }

        return columns.find((column) => column.items.some((item) => item.id === id))?.id ?? null;
    };

    const onDragEnd = (event: DragEndEvent) => {
        setActiveId(null);
        const over = event.over?.id ? String(event.over.id) : null;

        if (!over) {
            return;
        }

        const target = columnFor(over);

        if (target) {
            onMove?.(String(event.active.id), target);
        }
    };

    return (
        <DndContext sensors={sensors} collisionDetection={pointerWithin} autoScroll={autoScroll} onDragStart={(event: DragStartEvent) => setActiveId(String(event.active.id))} onDragEnd={onDragEnd} onDragCancel={() => setActiveId(null)}>
            <div ref={boardRef} data-testid="kanban-board" className="flex gap-3 overflow-x-auto overscroll-x-contain pb-4">
                {columns.map((column) => (
                    <Column key={column.id} column={column} renderCard={renderCard} empty={empty} />
                ))}
            </div>
            <DragPortal>
                <DragOverlay dropAnimation={null} zIndex={70}>
                    {active ? <div className="w-[260px]">{renderCard(active, true)}</div> : null}
                </DragOverlay>
            </DragPortal>
        </DndContext>
    );
}

function Column<T extends KanbanItem>({
    column,
    renderCard,
    empty,
}: {
    column: KanbanColumn<T>;
    renderCard: (item: T, overlay?: boolean) => ReactNode;
    empty?: ReactNode;
}) {
    const { setNodeRef, isOver } = useDroppable({ id: `col:${column.id}` });

    return (
        <section className={cn('card flex w-[272px] shrink-0 flex-col p-2', isOver && 'ring-2 ring-aqua')} data-column={column.id}>
            <header className="flex items-center justify-between px-2 py-1.5">
                <h2 className="text-sm font-semibold">{column.title}</h2>
                <span className="text-2xs font-semibold text-faint tabular">{column.items.length}</span>
            </header>
            <div ref={setNodeRef} className="flex min-h-40 flex-1 flex-col gap-2">
                {column.items.length === 0 ? <p className="px-2 py-6 text-center text-xs text-faint">{empty}</p> : null}
                {column.items.map((item) => (
                    <Card key={item.id} id={item.id}>
                        {renderCard(item)}
                    </Card>
                ))}
            </div>
        </section>
    );
}

function Card({ id, children }: { id: string; children: ReactNode }) {
    const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({ id });

    return (
        <div ref={setNodeRef} style={{ transform: CSS.Translate.toString(transform) }} className={cn('cursor-grab rounded-tile border border-[var(--glass-border)] bg-[var(--field-bg)] p-3 shadow-[var(--glow-aqua)]', isDragging && 'opacity-40')} {...listeners} {...attributes}>
            {children}
        </div>
    );
}
