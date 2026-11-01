import { Head, Link } from '@inertiajs/react';
import { AppShell } from '@/components/shell/AppShell';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';

type Agent = { id: number; name: string; extension: string | null; on_duty: boolean };
type Tier = { id: number; level: number; name_en: string; name_ar: string; open_tickets: number; agents: Agent[] };
type Risk = { id: number; number: string; subject: string; priority: string; breached: boolean; tier_en: string | null; tier_ar: string | null };
type Incident = { id: number; number: string; title: string };

export default function CallsBoard({ tiers, at_risk, incidents, lead_breaches = 0 }: { tiers: Tier[]; at_risk: Risk[]; incidents: Incident[]; lead_breaches?: number }) {
    const { t, locale } = useI18n();

    return (
        <AppShell>
            <Head title={t('calls.board')} />
            <PageHeader title={t('calls.board')} sub={t('calls.board_sub')} />
            <div className="grid gap-4 lg:grid-cols-2" data-testid="calls-board">
                {tiers.map((tier) => (
                    <section key={tier.id} className="card p-5">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold">{locale === 'ar' ? tier.name_ar : tier.name_en}</h2>
                            <Link className="text-sm font-semibold text-accent-ink" href={`/tickets?tier=${tier.id}`}>{t('calls.open_count', { count: tier.open_tickets })}</Link>
                        </div>
                        <ul className="mt-3 space-y-2">
                            {tier.agents.length === 0 ? <li className="text-sm text-muted">{t('calls.no_agents')}</li> : tier.agents.map((agent) => (
                                <li key={agent.id} className="flex items-center justify-between gap-3 text-sm">
                                    <span>{agent.name}{agent.extension ? <span dir="ltr" className="text-muted"> · {agent.extension}</span> : null}</span>
                                    <Badge tone={agent.on_duty ? 'good' : 'neutral'}>{agent.on_duty ? t('calls.on_duty') : t('calls.off_duty')}</Badge>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
                <section className="card p-5">
                    <h2 className="text-sm font-semibold">{t('calls.at_risk')}</h2>
                    {at_risk.length === 0 ? <p className="mt-2 text-sm text-muted">{t('calls.none_risk')}</p> : (
                        <ul className="mt-3 space-y-2 text-sm">
                            {at_risk.map((ticket) => (
                                <li key={ticket.id}>
                                    <Link className="font-semibold text-accent-ink" href={`/tickets/${ticket.id}`} dir="ltr">{ticket.number}</Link>
                                    {' · '}{ticket.priority}{' · '}{ticket.subject}
                                    {ticket.breached ? <Badge tone="bad">{t('tickets.breached')}</Badge> : <Badge tone="warn">{t('tickets.warning')}</Badge>}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
                <section className="card p-5" data-testid="lead-sla-tile">
                    <h2 className="text-sm font-semibold">{t('leads.risk')}</h2>
                    <p className="mt-2 text-3xl font-semibold" dir="ltr">{lead_breaches}</p>
                    <Link className="mt-2 inline-block text-sm font-semibold text-accent-ink" href="/leads?view=risk">{t('leads.title')}</Link>
                </section>
                <section className="card p-5">
                    <h2 className="text-sm font-semibold">{t('incidents.title')}</h2>
                    {incidents.length === 0 ? <p className="mt-2 text-sm text-muted">{t('incidents.empty')}</p> : (
                        <ul className="mt-3 space-y-2 text-sm">
                            {incidents.map((incident) => (
                                <li key={incident.id}><Link className="font-semibold text-accent-ink" href={`/incidents/${incident.id}`} dir="ltr">{incident.number}</Link> · {incident.title}</li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppShell>
    );
}
