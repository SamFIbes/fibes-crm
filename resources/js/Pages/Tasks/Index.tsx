import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import { ListTodo } from 'lucide-react';
import { PageSkeleton } from '@/components/feedback/PageSkeleton';
import { EmptyState } from '@/components/feedback/EmptyState';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { MonthBoard } from '@/components/calendar/MonthBoard';
import { WeekBoard } from '@/components/calendar/WeekBoard';
import { monthGrid, weekDates } from '@/lib/calendar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { FilterSelect } from '@/components/ui/filter-select';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type TaskRow = {
    id: number | string;
    title: string;
    status?: string;
    priority?: string;
    due_at: string | null;
    overdue?: boolean;
    event?: string;
    draggable?: boolean;
    kind?: string;
};
type Holiday = { date: string; name_en: string; name_ar: string };

const views = ['list', 'day', 'week', 'month'] as const;

export default function TasksIndex({
    tasks,
    events,
    holidays,
    filters,
    people,
    can_create,
    can_assign,
    can_see_all,
    rules,
}: {
    tasks: TaskRow[];
    events: TaskRow[];
    holidays: Holiday[];
    filters: { scope: string; view: string; date: string; status: string; priority: string; overdue: boolean };
    people: { id: number; name: string }[];
    can_create: boolean;
    can_assign: boolean;
    can_see_all: boolean;
    can_schedule: boolean;
    rules: { id: number; title: string; frequency: string; time_of_day: string }[];
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const form = useForm({ title: '', due_at: '', priority: 'normal', remind: true, assignee_id: '' });
    const rule = useForm({ title: '', frequency: 'weekly', time_of_day: '09:00', weekday: '1', day_of_month: '1', priority: 'normal' });
    const holidayName = (day: string) => {
        const hit = holidays.find((holiday) => holiday.date === day);
        return hit ? (locale === 'ar' ? hit.name_ar : hit.name_en) : '';
    };

    useEffect(() => {
        const offStart = router.on('start', () => setLoading(true));
        const offFinish = router.on('finish', () => setLoading(false));
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            if (target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return;
            if ((event.key === 'n' || event.key === 'N') && can_create && !event.metaKey && !event.ctrlKey) {
                event.preventDefault();
                setOpen(true);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => {
            offStart();
            offFinish();
            window.removeEventListener('keydown', onKey);
        };
    }, [can_create]);

    const visit = (next: Record<string, string | boolean>) => {
        router.get('/tasks', {
            scope: filters.scope,
            view: filters.view,
            date: filters.date,
            status: filters.status,
            priority: filters.priority,
            overdue: filters.overdue ? 1 : '',
            ...next,
        }, { preserveState: true, preserveScroll: true });
    };

    const move = (id: string, day: string) => {
        const event = events.find((item) => String(item.id) === id);
        if (event && event.draggable === false) return;
        router.post('/calendar/move', { id, day }, { preserveScroll: true });
    };

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.post('/tasks', { preserveScroll: true, onSuccess: () => { form.reset('title'); setOpen(false); } });
    };

    const weekDays = filters.view === 'day' ? [filters.date] : weekDates(filters.date);
    const monthDays = monthGrid(filters.date);
    const boardItems = events.filter((item) => item.due_at).map((item) => ({ id: String(item.id), dayId: String(item.due_at).slice(0, 10), title: item.title }));

    return (
        <AppShell>
            <Head title={t('tasks.title')} />
            {loading ? <PageSkeleton /> : null}
            <div data-testid="tasks-page">
                <PageHeader title={t('tasks.title')} sub={t('tasks.subtitle')} actions={can_create ? <Button type="button" onClick={() => setOpen(true)}>{t('tasks.add')}</Button> : null} />
                <p className="mb-3 text-xs text-faint">{t('tasks.shortcut')}</p>
                {page.props.flash.status ? <p className="mb-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <Input dir="ltr" type="date" aria-label={t('tasks.due')} value={filters.date} onChange={(event) => visit({ date: event.target.value })} />
                    {views.map((view) => (
                        <Button key={view} type="button" size="sm" variant={filters.view === view ? 'default' : 'outline'} onClick={() => visit({ view })}>{t(`tasks.view_${view}`)}</Button>
                    ))}
                    <Button type="button" size="sm" variant={filters.scope === 'mine' ? 'default' : 'outline'} onClick={() => visit({ scope: 'mine' })}>{t('tasks.mine')}</Button>
                    <Button type="button" size="sm" variant={filters.scope === 'team' ? 'default' : 'outline'} onClick={() => visit({ scope: 'team' })}>{t('tasks.team')}</Button>
                    {can_see_all ? <Button type="button" size="sm" variant={filters.scope === 'all' ? 'default' : 'outline'} onClick={() => visit({ scope: 'all' })}>{t('tasks.all')}</Button> : null}
                    <FilterSelect label={t('tasks.status')} value={filters.status} onChange={(status) => visit({ status })} options={[{ value: '', label: t('filters.any') }, { value: 'open', label: t('tasks.open') }, { value: 'done', label: t('tasks.done_status') }]} />
                    <FilterSelect label={t('tasks.priority')} value={filters.priority} onChange={(priority) => visit({ priority })} options={[{ value: '', label: t('filters.any') }, ...['low', 'normal', 'high', 'urgent'].map((priority) => ({ value: priority, label: t(`tasks.priority_${priority}`) }))]} />
                    <Button type="button" size="sm" variant={filters.overdue ? 'default' : 'outline'} onClick={() => visit({ overdue: !filters.overdue })}>{t('tasks.overdue')}</Button>
                </div>
                {open && can_create ? (
                    <form className="card mb-4 grid gap-3 p-4 md:grid-cols-4" onSubmit={save} data-testid="task-drawer">
                        <Field id="new-title" label={t('tasks.title_field')} error={form.errors.title}><Input id="new-title" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} required /></Field>
                        <Field id="new-due" label={t('tasks.due')}><Input id="new-due" dir="ltr" type="datetime-local" value={form.data.due_at} onChange={(event) => form.setData('due_at', event.target.value)} /></Field>
                        <Field id="new-priority" label={t('tasks.priority')}>
                            <select id="new-priority" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.priority} onChange={(event) => form.setData('priority', event.target.value)}>
                                {['low', 'normal', 'high', 'urgent'].map((priority) => <option key={priority} value={priority}>{t(`tasks.priority_${priority}`)}</option>)}
                            </select>
                        </Field>
                        {can_assign ? (
                            <Field id="new-who" label={t('tasks.assignee')}>
                                <select id="new-who" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.assignee_id} onChange={(event) => form.setData('assignee_id', event.target.value)}>
                                    <option value="">{t('tasks.me')}</option>
                                    {people.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                                </select>
                            </Field>
                        ) : null}
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.remind} onChange={(event) => form.setData('remind', event.target.checked)} /> {t('tasks.remind')}</label>
                        <div className="md:col-span-4"><Button type="submit">{t('tasks.save')}</Button></div>
                    </form>
                ) : null}
                {filters.view === 'list' ? (
                    tasks.length === 0 ? <div className="card"><EmptyState icon={<ListTodo className="size-5" />} title={t('tasks.empty')} body={t('tasks.shortcut')} /></div> : (
                        <ul className="card divide-y divide-line">
                            {tasks.map((task) => (
                                <li key={task.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                                    <div>
                                        <p className="font-semibold">{task.title}</p>
                                        <p className="text-xs text-muted" dir="ltr">{task.due_at || '—'}</p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {task.overdue ? <Badge tone="bad">{t('tasks.overdue')}</Badge> : null}
                                        <Badge tone={task.priority === 'urgent' || task.priority === 'high' ? 'warn' : 'neutral'}>{t(`tasks.priority_${task.priority ?? 'normal'}`)}</Badge>
                                        {task.status === 'open' ? <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/tasks/${task.id}/done`, {}, { preserveScroll: true })}>{t('tasks.complete')}</Button> : <span className="text-xs text-muted">{t('tasks.done_status')}</span>}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )
                ) : null}
                {filters.view === 'week' || filters.view === 'day' ? (
                    <WeekBoard
                        days={weekDays.map((day) => ({ id: day, label: `${day.slice(5)} ${holidayName(day)}`.trim() }))}
                        items={boardItems}
                        onMove={move}
                        empty={t('tasks.empty')}
                    />
                ) : null}
                {filters.view === 'month' ? (
                    <MonthBoard
                        days={monthDays.map((day) => ({ id: day, label: day.slice(8), note: holidayName(day), inMonth: day.slice(0, 7) === filters.date.slice(0, 7) }))}
                        items={boardItems}
                        onMove={move}
                    />
                ) : null}
                {can_create ? (
                    <form className="card mt-4 grid gap-3 p-4 md:grid-cols-4" onSubmit={(event) => { event.preventDefault(); rule.post('/task-rules', { preserveScroll: true }); }}>
                        <h2 className="md:col-span-4 text-sm font-semibold">{t('tasks.recurring')}</h2>
                        <Field id="rule-title" label={t('tasks.title_field')}><Input id="rule-title" value={rule.data.title} onChange={(event) => rule.setData('title', event.target.value)} required /></Field>
                        <Field id="rule-freq" label={t('tasks.frequency')}>
                            <select id="rule-freq" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={rule.data.frequency} onChange={(event) => rule.setData('frequency', event.target.value)}>
                                <option value="daily">{t('tasks.daily')}</option>
                                <option value="weekly">{t('tasks.weekly')}</option>
                                <option value="monthly">{t('tasks.monthly')}</option>
                            </select>
                        </Field>
                        <Field id="rule-time" label={t('tasks.time')}><Input id="rule-time" dir="ltr" type="time" value={rule.data.time_of_day} onChange={(event) => rule.setData('time_of_day', event.target.value)} /></Field>
                        <div className="flex items-end"><Button type="submit" variant="outline">{t('tasks.save_rule')}</Button></div>
                        <ul className="md:col-span-4 text-sm text-muted">
                            {rules.map((item) => <li key={item.id}>{item.title} · {t(`tasks.${item.frequency}`)} · <span dir="ltr">{item.time_of_day}</span></li>)}
                        </ul>
                    </form>
                ) : null}
            </div>
        </AppShell>
    );
}
