import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import { displayMinutes, toMinutes, type DurationUnit } from '@/lib/slaUnits';
import type { SharedProps } from '@/types';

type Company = {
    name: string;
    name_ar: string;
    email: string;
    phone_e164?: string;
    phone_display?: string;
    address_en: string;
    address_ar: string;
};

type Priority = { respond_minutes: number; resolve_minutes: number };

type Sla = {
    clock: string;
    support_hours: string;
    lead_first_response_minutes: number;
    lead_qualify_minutes?: number;
    lead_handoff_minutes?: number;
    priorities: Record<'P1' | 'P2' | 'P3' | 'P4', Priority>;
};

type HolidayRow = { id: number; observed_on: string; name_en: string; name_ar: string; kind?: string; confirmed?: boolean };

const priorities = ['P1', 'P2', 'P3', 'P4'] as const;

function DurationField({ id, label, minutes, kind, disabled, onMinutes }: { id: string; label: string; minutes: number; kind: 'respond' | 'resolve'; disabled: boolean; onMinutes: (minutes: number) => void }) {
    const { t } = useI18n();
    const shown = displayMinutes(minutes, kind);
    const units: DurationUnit[] = kind === 'resolve' ? ['hours', 'days'] : ['minutes', 'hours'];

    return (
        <Field id={id} label={label}>
            <div className="flex gap-2">
                <Input id={id} dir="ltr" type="number" min={1} disabled={disabled} value={shown.value} onChange={(event) => onMinutes(toMinutes(Number(event.target.value), shown.unit))} />
                <select aria-label={label} className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" disabled={disabled} value={shown.unit} onChange={(event) => onMinutes(toMinutes(shown.value, event.target.value as DurationUnit))}>
                    {units.map((unit) => <option key={unit} value={unit}>{t(`sla_units.${unit}`)}</option>)}
                </select>
            </div>
        </Field>
    );
}

type TierMember = { id: number; name: string; on_duty: boolean };
type Tier = { id: number; level: number; name_en: string; name_ar: string; team_id: number | null; members: TierMember[] };
type Person = { id: number; name: string; role: string };
type TeamRow = { id: number; name_en: string; name_ar: string };

