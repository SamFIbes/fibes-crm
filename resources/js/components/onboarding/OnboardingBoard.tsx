import {
    DndContext,
    DragOverlay,
    PointerSensor,
    pointerWithin,
    useDroppable,
    useSensor,
    useSensors,
    type DragEndEvent,
    type DragStartEvent,
} from '@dnd-kit/core';
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { OnboardingCardTile } from '@/components/onboarding/OnboardingCardTile';
import { OnboardingDrawer } from '@/components/onboarding/OnboardingDrawer';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { autoScroll, DragPortal, useDragLock, useEdgeScroll } from '@/lib/dnd';
import { useI18n } from '@/lib/i18n';
import {
    filterQuery,
    jsonFetch,
    type BoardFilters,
    type BoardMeta,
    type Card,
    type ColumnResponse,
    type Stage,
} from '@/lib/onboarding';

type ColumnState = {
    items: Card[];
    nextCursor: number | null;
    hasMore: boolean;
    loading: boolean;
    error: boolean;
    loaded: boolean;
};

type Job = () => Promise<void>;

const emptyColumn = (): ColumnState => ({
    items: [],
    nextCursor: null,
    hasMore: false,
    loading: false,
    error: false,
    loaded: false,
});

const controlClass = 'min-h-11 rounded-ctl border border-input bg-card px-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2';

