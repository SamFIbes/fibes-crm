import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { Field } from '@/components/form/Field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';

export function QuickTask({
    customerId,
    ticketId,
    jobId,
    leadId,
}: {
    customerId?: number;
    ticketId?: number;
    jobId?: number;
    leadId?: number;
}) {
    const { t } = useI18n();
    const [open, setOpen] = useState(false);
    const form = useForm({
        title: '',
        due_at: '',
        priority: 'normal',
        remind: true,
        customer_id: customerId ?? '',
        ticket_id: ticketId ?? '',
        job_id: jobId ?? '',
        lead_id: leadId ?? '',
    });

    if (!open) {
        return <Button type="button" size="sm" variant="outline" onClick={() => setOpen(true)}>{t('tasks.add')}</Button>;
    }

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.post('/tasks', { preserveScroll: true, onSuccess: () => { form.reset('title', 'due_at'); setOpen(false); } });
    };

    return (
        <form className="mt-3 grid gap-2" onSubmit={save} data-testid="quick-task">
            <Field id={`task-title-${jobId ?? ticketId ?? customerId ?? 'new'}`} label={t('tasks.title_field')} error={form.errors.title}>
                <Input id={`task-title-${jobId ?? ticketId ?? customerId ?? 'new'}`} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} required />
            </Field>
            <Field id={`task-due-${jobId ?? ticketId ?? customerId ?? 'new'}`} label={t('tasks.due')}>
                <Input id={`task-due-${jobId ?? ticketId ?? customerId ?? 'new'}`} dir="ltr" type="datetime-local" value={form.data.due_at} onChange={(event) => form.setData('due_at', event.target.value)} />
            </Field>
            <div className="flex gap-2">
                <Button type="submit" size="sm">{t('tasks.save')}</Button>
                <Button type="button" size="sm" variant="outline" onClick={() => setOpen(false)}>{t('tasks.cancel')}</Button>
            </div>
        </form>
    );
}
