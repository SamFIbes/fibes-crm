import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Zap } from 'lucide-react';
import { EmptyState } from '@/components/feedback/EmptyState';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type Rule = { id: number; key: string; version: number; name: string; trigger: string; enabled: boolean };
type Hook = { id: number; name: string; url: string; enabled: boolean; events: string[] };
type Health = { key: string; ok: boolean; detail: string };

export default function AutomationsIndex({
    rules,
    runs,
    webhooks,
    health,
    paused,
    can_manage,
    plain_key,
    plain_secret,
}: {
    rules: Rule[];
    runs: { id: number; trigger: string; status: string; depth: number; message: string | null }[];
    webhooks: Hook[];
    deliveries: { id: number; event: string; status: string; attempts: number; last_error: string | null }[];
    keys: { id: number; name: string }[];
    health: Health[];
    paused: boolean;
    can_manage: boolean;
    plain_key: string | null;
    plain_secret: string | null;
}) {
    const { t } = useI18n();
    const page = usePage<SharedProps>();
    const rule = useForm({ name_en: '', name_ar: '', trigger: 'lead.created', field: '', cmp: 'eq', value: '', action: 'create_task', action_value: '' });
    const hook = useForm({ name: '', url: '', events: ['lead.created'] });
    const apiKey = useForm({ name: '', days: '90' });

    return (
        <AppShell>
            <Head title={t('automations.title')} />
            <div data-testid="automations-page">
                <PageHeader
                    title={t('automations.title')}
                    sub={t('automations.subtitle')}
                    actions={can_manage ? <Button type="button" onClick={() => router.post('/automations/pause', { paused: !paused })}>{paused ? t('automations.resume') : t('automations.pause')}</Button> : null}
                />
                {page.props.flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{page.props.flash.status}</p> : null}
                {plain_secret ? <p className="mb-4 break-all rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line" dir="ltr" data-testid="webhook-secret">{plain_secret}</p> : null}
                {plain_key ? <p className="mb-4 break-all rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line" dir="ltr" data-testid="api-key">{plain_key}</p> : null}
                <section className="mb-4 grid gap-3 sm:grid-cols-2" data-testid="automation-health">
                    {health.map((item) => (
                        <p key={item.key} className="card px-4 py-3 text-sm">{t('automations.health')} · {item.key}: <span dir="ltr">{item.detail || (item.ok ? 'ok' : 'check')}</span></p>
                    ))}
                </section>
                {rules.length === 0 ? <div className="card"><EmptyState icon={<Zap className="size-5" />} title={t('automations.title')} body={t('automations.subtitle')} /></div> : (
                    <div className="space-y-3">
                        {rules.map((item) => (
                            <article key={item.id} className="card flex flex-wrap items-center justify-between gap-3 p-4" data-testid="automation-rule" data-enabled={item.enabled ? '1' : '0'}>
                                <div>
                                    <p className="text-sm font-semibold">{item.name}</p>
                                    <p className="text-sm text-muted" dir="ltr">{item.trigger} · v{item.version}</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Badge tone={item.enabled ? 'good' : 'bad'}>{item.enabled ? t('automations.enabled') : t('automations.off')}</Badge>
                                    {can_manage ? (
                                        <>
                                            <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/automations/${item.id}/dry`)}>{t('automations.dry')}</Button>
                                            <Button type="button" size="sm" variant="outline" onClick={() => router.post(`/automations/${item.id}`, { enabled: false, name_en: item.name, name_ar: item.name })}>{t('automations.disable')}</Button>
                                        </>
                                    ) : null}
                                </div>
                            </article>
                        ))}
                    </div>
                )}
                {can_manage ? (
                    <form className="card mt-4 grid gap-3 p-4 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); rule.post('/automations'); }}>
                        <h2 className="text-sm font-semibold md:col-span-2">{t('automations.new_rule')}</h2>
                        <input className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={rule.data.name_en} onChange={(event) => rule.setData('name_en', event.target.value)} aria-label={t('automations.name_en')} />
                        <input className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={rule.data.name_ar} onChange={(event) => rule.setData('name_ar', event.target.value)} aria-label={t('automations.name_ar')} />
                        <input className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" dir="ltr" value={rule.data.trigger} onChange={(event) => rule.setData('trigger', event.target.value)} aria-label={t('automations.trigger')} />
                        <input className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" dir="ltr" value={rule.data.field} onChange={(event) => rule.setData('field', event.target.value)} aria-label={t('automations.field')} />
                        <input className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" dir="ltr" value={rule.data.value} onChange={(event) => rule.setData('value', event.target.value)} aria-label={t('automations.value')} />
                        <select className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={rule.data.action} onChange={(event) => rule.setData('action', event.target.value)} aria-label={t('automations.action')}>
                            {['create_task', 'notify', 'add_tag', 'noc_task', 'send_whatsapp', 'dispatch'].map((item) => <option key={item} value={item}>{item}</option>)}
                        </select>
                        <input className="h-10 rounded-ctl border border-input bg-card px-2 text-sm md:col-span-2" value={rule.data.action_value} onChange={(event) => rule.setData('action_value', event.target.value)} aria-label={t('automations.action_value')} />
                        <Button type="submit">{t('automations.save')}</Button>
                    </form>
                ) : null}
                <section className="card mt-4 p-4" data-testid="webhook-list">
                    <h2 className="text-sm font-semibold">{t('automations.webhooks')}</h2>
                    {webhooks.map((item) => <p key={item.id} className="mt-2 text-sm" dir="ltr">{item.name} · {item.url}</p>)}
                    {can_manage ? (
                        <form className="mt-3 grid gap-2 md:grid-cols-2" onSubmit={(event) => { event.preventDefault(); hook.post('/automations/webhooks'); }}>
                            <input className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={hook.data.name} onChange={(event) => hook.setData('name', event.target.value)} aria-label={t('automations.webhook_name')} />
                            <input className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" dir="ltr" value={hook.data.url} onChange={(event) => hook.setData('url', event.target.value)} aria-label={t('automations.webhook_url')} />
                            <Button type="submit" size="sm">{t('automations.save_webhook')}</Button>
                        </form>
                    ) : null}
                </section>
                {can_manage ? (
                    <form className="card mt-4 flex flex-wrap items-end gap-2 p-4" onSubmit={(event) => { event.preventDefault(); apiKey.post('/automations/keys'); }}>
                        <h2 className="w-full text-sm font-semibold">{t('automations.keys')}</h2>
                        <input className="h-10 rounded-ctl border border-input bg-card px-2 text-sm" value={apiKey.data.name} onChange={(event) => apiKey.setData('name', event.target.value)} aria-label={t('automations.key_name')} />
                        <Button type="submit" size="sm">{t('automations.save_key')}</Button>
                    </form>
                ) : null}
                <section className="mt-4">
                    <h2 className="mb-2 text-sm font-semibold">{t('automations.runs')}</h2>
                    {runs.map((run) => <p key={run.id} className="text-sm text-muted" dir="ltr">{run.trigger} · {run.status} · {run.depth}</p>)}
                </section>
            </div>
        </AppShell>
    );
}