export function OnboardingBoard({
    initialFilters,
    initialCanEdit,
    initialCanManage,
    initialStaleDays,
}: {
    initialFilters: Partial<BoardFilters>;
    initialCanEdit: boolean;
    initialCanManage: boolean;
    initialStaleDays: number;
}) {
    const { t, locale } = useI18n();
    const [filters, setFilters] = useState<BoardFilters>({
        q: initialFilters?.q ?? '',
        assignee: initialFilters?.assignee ?? '',
        area_id: initialFilters?.area_id ?? '',
        stale: initialFilters?.stale ?? '',
        mine: initialFilters?.mine ?? '',
    });
    const [search, setSearch] = useState(filters.q);
    const [highlight, setHighlight] = useState('');
    const [meta, setMeta] = useState<BoardMeta | null>(null);
    const [metaError, setMetaError] = useState(false);
    const [reloadKey, setReloadKey] = useState(0);
    const [columns, setColumns] = useState<Record<string, ColumnState>>({});
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [activeId, setActiveId] = useState<number | null>(null);
    const [busyIds, setBusyIds] = useState<number[]>([]);
    const [mutationError, setMutationError] = useState(false);
    const [adding, setAdding] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const boardRef = useRef<HTMLDivElement>(null);
    const generation = useRef(0);
    const requestIds = useRef<Record<string, number>>({});
    const queue = useRef<Job[]>([]);
    const running = useRef(0);
    const filtersRef = useRef(filters);
    const metaRef = useRef(meta);
    const busyRef = useRef(new Set<number>());
    const mounted = useRef(false);
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }));

    filtersRef.current = filters;
    metaRef.current = meta;
    useDragLock(activeId !== null);
    useEdgeScroll(boardRef, activeId !== null);

    useEffect(() => {
        mounted.current = true;

        return () => {
            mounted.current = false;
            generation.current += 1;
            queue.current = [];
        };
    }, []);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setFilters((current) => current.q === search ? current : { ...current, q: search });
        }, 300);

        return () => window.clearTimeout(timer);
    }, [search]);

    const drain = useCallback(() => {
        while (running.current < 3 && queue.current.length > 0) {
            const job = queue.current.shift();
            if (!job) break;
            running.current += 1;
            void job().finally(() => {
                running.current -= 1;
                drain();
            });
        }
    }, []);

    const loadColumn = useCallback((stage: string, append = false, cursor: number | null = null) => {
        const currentGeneration = generation.current;
        const requestId = (requestIds.current[stage] ?? 0) + 1;
        requestIds.current[stage] = requestId;
        const query = filterQuery(filtersRef.current);
        if (append && cursor !== null) query.set('after', String(cursor));

        setColumns((current) => ({
            ...current,
            [stage]: { ...(current[stage] ?? emptyColumn()), loading: true, error: false },
        }));

        queue.current.push(async () => {
            if (!mounted.current || currentGeneration !== generation.current || requestIds.current[stage] !== requestId) return;

            try {
                const result = await jsonFetch<ColumnResponse>(`/onboarding/board/columns/${encodeURIComponent(stage)}?${query}`);
                if (!mounted.current || currentGeneration !== generation.current || requestIds.current[stage] !== requestId) return;

                setColumns((current) => {
                    const previous = current[stage] ?? emptyColumn();
                    const items = append
                        ? [...previous.items, ...result.items.filter((item) => !previous.items.some((existing) => existing.id === item.id))]
                        : result.items;

                    return {
                        ...current,
                        [stage]: {
                            items,
                            nextCursor: result.next_cursor,
                            hasMore: result.has_more,
                            loading: false,
                            error: false,
                            loaded: true,
                        },
                    };
                });
            } catch {
                if (!mounted.current || currentGeneration !== generation.current || requestIds.current[stage] !== requestId) return;
                setColumns((current) => ({
                    ...current,
                    [stage]: { ...(current[stage] ?? emptyColumn()), loading: false, error: true },
                }));
            }
        });
        drain();
    }, [drain]);

    const refreshMeta = useCallback(async () => {
        const currentGeneration = generation.current;
        try {
            const result = await jsonFetch<BoardMeta>(`/onboarding/board/meta?${filterQuery(filtersRef.current)}`);
            if (mounted.current && currentGeneration === generation.current) setMeta(result);
        } catch {
            if (mounted.current && currentGeneration === generation.current) setMutationError(true);
        }
    }, []);

    useEffect(() => {
        const currentGeneration = ++generation.current;
        queue.current = [];
        requestIds.current = {};
        setMeta(null);
        setMetaError(false);
        setColumns({});
        setActiveId(null);

        void jsonFetch<BoardMeta>(`/onboarding/board/meta?${filterQuery(filters)}`)
            .then((result) => {
                if (!mounted.current || currentGeneration !== generation.current) return;
                setMeta(result);
                const stages = [...result.stages].sort((a, b) => a.position - b.position);
                const visibleKey = window.innerWidth < 760
                    ? boardRef.current?.querySelector<HTMLElement>('[data-column-visible="true"]')?.dataset.column
                    : undefined;
                if (visibleKey) stages.sort((a, b) => Number(b.key === visibleKey) - Number(a.key === visibleKey));
                stages.forEach((stage) => loadColumn(stage.key));
            })
            .catch(() => {
                if (mounted.current && currentGeneration === generation.current) setMetaError(true);
            });

        return () => {
            generation.current += 1;
            queue.current = [];
        };
    }, [filters, reloadKey, loadColumn]);

    const refreshColumns = useCallback((keys: string[]) => {
        [...new Set(keys)].forEach((key) => loadColumn(key));
        void refreshMeta();
    }, [loadColumn, refreshMeta]);

    const mutateCard = useCallback(async (
        card: Card,
        endpoint: string,
        body: Record<string, string | number | null>,
        method: 'POST' | 'PUT' = 'POST',
    ): Promise<boolean> => {
        if (busyRef.current.has(card.id)) return false;
        busyRef.current.add(card.id);
        setBusyIds([...busyRef.current]);
        setMutationError(false);
        const startedGeneration = generation.current;

        try {
            const result = await jsonFetch<{ card: Card }>(`/onboarding/cards/${card.id}/${endpoint}`, {
                method,
                body: JSON.stringify(body),
            });
            if (mounted.current) {
                if (startedGeneration === generation.current) {
                    refreshColumns([card.stage_key, result.card.stage_key]);
                } else {
                    setReloadKey((value) => value + 1);
                }
            }
            return true;
        } catch {
            if (mounted.current) setMutationError(true);
            return false;
        } finally {
            busyRef.current.delete(card.id);
            if (mounted.current) setBusyIds([...busyRef.current]);
        }
    }, [refreshColumns]);

    const moveCard = useCallback(async (card: Card, stage: string) => {
        if (stage === card.stage_key) return true;
        return mutateCard(card, 'move', { stage });
    }, [mutateCard]);

    const activeCard = activeId === null
        ? null
        : Object.values(columns).flatMap((column) => column.items).find((card) => card.id === activeId) ?? null;

    const onDragEnd = (event: DragEndEvent) => {
        setActiveId(null);
        const target = event.over ? String(event.over.id) : '';
        if (!target.startsWith('column:') || !activeCard) return;
        void moveCard(activeCard, target.slice('column:'.length));
    };

    const stages = [...(meta?.stages ?? [])].sort((a, b) => a.position - b.position);
    const canEdit = meta?.can_edit ?? initialCanEdit;
    const canManage = meta?.can_manage ?? initialCanManage;

    const updateFilter = (key: keyof BoardFilters, value: string) => {
        setFilters((current) => ({ ...current, [key]: value }));
    };

    const selectStage = (key: string) => {
        setHighlight(key);
        if (!key) return;
        const column = Array.from(boardRef.current?.querySelectorAll<HTMLElement>('[data-column]') ?? [])
            .find((element) => element.dataset.column === key);
        column?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <Button type="button" variant="outline" className="min-h-11" onClick={() => updateFilter('stale', '1')}>
                    <span data-testid="onboarding-followup-count">{meta?.attention.total ?? 0}</span>
                    {' '}{t('onboarding.need_followup')}
                    {' · '}
                    <span data-testid="onboarding-mine-count">{meta?.attention.mine ?? 0}</span>
                    {' '}{t('onboarding.yours')}
                </Button>
                {canEdit ? <Button type="button" className="min-h-11" onClick={() => setAdding(true)}>{t('onboarding.add')}</Button> : null}
                {canManage ? <Button type="button" variant="outline" className="min-h-11" onClick={() => setSettingsOpen(true)}>{t('onboarding.board_settings')}</Button> : null}
            </div>

            <section aria-label={t('onboarding.filters')} className="flex flex-wrap items-end gap-2">
                <label className="grid gap-1 text-sm">
                    <span>{t('onboarding.search')}</span>
                    <Input className="min-h-11" value={search} onChange={(event) => setSearch(event.target.value)} />
                </label>
                <label className="grid gap-1 text-sm">
                    <span>{t('onboarding.stage')}</span>
                    <select className={controlClass} value={highlight} onChange={(event) => selectStage(event.target.value)}>
                        <option value="">{t('onboarding.all_stages')}</option>
                        {stages.map((stage) => <option key={stage.key} value={stage.key}>{locale === 'ar' ? stage.name_ar : stage.name_en}</option>)}
                    </select>
                </label>
                <label className="grid gap-1 text-sm">
                    <span>{t('onboarding.assignee')}</span>
                    <select className={controlClass} value={filters.assignee} onChange={(event) => updateFilter('assignee', event.target.value)}>
                        <option value="">{t('onboarding.all_assignees')}</option>
                        <option value="me">{t('onboarding.me')}</option>
                        <option value="unassigned">{t('onboarding.unassigned')}</option>
                        {(meta?.people ?? []).map((person) => <option key={person.id} value={String(person.id)}>{person.name}</option>)}
                    </select>
                </label>
                <label className="grid gap-1 text-sm">
                    <span>{t('onboarding.area')}</span>
                    <select className={controlClass} value={filters.area_id} onChange={(event) => updateFilter('area_id', event.target.value)}>
                        <option value="">{t('onboarding.all_areas')}</option>
                        {(meta?.areas ?? []).map((area) => <option key={area.id} value={String(area.id)}>{locale === 'ar' ? area.name_ar : area.name_en}</option>)}
                    </select>
                </label>
                <Button type="button" variant="outline" className="min-h-11" aria-pressed={filters.stale === '1'} onClick={() => updateFilter('stale', filters.stale === '1' ? '' : '1')}>
                    {t('onboarding.stale')}
                </Button>
                <Button type="button" variant="outline" className="min-h-11" aria-pressed={filters.mine === '1'} onClick={() => updateFilter('mine', filters.mine === '1' ? '' : '1')}>
                    {t('onboarding.assigned_to_me')}
                </Button>
            </section>

            {mutationError ? <p role="alert" className="text-sm text-bad">{t('onboarding.error')}</p> : null}
            {metaError ? (
                <div role="alert" className="space-y-2">
                    <p className="text-sm text-bad">{t('onboarding.error')}</p>
                    <Button type="button" variant="outline" className="min-h-11" onClick={() => setReloadKey((value) => value + 1)}>{t('onboarding.retry')}</Button>
                </div>
            ) : null}
            {!meta && !metaError ? <p role="status" className="text-sm text-muted">{t('onboarding.loading')}</p> : null}

            <DndContext
                sensors={sensors}
                collisionDetection={pointerWithin}
                autoScroll={autoScroll}
                onDragStart={(event: DragStartEvent) => setActiveId(Number(event.active.id))}
                onDragEnd={onDragEnd}
                onDragCancel={() => setActiveId(null)}
            >
                <div ref={boardRef} data-testid="onboarding-board" className="flex gap-2 overflow-x-auto overscroll-x-contain pb-4">
                    {stages.map((stage) => (
                        <BoardColumn
                            key={stage.key}
                            stage={stage}
                            state={columns[stage.key] ?? emptyColumn()}
                            highlighted={highlight === stage.key}
                            onRetry={() => loadColumn(stage.key)}
                            onMore={() => loadColumn(stage.key, true, columns[stage.key]?.nextCursor ?? null)}
                        >
                            {(columns[stage.key]?.items ?? []).map((card) => (
                                <OnboardingCardTile
                                    key={card.id}
                                    card={card}
                                    stages={stages}
                                    canEdit={canEdit}
                                    busy={busyIds.includes(card.id)}
                                    onOpen={() => setSelectedId(card.id)}
                                    onMove={(destination) => void moveCard(card, destination)}
                                    onSnooze={(days) => void mutateCard(card, 'followup', { snooze_days: days }, 'PUT')}
                                />
                            ))}
                        </BoardColumn>
                    ))}
                </div>
                <DragPortal>
                    <DragOverlay dropAnimation={null} zIndex={70}>
                        {activeCard ? (
                            <div className="w-[260px]">
                                <OnboardingCardTile card={activeCard} stages={stages} canEdit={false} busy={false} overlay onOpen={() => {}} onMove={() => {}} onSnooze={() => {}} />
                            </div>
                        ) : null}
                    </DragOverlay>
                </DragPortal>
            </DndContext>

            {selectedId !== null ? (
                <OnboardingDrawer
                    id={selectedId}
                    stages={stages}
                    people={meta?.people ?? []}
                    canEdit={canEdit}
                    busy={busyIds.includes(selectedId)}
                    onClose={() => setSelectedId(null)}
                    onMutate={mutateCard}
                />
            ) : null}
            {adding ? (
                <AddDialog
                    onClose={() => setAdding(false)}
                    onCreated={() => {
                        setAdding(false);
                        setReloadKey((value) => value + 1);
                    }}
                />
            ) : null}
            {settingsOpen ? (
                <SettingsDialog
                    staleDays={meta?.stale_days ?? initialStaleDays}
                    stages={stages}
                    onClose={() => setSettingsOpen(false)}
                    onSaved={() => {
                        setSettingsOpen(false);
                        setReloadKey((value) => value + 1);
                    }}
                />
            ) : null}
        </div>
    );
}

