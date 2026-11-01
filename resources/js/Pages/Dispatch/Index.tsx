import { DndContext, PointerSensor, useDraggable, useDroppable, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState, type ReactNode } from 'react';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { FilterSelect } from '@/components/ui/filter-select';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { QuickTask } from '@/components/tasks/QuickTask';
import { CoverageMap } from '@/components/maps/CoverageMap';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type JobCard = {
    id: number;
    number: string;
    type: string;
    status: string;
    window_start: string | null;
    window_end: string | null;
    required_skill: string | null;
    customer: { name_en: string; name_ar: string; area_en: string | null };
};
type Tech = { id: number; name: string; skills: string[]; jobs: JobCard[] };

function Card({ job }: { job: JobCard }) {
    const { locale, t } = useI18n();
    const page = usePage<SharedProps>();
    const drag = useDraggable({ id: `job:${job.id}`, data: job });

    return (
        <div ref={drag.setNodeRef} style={{ transform: CSS.Translate.toString(drag.transform) }} className="rounded-tile bg-card p-2 text-sm shadow-card ring-1 ring-line">
            <div className="cursor-grab" {...drag.attributes} {...drag.listeners}>
                <div className="font-semibold" dir="ltr">{job.number}</div>
                <div>{locale === 'ar' ? job.customer.name_ar : job.customer.name_en}</div>
                <div className="text-xs text-muted">{t(`jobs.type_${job.type}`)}{job.window_start ? ` · ${job.window_start.slice(11)}` : ''}</div>
            </div>
            {page.props.auth.abilities?.tasks_create ? <QuickTask jobId={job.id} /> : null}
        </div>
    );
}

function Column({ id, title, children }: { id: string; title: string; children: ReactNode }) {
    const drop = useDroppable({ id });

    return (
        <section ref={drop.setNodeRef} className={`min-h-40 rounded-tile p-3 ${drop.isOver ? 'bg-accent/10 ring-1 ring-accent' : 'bg-card-2'}`}>
            <h2 className="mb-2 text-sm font-semibold">{title}</h2>
            <div className="space-y-2">{children}</div>
        </section>
    );
}