export default function SettingsEdit({
    company,
    sla,
    holidays,
    categories = [],
    wrap_up_codes = [],
    escalation = { on_warning: 'none', on_breach: 'next_tier' },
    tiers = [],
    teams = [],
    people = [],
    can_manage,
    can_integrations = false,
    commercial,
}: {
    company: Company;
    sla: Sla;
    holidays: HolidayRow[];
    categories?: { id: number; name_en: string; name_ar: string }[];
    wrap_up_codes?: { id: number; name_en: string; name_ar: string }[];
    escalation?: { on_warning: string; on_breach: string };
    tiers?: Tier[];
    teams?: TeamRow[];
    people?: Person[];
    can_manage: boolean;
    can_integrations?: boolean;
    commercial: Record<string, string | number | boolean>;
}) {
    const { t, locale } = useI18n();
    const { flash } = usePage<SharedProps>().props;
    const form = useForm({
        company: {
            name: company.name,
            name_ar: company.name_ar,
            email: company.email ?? '',
            phone: company.phone_display || company.phone_e164 || '',
            address_en: company.address_en ?? '',
            address_ar: company.address_ar ?? '',
        },
        sla,
    });
    const holiday = useForm({ observed_on: '', name_en: '', name_ar: '' });
    const category = useForm({ name_en: '', name_ar: '' });
    const wrap = useForm({ name_en: '', name_ar: '' });
    const checklist = useForm({ ticket_category_id: String(categories[0]?.id ?? ''), job_type: '', label_en: '', label_ar: '' });
    const rule = useForm({ on_warning: escalation.on_warning, on_breach: escalation.on_breach });
    const figures = useForm({ commercial });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put('/settings');
    };

    return (
        <AppShell>
            <Head title={t('settings_page.title')} />
            <PageHeader title={t('settings_page.title')} sub={t('settings_page.subtitle')} />
            {can_integrations ? <p className="mb-4"><a className="text-sm font-semibold text-accent-ink" href="/settings/integrations">{t('integrations.open')}</a></p> : null}
            {flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{flash.status}</p> : null}
            <form onSubmit={save} className="grid gap-4" data-testid="settings-page">
                <section className="card space-y-4 p-5">
                    <h2 className="text-sm font-semibold">{t('settings_page.company')}</h2>
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field id="name" label={t('settings_page.name')} error={form.errors['company.name']}>
                            <Input id="name" value={form.data.company.name} disabled={!can_manage} onChange={(event) => form.setData('company', { ...form.data.company, name: event.target.value })} />
                        </Field>
                        <Field id="name_ar" label={t('settings_page.name_ar')} error={form.errors['company.name_ar']}>
                            <Input id="name_ar" value={form.data.company.name_ar} disabled={!can_manage} onChange={(event) => form.setData('company', { ...form.data.company, name_ar: event.target.value })} />
                        </Field>
                        <Field id="email" label={t('settings_page.email')} error={form.errors['company.email']}>
                            <Input id="email" dir="ltr" value={form.data.company.email} disabled={!can_manage} onChange={(event) => form.setData('company', { ...form.data.company, email: event.target.value })} />
                        </Field>
                        <Field id="phone" label={t('settings_page.phone')} error={form.errors['company.phone']}>
                            <Input id="phone" dir="ltr" value={form.data.company.phone} disabled={!can_manage} onChange={(event) => form.setData('company', { ...form.data.company, phone: event.target.value })} />
                        </Field>
                        <Field id="address_en" label={t('settings_page.address')}>
                            <Input id="address_en" value={form.data.company.address_en} disabled={!can_manage} onChange={(event) => form.setData('company', { ...form.data.company, address_en: event.target.value })} />
                        </Field>
                        <Field id="address_ar" label={t('settings_page.address_ar')}>
                            <Input id="address_ar" value={form.data.company.address_ar} disabled={!can_manage} onChange={(event) => form.setData('company', { ...form.data.company, address_ar: event.target.value })} />
                        </Field>
                    </div>
                </section>
                <section className="card space-y-4 p-5">
                    <h2 className="text-sm font-semibold">{t('settings_page.sla')}</h2>
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field id="support_hours" label={t('settings_page.support_hours')}>
                            <Input id="support_hours" value={form.data.sla.support_hours} disabled={!can_manage} onChange={(event) => form.setData('sla', { ...form.data.sla, support_hours: event.target.value })} />
                        </Field>
                        <Field id="clock" label={t('settings_page.clock')}>
                            <Input id="clock" value={t('settings_page.calendar')} disabled />
                        </Field>
                        <Field id="lead" label={t('settings_page.lead')}>
                            <Input id="lead" dir="ltr" type="number" value={form.data.sla.lead_first_response_minutes} disabled={!can_manage} onChange={(event) => form.setData('sla', { ...form.data.sla, lead_first_response_minutes: Number(event.target.value) })} />
                        </Field>
                        <Field id="lead_qualify" label={t('settings_page.lead_qualify')}>
                            <Input id="lead_qualify" dir="ltr" type="number" value={form.data.sla.lead_qualify_minutes ?? ''} disabled={!can_manage} onChange={(event) => form.setData('sla', { ...form.data.sla, lead_qualify_minutes: Number(event.target.value) })} />
                        </Field>
                        <Field id="lead_handoff" label={t('settings_page.lead_handoff')}>
                            <Input id="lead_handoff" dir="ltr" type="number" value={form.data.sla.lead_handoff_minutes ?? ''} disabled={!can_manage} onChange={(event) => form.setData('sla', { ...form.data.sla, lead_handoff_minutes: Number(event.target.value) })} />
                        </Field>
                    </div>
                    <div className="grid gap-3 md:grid-cols-2">
                        {priorities.map((key) => (
                            <div key={key} className="rounded-tile bg-card-2 p-3">
                                <p className="mb-2 text-sm font-semibold">{key}</p>
                                <div className="grid grid-cols-1 gap-2">
                                    <DurationField
                                        id={`${key}-respond`}
                                        label={t('settings_page.respond')}
                                        minutes={form.data.sla.priorities[key].respond_minutes}
                                        kind="respond"
                                        disabled={!can_manage}
                                        onMinutes={(minutes) => form.setData('sla', { ...form.data.sla, priorities: { ...form.data.sla.priorities, [key]: { ...form.data.sla.priorities[key], respond_minutes: minutes } } })}
                                    />
                                    <DurationField
                                        id={`${key}-resolve`}
                                        label={t('settings_page.resolve')}
                                        minutes={form.data.sla.priorities[key].resolve_minutes}
                                        kind="resolve"
                                        disabled={!can_manage}
                                        onMinutes={(minutes) => form.setData('sla', { ...form.data.sla, priorities: { ...form.data.sla.priorities, [key]: { ...form.data.sla.priorities[key], resolve_minutes: minutes } } })}
                                    />
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
                {can_manage ? <Button type="submit" disabled={form.processing}>{t('settings_page.save')}</Button> : null}
            </form>
            <form className="card mt-4 space-y-4 p-5" data-testid="commercial-settings" onSubmit={(event) => { event.preventDefault(); figures.put('/settings/commercial'); }}>
                <h2 className="text-sm font-semibold">{t('settings_page.commercial')}</h2>
                <p className="text-sm text-muted">{t('settings_page.commercial_help')}</p>
                <div className="grid gap-3 md:grid-cols-2">
                    {Object.keys(commercial).map((key) => (
                        <Field key={key} id={`c-${key}`} label={t(`settings_page.c_${key}`)}>
                            {typeof commercial[key] === 'boolean' ? (
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={Boolean(figures.data.commercial[key])} disabled={!can_manage} onChange={(event) => figures.setData('commercial', { ...figures.data.commercial, [key]: event.target.checked })} />
                                    {t(`settings_page.c_${key}`)}
                                </label>
                            ) : key === 'proration' ? (
                                <select id={`c-${key}`} className="h-10 w-full rounded-ctl border border-input bg-card px-3 text-sm" disabled={!can_manage} value={String(figures.data.commercial[key])} onChange={(event) => figures.setData('commercial', { ...figures.data.commercial, [key]: event.target.value })}>
                                    <option value="daily">{t('settings_page.proration_daily')}</option>
                                    <option value="full">{t('settings_page.proration_full')}</option>
                                </select>
                            ) : (
                                <Input id={`c-${key}`} dir="ltr" disabled={!can_manage} value={String(figures.data.commercial[key] ?? '')} onChange={(event) => figures.setData('commercial', { ...figures.data.commercial, [key]: event.target.value })} />
                            )}
                        </Field>
                    ))}
                </div>
                {can_manage ? <Button type="submit">{t('settings_page.save')}</Button> : null}
            </form>
            <section className="card mt-4 p-5">
                <h2 className="text-sm font-semibold">{t('settings_page.holidays')}</h2>
                <p className="mt-1 text-sm text-muted">{t('settings_page.holiday_help')}</p>
                {can_manage ? (
                    <form
                        className="mt-3 grid gap-2 md:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            holiday.post('/settings/holidays', { preserveScroll: true, onSuccess: () => holiday.reset() });
                        }}
                    >
                        <Input dir="ltr" type="date" aria-label={t('settings_page.holiday_date')} value={holiday.data.observed_on} onChange={(event) => holiday.setData('observed_on', event.target.value)} required />
                        <Input aria-label={t('admin.name_en')} value={holiday.data.name_en} onChange={(event) => holiday.setData('name_en', event.target.value)} required />
                        <Input aria-label={t('admin.name_ar')} value={holiday.data.name_ar} onChange={(event) => holiday.setData('name_ar', event.target.value)} required />
                        <Button type="submit">{t('settings_page.add_holiday')}</Button>
                    </form>
                ) : null}
                <ul className="mt-4 divide-y divide-line" data-testid="holiday-list">
                    {holidays.map((row) => (
                        <li key={row.id} className="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                            <span>
                                <span dir="ltr">{row.observed_on}</span>
                                <span className="ms-3">{locale === 'ar' ? row.name_ar : row.name_en}</span>
                                {row.kind === 'islamic' ? <span className="ms-2 text-xs text-faint">{t('settings_page.holiday_islamic')}</span> : null}
                                {row.confirmed === false ? <span className="ms-2 text-xs font-semibold text-warn">{t('settings_page.holiday_to_confirm')}</span> : null}
                            </span>
                            {can_manage ? (
                                <span className="flex items-center gap-2">
                                    {row.confirmed === false ? (
                                        <form className="flex items-center gap-2" onSubmit={(event) => {
                                            event.preventDefault();
                                            const date = (event.currentTarget.elements.namedItem('observed_on') as HTMLInputElement).value;
                                            router.patch(`/settings/holidays/${row.id}`, { observed_on: date, confirmed: true }, { preserveScroll: true });
                                        }}>
                                            <Input name="observed_on" dir="ltr" type="date" defaultValue={row.observed_on} aria-label={t('settings_page.holiday_date')} />
                                            <Button type="submit" size="sm" variant="outline">{t('settings_page.holiday_confirm')}</Button>
                                        </form>
                                    ) : null}
                                    <button type="button" className="text-xs font-semibold text-bad" onClick={() => router.delete(`/settings/holidays/${row.id}`, { preserveScroll: true })}>
                                        {t('settings_page.remove')}
                                    </button>
                                </span>
                            ) : null}
                        </li>
                    ))}
                </ul>
            </section>
            <section className="card mt-4 space-y-4 p-5" data-testid="escalation-settings">
                <h2 className="text-sm font-semibold">{t('settings_page.ladder')}</h2>
                <p className="text-sm text-muted">{t('settings_page.ladder_help')}</p>
                <form className="grid gap-3 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); rule.put('/settings/escalation', { preserveScroll: true }); }}>
                    <Field id="on_warning" label={t('settings_page.on_warning')}>
                        <select id="on_warning" className="h-10 w-full rounded-ctl border border-input bg-card px-3 text-sm" disabled={!can_manage} value={rule.data.on_warning} onChange={(event) => rule.setData('on_warning', event.target.value)}>
                            <option value="none">{t('settings_page.rule_none')}</option>
                            <option value="next_tier">{t('settings_page.rule_next')}</option>
                        </select>
                    </Field>
                    <Field id="on_breach" label={t('settings_page.on_breach')}>
                        <select id="on_breach" className="h-10 w-full rounded-ctl border border-input bg-card px-3 text-sm" disabled={!can_manage} value={rule.data.on_breach} onChange={(event) => rule.setData('on_breach', event.target.value)}>
                            <option value="none">{t('settings_page.rule_none')}</option>
                            <option value="next_tier">{t('settings_page.rule_next')}</option>
                        </select>
                    </Field>
                    {can_manage ? <div className="md:col-span-2"><Button type="submit">{t('settings_page.save_rule')}</Button></div> : null}
                </form>
                <div className="grid gap-3">
                    {tiers.map((tier) => <TierEditor key={tier.id} tier={tier} teams={teams} people={people} canManage={can_manage} />)}
                </div>
            </section>
            <section className="card mt-4 p-5">
                <h2 className="text-sm font-semibold">{t('settings_page.categories')}</h2>
                <ul className="mt-3 divide-y divide-line">
                    {categories.map((row) => (
                        <li key={row.id} className="py-2 text-sm">{row.name_en} <span className="text-muted">{row.name_ar}</span></li>
                    ))}
                </ul>
                {can_manage ? (
                    <form className="mt-3 grid gap-2 md:grid-cols-3" onSubmit={(event) => { event.preventDefault(); category.post('/settings/ticket-categories', { preserveScroll: true, onSuccess: () => category.reset() }); }}>
                        <Input aria-label={t('admin.name_en')} value={category.data.name_en} onChange={(event) => category.setData('name_en', event.target.value)} required />
                        <Input aria-label={t('admin.name_ar')} value={category.data.name_ar} onChange={(event) => category.setData('name_ar', event.target.value)} required />
                        <Button type="submit">{t('settings_page.add_category')}</Button>
                    </form>
                ) : null}
                {can_manage && categories.length > 0 ? (
                    <form className="mt-3 grid gap-2 md:grid-cols-4" onSubmit={(event) => { event.preventDefault(); checklist.post('/settings/checklists', { preserveScroll: true, onSuccess: () => checklist.reset('label_en', 'label_ar') }); }}>
                        <select aria-label={t('jobs.type')} className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={checklist.data.job_type} onChange={(event) => checklist.setData('job_type', event.target.value)}>
                            <option value="">{t('tickets.category')}</option>
                            {(['installation', 'repair', 'relocation', 'survey', 'maintenance'] as const).map((type) => <option key={type} value={type}>{t(`jobs.type_${type}`)}</option>)}
                        </select>
                        <select aria-label={t('tickets.category')} className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={checklist.data.ticket_category_id} onChange={(event) => checklist.setData('ticket_category_id', event.target.value)}>
                            {categories.map((row) => <option key={row.id} value={row.id}>{row.name_en}</option>)}
                        </select>
                        <Input aria-label={t('admin.name_en')} value={checklist.data.label_en} onChange={(event) => checklist.setData('label_en', event.target.value)} required />
                        <Input aria-label={t('admin.name_ar')} value={checklist.data.label_ar} onChange={(event) => checklist.setData('label_ar', event.target.value)} required />
                        <Button type="submit">{t('jobs.checklist')}</Button>
                    </form>
                ) : null}
            </section>
            <section className="card mt-4 p-5" data-testid="wrap-up-settings">
                <h2 className="text-sm font-semibold">{t('settings_page.wrap')}</h2>
                <ul className="mt-3 divide-y divide-line">
                    {wrap_up_codes.map((row) => <li key={row.id} className="py-2 text-sm">{row.name_en} <span className="text-muted">{row.name_ar}</span></li>)}
                </ul>
                {can_manage ? (
                    <form className="mt-3 grid gap-2 md:grid-cols-3" onSubmit={(event) => { event.preventDefault(); wrap.post('/settings/wrap-up-codes', { preserveScroll: true, onSuccess: () => wrap.reset() }); }}>
                        <Input aria-label={t('admin.name_en')} value={wrap.data.name_en} onChange={(event) => wrap.setData('name_en', event.target.value)} required />
                        <Input aria-label={t('admin.name_ar')} value={wrap.data.name_ar} onChange={(event) => wrap.setData('name_ar', event.target.value)} required />
                        <Button type="submit">{t('settings_page.add_wrap')}</Button>
                    </form>
                ) : null}
            </section>
        </AppShell>
    );
}