function BoardColumn({
    stage,
    state,
    highlighted,
    onRetry,
    onMore,
    children,
}: {
    stage: Stage;
    state: ColumnState;
    highlighted: boolean;
    onRetry: () => void;
    onMore: () => void;
    children: React.ReactNode;
}) {
    const { t, locale } = useI18n();
    const { setNodeRef, isOver } = useDroppable({ id: `column:${stage.key}` });
    const sectionRef = useRef<HTMLElement>(null);

    useEffect(() => {
        const element = sectionRef.current;
        if (!element || typeof IntersectionObserver === 'undefined') return;
        const observer = new IntersectionObserver(([entry]) => {
            element.dataset.columnVisible = entry.isIntersecting ? 'true' : 'false';
        }, { root: element.parentElement, threshold: 0.5 });
        observer.observe(element);
        return () => observer.disconnect();
    }, []);

    return (
        <section
            ref={sectionRef}
            data-testid="onboarding-column"
            data-column={stage.key}
            className={`flex w-[272px] shrink-0 flex-col rounded-card bg-card-2 p-2 ring-1 ${highlighted || isOver ? 'ring-accent' : 'ring-line'}`}
        >
            <header className="flex items-center justify-between gap-2 px-2 py-2">
                <h2 className="text-sm font-semibold">{locale === 'ar' ? stage.name_ar : stage.name_en}</h2>
                <span className="text-2xs text-faint tabular">{stage.count}</span>
            </header>
            <div ref={setNodeRef} className="flex min-h-40 flex-1 flex-col gap-2">
                {children}
                {state.loading ? (
                    <div role="status" aria-label={t('onboarding.loading')} className="space-y-2">
                        <div className="h-24 animate-pulse rounded-tile bg-card ring-1 ring-line motion-reduce:animate-none" />
                        <div className="h-24 animate-pulse rounded-tile bg-card ring-1 ring-line motion-reduce:animate-none" />
                    </div>
                ) : null}
                {!state.loading && state.loaded && state.items.length === 0 ? <p className="px-2 py-6 text-center text-sm text-muted">{t('onboarding.empty')}</p> : null}
                {state.error ? (
                    <div role="alert" className="space-y-2 px-2">
                        <p className="text-sm text-bad">{t('onboarding.error')}</p>
                        <Button type="button" variant="outline" className="min-h-11" onClick={onRetry}>{t('onboarding.retry')}</Button>
                    </div>
                ) : null}
                {state.hasMore && !state.error ? (
                    <Button type="button" variant="outline" className="min-h-11" disabled={state.loading} onClick={onMore}>{t('onboarding.load_more')}</Button>
                ) : null}
            </div>
        </section>
    );
}

