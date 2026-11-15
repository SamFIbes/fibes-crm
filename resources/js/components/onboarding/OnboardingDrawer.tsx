import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { jsonFetch, type Card, type CardDetail, type Person, type Stage } from '@/lib/onboarding';

const controlClass = 'min-h-11 w-full rounded-ctl border border-input bg-card px-2 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2';

export function OnboardingDrawer({
    id,
    stages,
    people,
    canEdit,
    busy,
    onClose,
    onMutate,
}: {
    id: number;
    stages: Stage[];
    people: Person[];
    canEdit: boolean;
    busy: boolean;
    onClose: () => void;
    onMutate: (card: Card, endpoint: string, body: Record<string, string | number | null>, method?: 'POST' | 'PUT') => Promise<boolean>;
}) {
    const { t, locale } = useI18n();
    const [detail, setDetail] = useState<CardDetail | null>(null);
    const [error, setError] = useState(false);
    const [note, setNote] = useState('');
    const [due, setDue] = useState('');
    const [followupNote, setFollowupNote] = useState('');
    const [loading, setLoading] = useState(true);
    const panelRef = useRef<HTMLElement>(null);
    const requestRef = useRef(0);
    const closeRef = useRef(onClose);
    closeRef.current = onClose;

    const load = useCallback(async () => {
        const request = ++requestRef.current;
        setLoading(true);
        setError(false);
        try {
            const result = await jsonFetch<CardDetail>(`/onboarding/cards/${id}`);
            if (request !== requestRef.current) return;
            setDetail(result);
            setDue((result.followup_on ?? '').slice(0, 10));
            setFollowupNote(result.followup?.note ?? '');
        } catch {
            if (request === requestRef.current) setError(true);
        } finally {
            if (request === requestRef.current) setLoading(false);
        }
    }, [id]);

    useEffect(() => {
        void load();
        return () => { requestRef.current += 1; };
    }, [load]);

    useEffect(() => {
        const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        panelRef.current?.focus();
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeRef.current();
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
    }, []);

    const mutate = async (endpoint: string, body: Record<string, string | number | null>, method: 'POST' | 'PUT' = 'POST') => {
        if (!detail) return false;
        const success = await onMutate(detail, endpoint, body, method);
        if (success) await load();
        return success;
    };

    const saveNote = async (event: FormEvent) => {
        event.preventDefault();
        if (!note.trim()) return;
        if (await mutate('notes', { body: note.trim() })) setNote('');
    };

    const saveFollowup = async (event: FormEvent) => {
        event.preventDefault();
        await mutate('followup', { due_on: due || null, note: followupNote || null }, 'PUT');
    };

    const allowedStages = detail?.next_stages.map((stage) => typeof stage === 'string' ? stage : stage.key) ?? [];
    const phone = detail?.client_phone?.replace(/\D/g, '') ?? '';
    const message = `${t('onboarding.followup_message')} ${detail?.title ?? ''}`.trim();
    const email = detail?.client_email?.replace(/[\r\n]/g, '') ?? '';

    return (
        <div className="fixed inset-0 z-50 flex justify-end bg-black/50">
            <section
                ref={panelRef}
                data-testid="onboarding-drawer"
                role="dialog"
                aria-modal="true"
                aria-labelledby="onboarding-drawer-title"
                tabIndex={-1}
                className="h-full w-full max-w-xl overflow-y-auto bg-card p-4 shadow-card focus-visible:outline focus-visible:outline-2"
            >
                <header className="mb-4 flex items-center justify-between gap-2">
                    <h2 id="onboarding-drawer-title" className="text-lg font-semibold">{detail?.title ?? t('onboarding.details')}</h2>
                    <Button type="button" variant="outline" className="min-h-11" onClick={onClose}>{t('onboarding.close')}</Button>
                </header>
                {loading ? <p role="status" className="text-sm text-muted">{t('onboarding.loading')}</p> : null}
                {error ? (
                    <div role="alert" className="space-y-2">
                        <p className="text-sm text-bad">{t('onboarding.error')}</p>
                        <Button type="button" variant="outline" className="min-h-11" onClick={() => void load()}>{t('onboarding.retry')}</Button>
                    </div>
                ) : null}
                {detail ? (
                    <div className="space-y-4">
                        {detail.client_name ? <p className="text-sm">{detail.client_name}</p> : null}
                        {canEdit ? (
                            <>
                                <label className="grid gap-1 text-sm">
                                    <span>{t('onboarding.move_to')}</span>
                                    <select className={controlClass} value={detail.stage_key} disabled={busy || loading} onChange={(event) => void mutate('move', { stage: event.target.value })}>
                                        {stages.filter((stage) => stage.key === detail.stage_key || allowedStages.includes(stage.key)).map((stage) => (
                                            <option key={stage.key} value={stage.key}>{locale === 'ar' ? stage.name_ar : stage.name_en}</option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid gap-1 text-sm">
                                    <span>{t('onboarding.assignee')}</span>
                                    <select className={controlClass} value={detail.assigned_to === null ? '' : String(detail.assigned_to)} disabled={busy || loading} onChange={(event) => void mutate('assign', { assigned_to: event.target.value ? Number(event.target.value) : null })}>
                                        <option value="">{t('onboarding.unassigned')}</option>
                                        {people.map((person) => <option key={person.id} value={String(person.id)}>{person.name}</option>)}
                                    </select>
                                </label>
                                <form className="space-y-2" onSubmit={(event) => void saveFollowup(event)}>
                                    <h3 className="font-semibold">{t('onboarding.follow_up')}</h3>
                                    <label className="grid gap-1 text-sm">
                                        <span>{t('onboarding.followup_date')}</span>
                                        <Input type="date" dir="ltr" className="min-h-11" value={due} onChange={(event) => setDue(event.target.value)} />
                                    </label>
                                    <label className="grid gap-1 text-sm">
                                        <span>{t('onboarding.followup_note')}</span>
                                        <Input className="min-h-11" value={followupNote} onChange={(event) => setFollowupNote(event.target.value)} />
                                    </label>
                                    <div className="flex flex-wrap gap-2">
                                        <Button type="submit" className="min-h-11" disabled={busy || loading}>{t('onboarding.save')}</Button>
                                        <Button type="button" variant="outline" className="min-h-11" disabled={busy || loading} onClick={() => void mutate('followup', { due_on: null, note: null }, 'PUT')}>{t('onboarding.clear')}</Button>
                                        <Button type="button" variant="outline" className="min-h-11" disabled={busy || loading} onClick={() => void mutate('followup', { snooze_days: 1 }, 'PUT')}>{t('onboarding.snooze_1')}</Button>
                                        <Button type="button" variant="outline" className="min-h-11" disabled={busy || loading} onClick={() => void mutate('followup', { snooze_days: 3 }, 'PUT')}>{t('onboarding.snooze_3')}</Button>
                                    </div>
                                </form>
                                <form className="space-y-2" onSubmit={(event) => void saveNote(event)}>
                                    <label className="grid gap-1 text-sm">
                                        <span>{t('onboarding.note')}</span>
                                        <textarea className={`${controlClass} min-h-24 py-2`} value={note} onChange={(event) => setNote(event.target.value)} required />
                                    </label>
                                    <Button type="submit" className="min-h-11" disabled={busy || loading}>{t('onboarding.save')}</Button>
                                </form>
                            </>
                        ) : null}
                        <div className="flex flex-wrap gap-2">
                            {phone ? <a className="inline-flex min-h-11 items-center rounded-ctl px-3 ring-1 ring-line focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" href={`https://wa.me/${phone}?text=${encodeURIComponent(message)}`} target="_blank" rel="noopener noreferrer">{t('onboarding.whatsapp')}</a> : null}
                            {email ? <a className="inline-flex min-h-11 items-center rounded-ctl px-3 ring-1 ring-line focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" href={`mailto:${encodeURIComponent(email)}?subject=${encodeURIComponent(detail.title)}&body=${encodeURIComponent(message)}`} target="_blank" rel="noopener noreferrer">{t('onboarding.email')}</a> : null}
                            {detail.lead_id ? <a className="inline-flex min-h-11 items-center rounded-ctl px-3 ring-1 ring-line focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" href={`/leads?lead=${encodeURIComponent(String(detail.lead_id))}`}>{t('onboarding.open_lead')}</a> : null}
                            {detail.customer_id ? <a className="inline-flex min-h-11 items-center rounded-ctl px-3 ring-1 ring-line focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" href={`/customers/${encodeURIComponent(String(detail.customer_id))}`}>{t('onboarding.open_customer')}</a> : null}
                        </div>
                        <section>
                            <h3 className="font-semibold">{t('onboarding.history')}</h3>
                            {detail.events.length === 0 ? <p className="text-sm text-muted">{t('onboarding.empty_history')}</p> : (
                                <ul className="mt-2 space-y-2">
                                    {[...detail.events].sort((a, b) => b.occurred_at.localeCompare(a.occurred_at)).map((event) => {
                                        const key = `onboarding.event_${event.kind}`;
                                        const translated = t(key);
                                        return (
                                            <li key={event.id} className="rounded-tile p-2 text-sm ring-1 ring-line">
                                                <p>{translated === key ? event.kind : translated}</p>
                                                <p className="text-muted">{event.actor_name || t('onboarding.system')} · <time dir="ltr">{event.occurred_at}</time></p>
                                                {event.detail ? <p className="whitespace-pre-wrap">{event.detail}</p> : null}
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </section>
                    </div>
                ) : null}
            </section>
        </div>
    );
}