export default function DispatchIndex({
    date,
    mode,
    area_id,
    areas,
    skills,
    types,
    unassigned,
    technicians,
    pins,
    technicians_here,
    tiles,
}: {
    date: string;
    mode: 'day' | 'week';
    area_id: number | null;
    areas: { id: number; name_en: string; name_ar: string }[];
    skills: string[];
    types: string[];
    unassigned: JobCard[];
    technicians: Tech[];
    pins: { id: number; number: string | null; name: string | null; latitude: number; longitude: number; technician_id: number | null; order: number; maps: string | null }[];
    technicians_here: { id: number; name: string; latitude: number; longitude: number }[];
    tiles: string;
}) {
    const { t, locale } = useI18n();
    const page = usePage<SharedProps>();
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 6 } }));
    const form = useForm({ type: 'repair', customer_id: '', lookup: '', required_skill: '', window_start: `${date}T09:00`, window_end: `${date}T11:00`, notes: '' });
    const [matches, setMatches] = useState<{ id: number; name_en: string; name_ar: string; account_no: string }[]>([]);
    const days = Array.from({ length: 7 }, (_, index) => {
        const day = new Date(`${date}T12:00:00`);
        day.setDate(day.getDate() + index);

        return day.toISOString().slice(0, 10);
    });

    const visit = (next: Record<string, string>) => router.get('/dispatch', { date, mode, area_id: area_id ?? '', ...next }, { preserveState: true });

    const onDragEnd = (event: DragEndEvent) => {
        const over = event.over?.id;
        const job = event.active.data.current as JobCard | undefined;
        if (!over || !job) return;
        const target = String(over);
        if (target.startsWith('tech:')) {
            const start = job.window_start ?? `${date} 09:00`;
            const end = job.window_end ?? `${date} 11:00`;
            router.patch(`/dispatch/jobs/${job.id}`, { technician_id: Number(target.slice(5)), window_start: start.replace('T', ' '), window_end: end.replace('T', ' ') }, { preserveScroll: true });
        }
        if (target === 'unassigned') {
            router.patch(`/dispatch/jobs/${job.id}`, { technician_id: null }, { preserveScroll: true });
        }
        if (target.startsWith('day:')) {
            const day = target.slice(4);
            const start = `${day} ${(job.window_start ?? `${date} 09:00`).slice(11)}`;
            const end = `${day} ${(job.window_end ?? `${date} 11:00`).slice(11)}`;
            router.patch(`/dispatch/jobs/${job.id}`, { window_start: start, window_end: end, status: 'rescheduled' }, { preserveScroll: true });
        }
    };

    return (
        <AppShell>
            <Head title={t('jobs.dispatch')} />
            <PageHeader title={t('jobs.dispatch')} sub={t('jobs.dispatch_sub')} />
            {page.props.flash.status ? <p className="mb-3 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
            <div className="mb-4 flex flex-wrap items-center gap-2" data-testid="dispatch-board">
                <Input dir="ltr" type="date" aria-label={t('jobs.date')} value={date} onChange={(event) => visit({ date: event.target.value })} />
                <Button type="button" variant={mode === 'day' ? 'default' : 'outline'} onClick={() => visit({ mode: 'day' })}>{t('jobs.day')}</Button>
                <Button type="button" variant={mode === 'week' ? 'default' : 'outline'} onClick={() => visit({ mode: 'week' })}>{t('jobs.week')}</Button>
                <FilterSelect label={t('customers.area')} value={area_id ? String(area_id) : ''} onChange={(value) => visit({ area_id: value })} options={[{ value: '', label: t('filters.any') }, ...areas.map((area) => ({ value: String(area.id), label: locale === 'ar' ? area.name_ar : area.name_en }))]} />
            </div>
            <div className="mb-4 grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_16rem]">
                <CoverageMap
                    testId="dispatch-map"
                    tileUrl={tiles}
                    height={320}
                    pins={[
                        ...pins.map((pin) => ({ id: pin.id, lat: pin.latitude, lng: pin.longitude, label: `${pin.number ?? ''} ${pin.name ?? ''}`.trim(), order: pin.order, tone: 'job' as const })),
                        ...technicians_here.map((tech) => ({ id: `tech-${tech.id}`, lat: tech.latitude, lng: tech.longitude, label: tech.name, tone: 'tech' as const })),
                    ]}
                />
                <section className="card p-4">
                    <h2 className="text-sm font-semibold">{t('coverage.route')}</h2>
                    <ol className="mt-2 space-y-1 text-sm">
                        {[...pins].sort((left, right) => left.order - right.order || left.id - right.id).map((pin) => (
                            <li key={pin.id} dir="ltr">{pin.order}. {pin.number}</li>
                        ))}
                    </ol>
                    {technicians_here.length > 0 ? <p className="mt-3 text-xs text-muted">{t('coverage.tech_here')}</p> : null}
                </section>
            </div>
            <form className="card mb-4 grid gap-3 p-4 md:grid-cols-5" onSubmit={(event: FormEvent) => { event.preventDefault(); form.post('/dispatch'); }}>
                <Field id="job-customer" label={t('tickets.phone_search')}>
                    <Input id="job-customer" value={form.data.lookup} onChange={async (event) => {
                        form.setData('lookup', event.target.value);
                        const response = await fetch(`/tickets/lookup?q=${encodeURIComponent(event.target.value)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                        if (response.ok) setMatches(await response.json());
                    }} />
                    {matches.length > 0 ? <ul className="mt-1 rounded-ctl border border-line bg-card text-sm">{matches.map((match) => <li key={match.id}><button type="button" className="w-full px-2 py-1 text-start" onClick={() => { form.setData('customer_id', String(match.id)); form.setData('lookup', locale === 'ar' ? match.name_ar : match.name_en); setMatches([]); }}>{locale === 'ar' ? match.name_ar : match.name_en} · {match.account_no}</button></li>)}</ul> : null}
                </Field>
                <Field id="job-type" label={t('jobs.type')}>
                    <select id="job-type" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.type} onChange={(event) => form.setData('type', event.target.value)}>
                        {types.map((type) => <option key={type} value={type}>{t(`jobs.type_${type}`)}</option>)}
                    </select>
                </Field>
                <Field id="job-skill" label={t('jobs.skill')}>
                    <select id="job-skill" className="h-10 w-full rounded-ctl border border-input bg-card px-2 text-sm" value={form.data.required_skill} onChange={(event) => form.setData('required_skill', event.target.value)}>
                        <option value="">{t('filters.any')}</option>
                        {skills.map((skill) => <option key={skill} value={skill}>{t(`jobs.skill_${skill}`)}</option>)}
                    </select>
                </Field>
                <Field id="job-start" label={t('jobs.window')}><Input id="job-start" dir="ltr" type="datetime-local" value={form.data.window_start} onChange={(event) => form.setData('window_start', event.target.value)} /></Field>
                <div className="flex items-end"><Button type="submit">{t('jobs.new')}</Button></div>
            </form>
            <DndContext sensors={sensors} onDragEnd={onDragEnd}>
                <div className="grid gap-3 lg:grid-cols-4">
                    <Column id="unassigned" title={t('jobs.unassigned')}>
                        {unassigned.map((job) => <Card key={job.id} job={job} />)}
                    </Column>
                    {mode === 'day' ? technicians.map((tech) => (
                        <Column key={tech.id} id={`tech:${tech.id}`} title={tech.name}>
                            <div className="mb-2 flex flex-wrap gap-1">
                                {skills.map((skill) => (
                                    <button key={skill} type="button" className={`rounded-full px-2 py-0.5 text-2xs ${tech.skills.includes(skill) ? 'bg-accent text-on-accent' : 'bg-card ring-1 ring-line'}`} onClick={() => {
                                        const next = tech.skills.includes(skill) ? tech.skills.filter((item) => item !== skill) : [...tech.skills, skill];
                                        router.post(`/dispatch/technicians/${tech.id}/skills`, { skills: next }, { preserveScroll: true });
                                    }}>{t(`jobs.skill_${skill}`)}</button>
                                ))}
                            </div>
                            {tech.jobs.map((job) => <Card key={job.id} job={job} />)}
                        </Column>
                    )) : days.map((day) => (
                        <Column key={day} id={`day:${day}`} title={day}>
                            {technicians.flatMap((tech) => tech.jobs).filter((job) => job.window_start?.startsWith(day)).map((job) => <Card key={`${day}-${job.id}`} job={job} />)}
                        </Column>
                    ))}
                </div>
            </DndContext>
            <section className="card mt-4 p-4">
                <h2 className="text-sm font-semibold">{t('jobs.pins')}</h2>
                {pins.length === 0 ? <p className="mt-2 text-sm text-muted">{t('jobs.no_pins')}</p> : (
                    <ul className="mt-2 space-y-1 text-sm">
                        {pins.map((pin) => <li key={pin.id}><a className="font-semibold text-accent-ink" href={pin.maps ?? '#'} dir="ltr">{pin.number}</a> · {pin.name}</li>)}
                    </ul>
                )}
            </section>
        </AppShell>
    );
}