function Modal({
    title,
    onClose,
    children,
}: {
    title: string;
    onClose: () => void;
    children: React.ReactNode;
}) {
    const panelRef = useRef<HTMLDivElement>(null);
    const { t } = useI18n();

    useEffect(() => {
        const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        panelRef.current?.focus();
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onClose();
            }
            if (event.key === 'Tab' && panelRef.current) {
                const controls = Array.from(panelRef.current.querySelectorAll<HTMLElement>('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), a[href], [tabindex]:not([tabindex="-1"])'));
                if (!controls.length) {
                    event.preventDefault();
                    return;
                }
                const first = controls[0];
                const last = controls[controls.length - 1];
                if (event.shiftKey && (document.activeElement === first || document.activeElement === panelRef.current)) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        };
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('keydown', onKeyDown);
            previous?.focus();
        };
    }, [onClose]);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div ref={panelRef} role="dialog" aria-modal="true" aria-labelledby="onboarding-modal-title" tabIndex={-1} className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-card bg-card p-4 shadow-card focus-visible:outline focus-visible:outline-2">
                <div className="mb-4 flex items-center justify-between gap-2">
                    <h2 id="onboarding-modal-title" className="text-lg font-semibold">{title}</h2>
                    <Button type="button" variant="outline" className="min-h-11" onClick={onClose}>{t('onboarding.close')}</Button>
                </div>
                {children}
            </div>
        </div>
    );
}

