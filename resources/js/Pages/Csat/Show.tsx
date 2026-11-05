import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

export default function CsatShow({ token, score, number }: { token: string; score: number | null; number: string | null }) {
    const { t } = useI18n();
    const page = usePage<SharedProps>();
    const form = useForm({ score: score ? String(score) : '', comment: '' });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/csat/${token}`);
    };

    return (
        <main className="mx-auto flex min-h-dvh max-w-md flex-col justify-center px-4" data-testid="csat-page">
            <Head title={t('jobs.csat')} />
            <h1 className="text-3xl font-semibold">{t('jobs.csat')}</h1>
            <p className="mt-2 text-sm text-muted" dir="ltr">{number}</p>
            {page.props.flash.status ? <p className="mt-3 text-sm">{page.props.flash.status}</p> : null}
            <form className="mt-4 grid gap-3" onSubmit={save}>
                <div className="flex gap-2">
                    {[1, 2, 3, 4, 5].map((value) => (
                        <button key={value} type="button" className={`h-12 flex-1 rounded-ctl text-lg font-semibold ${form.data.score === String(value) ? 'bg-accent text-on-accent' : 'bg-card ring-1 ring-line'}`} onClick={() => form.setData('score', String(value))}>{value}</button>
                    ))}
                </div>
                <textarea aria-label={t('jobs.csat_comment')} className="min-h-24 rounded-ctl border border-input bg-card p-3 text-sm" value={form.data.comment} onChange={(event) => form.setData('comment', event.target.value)} />
                <Button type="submit" disabled={!form.data.score}>{t('jobs.csat_send')}</Button>
            </form>
        </main>
    );
}
