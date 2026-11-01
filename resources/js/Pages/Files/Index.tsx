import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { AppShell } from '@/components/shell/AppShell';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { useI18n } from '@/lib/i18n';
import type { SharedProps } from '@/types';

type FileRow = {
    id: number;
    original_name: string;
    mime: string;
    size: number;
    scan_status: string;
    uploader: string | null;
    download_url: string;
};

export default function FilesIndex({ files, can_upload, can_delete, max_kilobytes }: { files: FileRow[]; can_upload: boolean; can_delete: boolean; max_kilobytes: number }) {
    const { t } = useI18n();
    const { flash } = usePage<SharedProps>().props;
    const form = useForm<{ file: File | null }>({ file: null });

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.post('/files', { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <AppShell>
            <Head title={t('files.title')} />
            <PageHeader title={t('files.title')} sub={t('files.subtitle')} />
            {flash.status ? <p className="mb-4 rounded-tile bg-card px-3 py-2 text-sm ring-1 ring-line">{flash.status}</p> : null}
            {form.errors.file ? <p className="mb-4 text-sm text-bad">{form.errors.file}</p> : null}
            {can_upload ? (
                <form onSubmit={save} className="card mb-4 flex flex-wrap items-center gap-3 p-4" data-testid="files-page">
                    <input type="file" aria-label={t('files.upload')} onChange={(event) => form.setData('file', event.target.files?.[0] ?? null)} />
                    <Button type="submit" disabled={form.processing || !form.data.file}>{t('files.upload')}</Button>
                    <span className="text-xs text-muted">{t('files.limit', { size: Math.round(max_kilobytes / 1024) })}</span>
                </form>
            ) : null}
            <div className="card overflow-x-auto">
                <table className="w-full text-start">
                    <thead>
                        <tr className="border-b border-line text-2xs font-bold uppercase tracking-[0.1em] text-faint">
                            <th className="px-4 py-3 text-start">{t('admin.name')}</th>
                            <th className="px-4 py-3 text-start">{t('files.scan')}</th>
                            <th className="px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody>
                        {files.length === 0 ? <tr><td colSpan={3} className="px-4 py-8 text-center text-muted">{t('files.empty')}</td></tr> : null}
                        {files.map((file) => (
                            <tr key={file.id} className="border-b border-line last:border-0">
                                <td className="px-4 py-3 text-sm">
                                    <div className="font-semibold">{file.original_name}</div>
                                    <div className="text-xs text-muted" dir="ltr">{file.mime} · {file.uploader ?? '—'}</div>
                                </td>
                                <td className="px-4 py-3 text-sm">{file.scan_status}</td>
                                <td className="px-4 py-3 text-end">
                                    <a className="text-sm font-semibold text-accent-ink" href={file.download_url}>{t('files.download')}</a>
                                    {can_delete ? <button type="button" className="ms-3 text-xs font-semibold text-bad" onClick={() => router.delete(`/files/${file.id}`, { preserveScroll: true })}>{t('files.delete')}</button> : null}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppShell>
    );
}
