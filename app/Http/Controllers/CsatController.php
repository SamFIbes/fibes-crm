<?php

namespace App\Http\Controllers;

use App\Models\CsatResponse;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Jobs\FieldWork;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CsatController extends Controller
{
    public function show(string $token): Response
    {
        $csat = CsatResponse::query()->where('token', $token)->firstOrFail();

        return Inertia::render('Csat/Show', [
            'token' => $token,
            'score' => $csat->score,
            'number' => $csat->ticket?->number,
        ]);
    }

    public function store(Request $request, string $token, FieldWork $work): RedirectResponse
    {
        $csat = CsatResponse::query()->where('token', $token)->firstOrFail();
        $data = $request->validate([
            'score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        $work->recordCsat($csat, (int) $data['score'], $data['comment'] ?? null, 'link', null, $request);

        return back()->with('status', __('ui.jobs.csat_thanks'));
    }

    public function manual(Request $request, Ticket $ticket, FieldWork $work, AccessGate $access): RedirectResponse
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);
        $csat = CsatResponse::query()->firstOrCreate(['ticket_id' => $ticket->id], ['token' => Str::random(40)]);
        $data = $request->validate([
            'score' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        $work->recordCsat($csat, (int) $data['score'], $data['comment'] ?? null, 'manual', $actor, $request);

        return back()->with('status', __('ui.jobs.csat_saved'));
    }
}
