import { useDraggable } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import type { Card, Stage } from '@/lib/onboarding';

export function OnboardingCardTile({
    card,
    stages,
    canEdit,
    busy,
    overlay = false,
    onOpen,
    onMove,
    onSnooze,
}: {
    card: Card;
    stages: Stage[];
    canEdit: boolean;
    busy: boolean;
    overlay?: boolean;
    onOpen: () => void;
    onMove: (stage: string) => void;
    onSnooze: (days: 1 | 3) => void;
}) {
    const { t, locale } = useI18n();
    const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
        id: String(card.id),
        disabled: !canEdit || busy || overlay,
    });
    const area = locale === 'ar' ? card.area_ar || card.area_en : card.area_en || card.area_ar;
    const task = locale === 'ar' ? card.task_title_ar || card.task_title_en : card.task_title_en || card.task_title_ar;

    return (
        <article
            ref={setNodeRef}
            data-testid={overlay ? undefined : 'onboarding-card'}
            tabIndex={overlay ? -1 : 0}
            aria-label={card.title}
            style={{ transform: CSS.Translate.toString(transform) }}
            className={`space-y-2 rounded-tile bg-card p-3 shadow-card ring-1 ring-line focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${isDragging ? 'opacity-40' : ''}`}
            onKeyDown={(event) => {
                if (event.target !== event.currentTarget) return;
                if (event.key === 'Enter') {
                    event.preventDefault();
                    onOpen();
                    return;
                }
                if (!canEdit || busy || (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight')) return;
                event.preventDefault();
                const rtl = document.documentElement.dir === 'rtl';
                const direction = (event.key === 'ArrowRight' ? 1 : -1) * (rtl ? -1 : 1);
                const index = stages.findIndex((stage) => stage.key === card.stage_key);
                const destination = index >= 0 ? stages[index + direction] : undefined;
                if (destination) onMove(destination.key);
            }}
        >
            <div className="flex items-start gap-2">
                <button type="button" className="min-h-11 flex-1 text-start font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" onClick={onOpen} tabIndex={overlay ? -1 : 0}>
                    {card.title}
                </button>
                {canEdit && !overlay ? (
                    <button
                        type="button"
                        className="flex min-h-11 min-w-11 touch-none items-center justify-center rounded-ctl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        aria-label={`${t('onboarding.drag_card')}: ${card.title}`}
                        disabled={busy}
                        {...attributes}
                        {...listeners}
                    >
                        <GripVertical aria-hidden="true" className="size-4" />
                    </button>
                ) : null}
            </div>
            <div className="space-y-1 text-sm text-muted">
                {area ? <p>{t('onboarding.area')}: {area}</p> : null}
                {card.salesperson_name ? <p>{t('onboarding.salesperson')}: {card.salesperson_name}</p> : null}
                <p>{t('onboarding.assignee')}: {card.assignee_name || t('onboarding.unassigned')}</p>
                <p>{t('onboarding.days_in_stage')}: <span className="tabular">{card.days_in_stage}</span></p>
                {task ? <p>{t('onboarding.next_task')}: {task}</p> : null}
            </div>
            <div className="flex flex-wrap gap-2">
                {card.stale ? <Badge tone="warn">{t('onboarding.stale')}</Badge> : null}
                {card.followup_due ? <Badge tone="warn">{t('onboarding.followup_due')}</Badge> : null}
            </div>
            {canEdit && !overlay ? (
                <div className="space-y-2">
                    <label className="grid gap-1 text-sm">
                        <span>{t('onboarding.move_to')}</span>
                        <select
                            className="min-h-11 w-full rounded-ctl border border-input bg-card px-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                            value={card.stage_key}
                            disabled={busy}
                            onChange={(event) => onMove(event.target.value)}
                        >
                            {stages.map((stage) => <option key={stage.key} value={stage.key}>{locale === 'ar' ? stage.name_ar : stage.name_en}</option>)}
                        </select>
                    </label>
                    {card.followup_due || card.stale ? (
                        <div className="flex gap-2">
                            <Button type="button" variant="outline" className="min-h-11" disabled={busy} onClick={() => onSnooze(1)}>{t('onboarding.snooze_1')}</Button>
                            <Button type="button" variant="outline" className="min-h-11" disabled={busy} onClick={() => onSnooze(3)}>{t('onboarding.snooze_3')}</Button>
                        </div>
                    ) : null}
                </div>
            ) : null}
        </article>
    );
}
