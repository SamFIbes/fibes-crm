import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Field } from '@/components/form/Field';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { SolidBadge, SolidCard, SolidSelect, solidField, solidFieldStyle } from '@/components/ui/classic';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Secret = { name: string; set: boolean };
type WarehouseRow = { location_id: number; warehouse: string };
type Location = { id: number; name: string; kind: string };
type Status = 'never_connected' | 'configured' | 'synced' | 'error';

type Card = {
    key: string;
    enabled: boolean;
    url: string;
    company: string;
    warehouse: string;
    purpose: string;
    phone_number_id: string;
    warehouses: WarehouseRow[];
    live: boolean;
    flag: boolean;
    secrets: Secret[];
    reason: string;
    dial?: boolean;
    abandoned_mode?: 'push' | null;
    abandoned_count?: number;
    optional_secrets?: Secret[];
    status: Status;
    last_sync_at: string | null;
    last_error: string | null;
    docs: string;
};

type Draft = {
    enabled: boolean;
    url: string;
    company: string;
    warehouse: string;
    purpose: string;
    phone_number_id: string;
    warehouses: WarehouseRow[];
};

const titles: Record<string, string> = {
    erpnext: 'integrations.erp_title',
    krypton: 'integrations.krypton_title',
    whish: 'integrations.whish_title',
    grandstream: 'integrations.pbx_title',
    whatsapp: 'integrations.whatsapp_title',
};

const bodies: Record<string, string> = {
    erpnext: 'integrations.erp_body',
    krypton: 'integrations.krypton_body',
    whish: 'integrations.whish_body',
    grandstream: 'integrations.pbx_body',
    whatsapp: 'integrations.whatsapp_body',
};

const statusTone: Record<Status, 'neutral' | 'info' | 'good' | 'bad'> = {
    never_connected: 'neutral',
    configured: 'info',
    synced: 'good',
    error: 'bad',
};

function draftFor(card: Card, locations: Location[]): Draft {
    return {
        enabled: card.enabled,
        url: card.url,
        company: card.company,
        warehouse: card.warehouse,
        purpose: card.purpose || 'Purchase',
        phone_number_id: card.phone_number_id,
        warehouses: locations.map((location) => ({
            location_id: location.id,
            warehouse: card.warehouses.find((row) => row.location_id === location.id)?.warehouse ?? '',
        })),
    };
}

