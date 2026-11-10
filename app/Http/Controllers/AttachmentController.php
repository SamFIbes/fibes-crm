<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Files\AttachmentScope;
use App\Services\Files\FileDesk;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function index(Request $request, AccessGate $access, AttachmentScope $scope): Response
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return Inertia::render('Files/Index', [
            'files' => $scope->visible($actor)->with('uploader:id,name')->latest()->limit(50)->get()->map(function (Attachment $file) {
                return [
                    'id' => $file->id,
                    'original_name' => $file->original_name,
                    'mime' => $file->mime,
                    'size' => $file->size,
                    'scan_status' => $file->scan_status,
                    'uploader' => $file->uploader?->name,
                    'created_at' => $file->created_at?->toIso8601String(),
                    'download_url' => URL::temporarySignedRoute('files.download', now()->addMinutes(10), ['attachment' => $file->id]),
                ];
            }),
            'can_upload' => $access->allows($actor, 'attachments.upload'),
            'can_delete' => $access->allows($actor, 'attachments.delete'),
            'max_kilobytes' => (int) config('fibes.attachments.max_kilobytes'),
        ]);
    }

    public function store(Request $request, AccessGate $access, FileDesk $files): RedirectResponse
    {
        $max = (int) config('fibes.attachments.max_kilobytes');
        $upload = $request->validate([
            'file' => ['required', 'file', 'max:'.$max, 'mimes:pdf,jpg,jpeg,png,webp,txt,csv'],
        ])['file'];
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $files->store($upload, $actor, $request, null, 'attachment.stored');

        return back()->with('status', __('ui.files.saved'));
    }

    public function download(Request $request, Attachment $attachment, AccessGate $access, AttachmentScope $scope): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $actor = $access->effective($request);
        abort_unless($actor instanceof User && $scope->canSee($actor, $attachment), 404);
        abort_unless(in_array($attachment->scan_status, ['clean', 'skipped'], true), 403);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    public function destroy(Request $request, Attachment $attachment, AccessGate $access, AuditLogger $audit, AttachmentScope $scope): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User && $scope->canSee($actor, $attachment), 404);
        $audit->record('attachment.deleted', $actor instanceof User ? $actor : null, [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
        ], $request);
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();

        return back()->with('status', __('ui.files.removed'));
    }
}
