<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AccountSessionController extends Controller
{
    public function index(Request $request, SessionRegistry $sessions): Response
    {
        $user = $request->user();
        $current = $request->session()->getId();
        $own = $sessions->forUser($user->id)
            ->map(fn (object $row) => $sessions->present($row, $current))
            ->values()
            ->all();

        $others = [];

        if ($user->isSuperAdmin()) {
            $others = $sessions->others()
                ->reject(fn (object $row) => (int) $row->user_id === $user->id)
                ->map(fn (object $row) => $sessions->present($row, $current, true))
                ->values()
                ->all();
        }

        return Inertia::render('Account/Sessions', [
            'sessions' => $own,
            'other_sessions' => $others,
        ]);
    }

    public function destroy(Request $request, string $sessionId, SessionRegistry $sessions, AuditLogger $audit): RedirectResponse
    {
        $row = $sessions->find($sessionId);
        $user = $request->user();

        if (! $row || ! $this->canRevoke($user, $row)) {
            abort(404);
        }

        $sessions->delete($sessionId);
        $audit->record('auth.session.revoked', $user, [
            'session' => $sessions->shortId($sessionId),
            'owner_id' => $row->user_id,
        ], $request);

        if ($sessionId === $request->session()->getId()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return back()->with('status', __('ui.sessions.revoked'));
    }

    public function destroyOthers(Request $request, SessionRegistry $sessions, AuditLogger $audit): RedirectResponse
    {
        $ids = $sessions->deleteOthers($request->user()->id, $request->session()->getId());
        $audit->record('auth.session.revoked', $request->user(), [
            'count' => count($ids),
            'scope' => 'others',
        ], $request);

        return back()->with('status', __('ui.sessions.others_revoked'));
    }

    private function canRevoke(User $actor, object $row): bool
    {
        if ((int) $row->user_id === $actor->id) {
            return true;
        }

        return $actor->isSuperAdmin();
    }
}
