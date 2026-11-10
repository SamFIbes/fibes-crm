<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ViewAsController extends Controller
{
    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->isSuperAdmin() || (int) $data['user_id'] === $actor->id) {
            abort(403);
        }

        $request->session()->put('view_as_user_id', (int) $data['user_id']);
        $audit->record('auth.view_as.started', $actor, ['user_id' => (int) $data['user_id']], $request);

        return back()->with('status', __('ui.admin.viewing_as'));
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $actor = $request->user();
        $target = $request->session()->pull('view_as_user_id');

        if ($actor instanceof User && $target) {
            $audit->record('auth.view_as.stopped', $actor, ['user_id' => $target], $request);
        }

        return back()->with('status', __('ui.admin.viewing_stopped'));
    }
}
