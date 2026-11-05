import { DndContext, DragOverlay, PointerSensor, pointerWithin, useDraggable, useDroppable, useSensor, useSensors, type DragEndEvent, type DragStartEvent } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { useRef, useState, type ReactNode } from 'react';
import { autoScroll, DragPortal, useDragLock, useEdgeScroll } from '@/lib/dnd';
import { cn } from '@/lib/utils';

export type WeekItem = { id: string; dayId: string; title: string };

export function WeekBoard({
    days,
    items,
    onMove,
    empty,
    renderItem,
}: {
    days: { id: string; label: string }[];
    items: WeekItem[];
    onMove?: (itemId: string, dayId: string) => void;
    empty?: ReactNode;
    renderItem?: (item: WeekItem) => ReactNode;
}) {
    const boardRef = useRef<HTMLDivElement>(null);
    const [activeId, setActiveId] = useState<string | null>(null);
    useDragLock(Boolean(activeId));
    useEdgeScroll(boardRef, Boolean(activeId));
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }));
    const active = items.find((item) => item.id === activeId) ?? null;

    const dayFor = (id: string): string | null => {
        if (id.startsWith('day:')) {
            return id.slice(4);
        }

        return items.find((item) => item.id === id)?.dayId ?? null;
    };

    const onDragEnd = (event: DragEndEvent) => {
        setActiveId(null);
        const over = event.over?.id ? String(event.over.id) : null;
        const day = over ? dayFor(over) : null;

        if (day) {
            onMove?.(String(event.active.id), day);
        }
    };

    return (
        <DndContext sensors={sensors} collisionDetection={pointerWithin} autoScroll={autoScroll} onDragStart={(event: DragStartEvent) => setActiveId(String(event.active.id))} onDragEnd={onDragEnd} onDragCancel={() => setActiveId(null)}>
            <div ref={boardRef} data-testid="calendar" className="card flex gap-2 overflow-x-auto overscroll-x-contain p-3">
                {days.map((day) => (
                    <Day key={day.id} day={day} items={items.filter((item) => item.dayId === day.id)} empty={empty} renderItem={renderItem} />
                ))}
            </div>
            <DragPortal>
                <DragOverlay dropAnimation={null} zIndex={70}>
                    {active ? <div className="w-40 rounded-tile bg-card p-2 text-sm shadow-pop ring-1 ring-line">{active.title}</div> : null}
                </DragOverlay>
            </DragPortal>
        </DndContext>
    );
}

function Day({
    day,
    items,
    empty,
    renderItem,
}: {
    day: { id: string; label: string };
    items: WeekItem[];
    empty?: ReactNode;
    renderItem?: (item: WeekItem) => ReactNode;
}) {
    const { setNodeRef, isOver } = useDroppable({ id: `day:${day.id}` });

    return (
        <section className={cn('flex min-h-48 w-40 shrink-0 flex-col rounded-tile bg-card-2 p-2 ring-1 ring-transparent', isOver && 'ring-accent')} data-day={day.id}>
            <h2 className="px-1 pb-2 text-sm font-semibold">{day.label}</h2>
            <div ref={setNodeRef} className="flex flex-1 flex-col gap-2">
                {items.length === 0 ? <p className="px-1 py-4 text-center text-xs text-faint">{empty}</p> : null}
                {items.map((item) => (
                    <Event key={item.id} item={item} renderItem={renderItem} />
                ))}
            </div>
        </section>
    );
}

function Event({ item, renderItem }: { item: WeekItem; renderItem?: (item: WeekItem) => ReactNode }) {
    const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({ id: item.id });

    return (
        <div ref={setNodeRef} style={{ transform: CSS.Translate.toString(transform) }} className={cn('cursor-grab rounded-[10px] border border-[var(--glass-border)] bg-[var(--field-bg)] px-2 py-1.5 text-sm', isDragging && 'opacity-40')} {...listeners} {...attributes}>
            {renderItem ? renderItem(item) : item.title}
        </div>
    );
}