export default function IntegrationsPage({ cards, locations, can_manage }: { cards: Card[]; locations: Location[]; can_manage: boolean }) {
    const { t } = useI18n();
    const page = usePage<SharedProps>();
    const form = useForm<{ integrations: Record<string, Draft> }>({
        integrations: Object.fromEntries(cards.map((card) => [card.key, draftFor(card, locations)])),
    });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put('/settings/integrations', { preserveScroll: true });
    };

    const patch = (key: string, next: Partial<Draft>) => {
        form.setData('integrations', {
            ...form.data.integrations,
            [key]: { ...form.data.integrations[key], ...next },
        });
    };

    return (
        <AppShell>
            <Head title={t('integrations.title')} />
            <div className="mb-5">
                <p className="mb-2 text-sm text-muted">
                    <a className="font-semibold text-accent-ink" href="/settings">{t('settings_page.title')}</a>
                    <span className="px-1">/</span>
                    {t('integrations.title')}
                </p>
                <h1 className="text-[30px] font-semibold leading-tight tracking-[-0.025em] sm:text-display">{t('integrations.title')}</h1>
                <p className="mt-1.5 max-w-2xl text-base text-muted">{t('integrations.subtitle')}</p>
            </div>
            {page.props.flash.status ? <p className="mb-4 rounded-tile border border-line px-3 py-2 text-sm" style={solidFieldStyle}>{page.props.flash.status}</p> : null}
            {page.props.errors?.test ? <p className="mb-4 rounded-tile border border-bad/40 bg-bad-bg px-3 py-2 text-sm text-bad" role="alert">{page.props.errors.test}</p> : null}
            <form onSubmit={save} className="grid gap-4" data-testid="integrations-page">
                {cards.map((card) => {
                    const draft = form.data.integrations[card.key];
                    return (
                        <SolidCard key={card.key} className="space-y-4 p-5" >
                            <div id={card.key} className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-sm font-semibold">{t(titles[card.key] ?? card.key)}</h2>
                                    <p className="mt-1 max-w-2xl text-sm text-muted">{t(bodies[card.key] ?? card.key)}</p>
                                </div>
                                <SolidBadge tone={statusTone[card.status]}>{t(`integrations.status_${card.status}`)}</SolidBadge>
                            </div>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    role="switch"
                                    aria-label={t('integrations.enabled')}
                                    checked={draft.enabled}
                                    disabled={!can_manage}
                                    onChange={(event) => patch(card.key, { enabled: event.target.checked })}
                                />
                                {t('integrations.enabled')}
                            </label>
                            {!card.live && card.reason !== 'ready' ? (
                                <p className="text-sm text-muted">
                                    {t(card.key === 'erpnext' && card.reason === 'credentials' ? 'integrations.off_reason_credentials' : `integrations.off_reason_${card.reason === 'credentials' ? 'missing' : card.reason}`)}
                                </p>
                            ) : null}
                            {card.key === 'grandstream' ? (
                                <p className="text-sm text-muted">{card.dial ? t('integrations.dial_on') : t('integrations.dial_off')}</p>
                            ) : null}
                            {card.abandoned_mode === 'push' ? (
                                <p className="text-sm text-muted">{t('integrations.abandoned_push', { count: card.abandoned_count ?? 0 })}</p>
                            ) : null}
                            {card.last_sync_at ? <p className="text-sm text-muted" dir="ltr">{t('integrations.last_sync', { time: card.last_sync_at })}</p> : null}
                            {card.last_error ? <p className="text-sm text-bad">{t('integrations.last_error')}: {card.last_error}</p> : null}
                            {card.key !== 'whatsapp' ? (
                                <Field id={`${card.key}-url`} label={t('integrations.url')} error={form.errors[`integrations.${card.key}.url`]}>
                                    <input id={`${card.key}-url`} className={solidField} style={solidFieldStyle} dir="ltr" value={draft.url} disabled={!can_manage} onChange={(event) => patch(card.key, { url: event.target.value })} />
                                </Field>
                            ) : null}
                            {card.key === 'erpnext' ? (
                                <div className="grid gap-3 md:grid-cols-2">
                                    <Field id="erp-company" label={t('integrations.company')}>
                                        <input id="erp-company" className={solidField} style={solidFieldStyle} value={draft.company} disabled={!can_manage} onChange={(event) => patch(card.key, { company: event.target.value })} />
                                    </Field>
                                    <Field id="erp-warehouse" label={t('integrations.warehouse')}>
                                        <input id="erp-warehouse" className={solidField} style={solidFieldStyle} dir="ltr" value={draft.warehouse} disabled={!can_manage} onChange={(event) => patch(card.key, { warehouse: event.target.value })} />
                                    </Field>
                                    <Field id="erp-purpose" label={t('integrations.purpose')}>
                                        <SolidSelect id="erp-purpose" value={draft.purpose} disabled={!can_manage} onChange={(event) => patch(card.key, { purpose: event.target.value })}>
                                            <option value="Purchase">{t('integrations.purpose_purchase')}</option>
                                            <option value="Material Transfer">{t('integrations.purpose_transfer')}</option>
                                            <option value="Material Issue">{t('integrations.purpose_issue')}</option>
                                        </SolidSelect>
                                    </Field>
                                </div>
                            ) : null}
                            {card.key === 'whatsapp' ? (
                                <Field id="wa-phone" label={t('integrations.phone')}>
                                    <input id="wa-phone" className={solidField} style={solidFieldStyle} dir="ltr" value={draft.phone_number_id} disabled={!can_manage} onChange={(event) => patch(card.key, { phone_number_id: event.target.value })} />
                                </Field>
                            ) : null}
                            <div>
                                <p className="text-sm font-semibold">{t('integrations.secrets')}</p>
                                <p className="mt-1 text-xs text-muted">{t('integrations.secret_help')}</p>
                                <ul className="mt-2 space-y-1">
                                    {card.secrets.map((secret) => (
                                        <li key={secret.name} className="flex items-center justify-between gap-3 text-sm">
                                            <span dir="ltr">{secret.name}</span>
                                            <SolidBadge tone={secret.set ? 'good' : 'neutral'}>{secret.set ? t('integrations.secret_set') : t('integrations.secret_missing')}</SolidBadge>
                                        </li>
                                    ))}
                                    {(card.optional_secrets ?? []).map((secret) => (
                                        <li key={secret.name} className="flex items-center justify-between gap-3 text-sm">
                                            <span dir="ltr">{secret.name}</span>
                                            <SolidBadge tone={secret.set ? 'good' : 'neutral'}>{secret.set ? t('integrations.secret_set') : t('integrations.secret_missing')}</SolidBadge>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                            {card.key === 'erpnext' && locations.length > 0 ? (
                                <div>
                                    <p className="text-sm font-semibold">{t('integrations.map')}</p>
                                    <p className="mt-1 text-xs text-muted">{t('integrations.map_help')}</p>
                                    <div className="mt-2 grid gap-2">
                                        {locations.map((location) => (
                                            <label key={location.id} className="grid items-center gap-2 text-sm md:grid-cols-2">
                                                <span>{location.name}</span>
                                                <input
                                                    aria-label={location.name}
                                                    className={solidField}
                                                    style={solidFieldStyle}
                                                    dir="ltr"
                                                    disabled={!can_manage}
                                                    value={draft.warehouses.find((row) => row.location_id === location.id)?.warehouse ?? ''}
                                                    onChange={(event) => patch(card.key, {
                                                        warehouses: draft.warehouses.map((row) => row.location_id === location.id ? { ...row, warehouse: event.target.value } : row),
                                                    })}
                                                />
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            ) : null}
                            <div className="flex flex-wrap items-center gap-3">
                                {can_manage ? (
                                    <Button type="button" variant="soft" onClick={() => router.post(`/settings/integrations/${card.key}/test`, {}, { preserveScroll: true })}>
                                        {t('integrations.test')}
                                    </Button>
                                ) : null}
                                <a className="text-sm font-semibold text-accent-ink" href={card.docs}>{t('integrations.docs')}</a>
                            </div>
                        </SolidCard>
                    );
                })}
                {can_manage ? <Button type="submit" disabled={form.processing}>{t('integrations.save')}</Button> : null}
                <SolidCard className="space-y-2 p-5">
                    <h2 className="text-sm font-semibold">{t('integrations.map_title')}</h2>
                    <p className="text-sm text-muted">{t('integrations.map_company')}</p>
                    <p className="text-sm text-muted">{t('integrations.map_item')}</p>
                    <p className="text-sm text-muted">{t('integrations.map_warehouse')}</p>
                    <p className="text-sm text-muted">{t('integrations.map_request')}</p>
                    <p className="text-sm text-muted">{t('integrations.map_links')}</p>
                    <p className="text-sm text-muted">{t('integrations.map_asset')}</p>
                    <p className="text-sm text-muted">{t('integrations.map_expense')}</p>
                </SolidCard>
            </form>
        </AppShell>
    );
}
