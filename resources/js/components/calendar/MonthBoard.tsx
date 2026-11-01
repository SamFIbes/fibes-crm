import { DndContext, DragOverlay, PointerSensor, pointerWithin, useDraggable, useDroppable, useSensor, useSensors, type DragEndEvent, type DragStartEvent } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { useState } from 'react';
import { DragPortal } from '@/lib/dnd';
import { cn } from '@/lib/utils';
import type { WeekItem } from '@/components/calendar/WeekBoard';

export function MonthBoard({
    days,
    items,
    onMove,
}: {
    days: { id: string; label: string; note?: string; inMonth: boolean }[];
    items: WeekItem[];
    onMove?: (itemId: string, dayId: string) => void;
}) {
    const [activeId, setActiveId] = useState<string | null>(null);
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
        <DndContext sensors={sensors} collisionDetection={pointerWithin} autoScroll={false} onDragStart={(event: DragStartEvent) => setActiveId(String(event.active.id))} onDragEnd={onDragEnd} onDragCancel={() => setActiveId(null)}>
            <div className="card grid grid-cols-7 gap-1 p-3" data-testid="month-board">
                {days.map((day) => (
                    <MonthDay key={day.id} day={day} items={items.filter((item) => item.dayId === day.id)} />
                ))}
            </div>
            <DragPortal>
                <DragOverlay dropAnimation={null} zIndex={70}>
                    {active ? <div className="w-32 rounded-tile bg-card p-2 text-xs shadow-pop ring-1 ring-line">{active.title}</div> : null}
                </DragOverlay>
            </DragPortal>
        </DndContext>
    );
}

function MonthDay({
    day,
    items,
}: {
    day: { id: string; label: string; note?: string; inMonth: boolean };
    items: WeekItem[];
}) {
    const { setNodeRef, isOver } = useDroppable({ id: `day:${day.id}` });

    return (
        <div ref={setNodeRef} className={cn('min-h-20 rounded-tile bg-card-2 p-1 text-start text-xs', !day.inMonth && 'opacity-50', isOver && 'ring-1 ring-accent')}>
            <span className="font-semibold" dir="ltr">{day.label}</span>
            {day.note ? <span className="mt-1 block text-faint">{day.note}</span> : null}
            <div className="mt-1 space-y-1">
                {items.slice(0, 3).map((item) => (
                    <MonthEvent key={item.id} item={item} />
                ))}
            </div>
        </div>
    );
}

function MonthEvent({ item }: { item: WeekItem }) {
    const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({ id: item.id });

    return (
        <div ref={setNodeRef} style={{ transform: CSS.Translate.toString(transform) }} className={cn('cursor-grab truncate rounded-[8px] bg-card px-1 py-0.5 ring-1 ring-line', isDragging && 'opacity-40')} {...listeners} {...attributes}>
            {item.title}
        </div>
    );
}
