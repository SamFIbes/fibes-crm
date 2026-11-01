<?php

namespace App\Services\Files;

use App\Models\Attachment;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FileDesk
{
    public function __construct(
        private FileScanner $scanner,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     */
    public function store(UploadedFile $upload, User $actor, Request $request, ?Model $parent, string $event, array $extra = []): Attachment
    {
        $quarantine = $upload->store('quarantine', 'local');
        $absolute = Storage::disk('local')->path($quarantine);
        $sniffed = mime_content_type($absolute) ?: 'application/octet-stream';

        if (in_array($sniffed, ['application/x-dosexec', 'application/x-executable', 'application/x-sharedlib', 'application/x-mach-binary'], true)) {
            Storage::disk('local')->delete($quarantine);

            throw ValidationException::withMessages(['file' => __('ui.files.infected')]);
        }

        $scan = $this->scanner->scan($absolute);

        if ($scan['status'] !== 'clean') {
            Storage::disk('local')->delete($quarantine);
            $this->audit->record('attachment.rejected', $actor, [
                'name' => $upload->getClientOriginalName(),
                'result' => $scan['status'],
            ] + $extra, $request);
            $this->notify($actor);

            throw ValidationException::withMessages(['file' => __('ui.files.infected')]);
        }

        $path = 'attachments/'.Str::uuid()->toString();
        Storage::disk('local')->move($quarantine, $path);
        $stored = Storage::disk('local')->path($path);
        $attributes = [
            'disk' => 'local',
            'path' => $path,
            'original_name' => $upload->getClientOriginalName(),
            'mime' => $sniffed !== 'application/octet-stream' ? $sniffed : ($upload->getClientMimeType() ?: 'application/octet-stream'),
            'size' => $upload->getSize() ?: 0,
            'sha256' => hash_file('sha256', $stored) ?: '',
            'scan_status' => 'clean',
            'scan_detail' => null,
            'scan_result' => 'clean',
            'scanned_at' => now(),
            'uploaded_by' => $actor->id,
        ];
        $file = $parent === null
            ? Attachment::query()->create($attributes)
            : $parent->files()->create($attributes);
        $this->audit->record($event, $actor, [
            'id' => $file->id,
            'name' => $file->original_name,
            'size' => $file->size,
            'scan_status' => 'clean',
        ] + $extra, $request);

        return $file;
    }

    private function notify(User $actor): void
    {
        $notice = new WorkspaceNotification(__('ui.files.infected_title'), __('ui.files.infected_body'));
        $actor->notify($notice);

        User::query()->where('role', 'super_admin')->where('id', '!=', $actor->id)->whereNotNull('email')->each(function (User $admin) use ($notice) {
            $admin->notify($notice);
        });
    }
}