function AddDialog({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
    const { t } = useI18n();
    const [title, setTitle] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(false);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (!title.trim() || busy) return;
        setBusy(true);
        setError(false);
        try {
            await jsonFetch<{ card: Card }>('/onboarding/cards', { method: 'POST', body: JSON.stringify({ title: title.trim() }) });
            onCreated();
        } catch {
            setError(true);
            setBusy(false);
        }
    };

    return (
        <Modal title={t('onboarding.add')} onClose={onClose}>
            <form className="space-y-4" onSubmit={(event) => void submit(event)}>
                <label className="grid gap-1 text-sm">
                    <span>{t('onboarding.card_title')}</span>
                    <Input value={title} onChange={(event) => setTitle(event.target.value)} required maxLength={255} className="min-h-11" />
                </label>
                {error ? <p role="alert" className="text-sm text-bad">{t('onboarding.error')}</p> : null}
                <Button type="submit" className="min-h-11" disabled={busy}>{t('onboarding.save')}</Button>
            </form>
        </Modal>
    );
}

function SettingsDialog({
    staleDays,
    stages,
    onClose,
    onSaved,
}: {
    staleDays: number;
    stages: Stage[];
    onClose: () => void;
    onSaved: () => void;
}) {
    const { t } = useI18n();
    const [days, setDays] = useState(String(staleDays));
    const [rows, setRows] = useState(stages.map((stage) => ({ ...stage })));
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(false);

    useEffect(() => {
        let live = true;
        void jsonFetch<{ stale_days: number; stages?: Stage[] }>('/onboarding/settings')
            .then((result) => {
                if (!live) return;
                setDays(String(result.stale_days));
                if (result.stages) setRows(result.stages.map((stage) => ({ ...stage })));
                setLoading(false);
            })
            .catch(() => {
                if (!live) return;
                setError(true);
                setLoading(false);
            });
        return () => { live = false; };
    }, []);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (busy) return;
        setBusy(true);
        setError(false);
        try {
            await jsonFetch('/onboarding/settings', {
                method: 'PUT',
                body: JSON.stringify({
                    stale_days: Number(days),
                    stages: rows.map(({ key, name_en, name_ar, position, is_terminal }) => ({ key, name_en, name_ar, position, is_terminal })),
                }),
            });
            onSaved();
        } catch {
            setError(true);
            setBusy(false);
        }
    };

    return (
        <Modal title={t('onboarding.board_settings')} onClose={onClose}>
            {loading ? <p role="status">{t('onboarding.loading')}</p> : (
                <form className="space-y-4" onSubmit={(event) => void submit(event)}>
                    <label className="grid gap-1 text-sm">
                        <span>{t('onboarding.stale_days')}</span>
                        <Input type="number" min="1" required className="min-h-11" value={days} onChange={(event) => setDays(event.target.value)} />
                    </label>
                    {rows.map((row, index) => (
                        <fieldset key={row.key} className="space-y-2 rounded-tile p-2 ring-1 ring-line">
                            <legend className="text-sm font-semibold">{row.key}</legend>
                            {(['name_en', 'name_ar'] as const).map((field) => (
                                <label key={field} className="grid gap-1 text-sm">
                                    <span>{t(`onboarding.${field}`)}</span>
                                    <Input required className="min-h-11" value={row[field]} onChange={(event) => setRows((current) => current.map((item, i) => i === index ? { ...item, [field]: event.target.value } : item))} />
                                </label>
                            ))}
                            <label className="grid gap-1 text-sm">
                                <span>{t('onboarding.position')}</span>
                                <Input type="number" required className="min-h-11" value={row.position} onChange={(event) => setRows((current) => current.map((item, i) => i === index ? { ...item, position: Number(event.target.value) } : item))} />
                            </label>
                        </fieldset>
                    ))}
                    {error ? <p role="alert" className="text-sm text-bad">{t('onboarding.error')}</p> : null}
                    <Button type="submit" className="min-h-11" disabled={busy || error}>{t('onboarding.save')}</Button>
                </form>
            )}
        </Modal>
    );
}