function TierEditor({ tier, teams, people, canManage }: { tier: Tier; teams: TeamRow[]; people: Person[]; canManage: boolean }) {
    const { t, locale } = useI18n();
    const form = useForm({
        name_en: tier.name_en,
        name_ar: tier.name_ar,
        team_id: tier.team_id ? String(tier.team_id) : '',
        members: tier.members.map((member) => ({ user_id: member.id, on_duty: member.on_duty })),
        add_id: '',
    });
    const nameOf = (id: number) => people.find((person) => person.id === id)?.name ?? `#${id}`;

    return (
        <form
            className="rounded-tile bg-card-2 p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    name_en: data.name_en,
                    name_ar: data.name_ar,
                    team_id: data.team_id === '' ? null : Number(data.team_id),
                    members: data.members,
                }));
                form.put(`/settings/tiers/${tier.id}`, { preserveScroll: true });
            }}
        >
            <p className="mb-2 text-sm font-semibold">{t('settings_page.tier')} {tier.level}</p>
            <div className="grid gap-2 md:grid-cols-3">
                <Input aria-label={t('admin.name_en')} value={form.data.name_en} disabled={!canManage} onChange={(event) => form.setData('name_en', event.target.value)} />
                <Input aria-label={t('admin.name_ar')} value={form.data.name_ar} disabled={!canManage} onChange={(event) => form.setData('name_ar', event.target.value)} />
                <select aria-label={t('settings_page.team')} className="h-10 rounded-ctl border border-input bg-card px-3 text-sm" disabled={!canManage} value={form.data.team_id} onChange={(event) => form.setData('team_id', event.target.value)}>
                    <option value="">{t('settings_page.no_team')}</option>
                    {teams.map((team) => <option key={team.id} value={team.id}>{locale === 'ar' ? team.name_ar : team.name_en}</option>)}
                </select>
            </div>
            <ul className="mt-3 space-y-1">
                {form.data.members.map((member) => (
                    <li key={member.user_id} className="flex items-center justify-between gap-3 text-sm">
                        <span>{nameOf(member.user_id)}</span>
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={member.on_duty} disabled={!canManage} onChange={(event) => form.setData('members', form.data.members.map((row) => row.user_id === member.user_id ? { ...row, on_duty: event.target.checked } : row))} />
                            {t('tickets.on_duty')}
                        </label>
                    </li>
                ))}
            </ul>
            {canManage ? (
                <div className="mt-3 flex flex-wrap gap-2">
                    <select aria-label={t('settings_page.add_member')} className="h-10 rounded-ctl border border-input bg-card px-3 text-sm" value={form.data.add_id} onChange={(event) => form.setData('add_id', event.target.value)}>
                        <option value="">{t('settings_page.add_member')}</option>
                        {people.filter((person) => !form.data.members.some((member) => member.user_id === person.id)).map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
                    </select>
                    <Button type="button" variant="outline" onClick={() => {
                        const id = Number(form.data.add_id);
                        if (!id || form.data.members.some((member) => member.user_id === id)) return;
                        form.setData({ ...form.data, members: [...form.data.members, { user_id: id, on_duty: true }], add_id: '' });
                    }}>{t('settings_page.add_member')}</Button>
                    <Button type="submit">{t('settings_page.save_tier')}</Button>
                </div>
            ) : null}
        </form>
    );
}
