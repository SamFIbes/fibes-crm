<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Services\Audit\AuditLogger;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExchangeRateController extends Controller
{
    public function index(Request $request, AccessGate $access): Response
    {
        $actor = $access->effective($request);
        $latest = ExchangeRate::query()->orderByDesc('effective_on')->orderByDesc('id')->first();

        return Inertia::render('Rates/Index', [
            'currencies' => Currency::query()->orderBy('code')->get(),
            'rates' => ExchangeRate::query()->with('author:id,name')->orderByDesc('effective_on')->orderByDesc('id')->limit(60)->get()->map(fn (ExchangeRate $rate) => [
                'id' => $rate->id,
                'effective_on' => $rate->effective_on?->toDateString(),
                'usd_lbp' => (string) $rate->usd_lbp,
                'confirmed' => $rate->confirmed,
                'note' => $rate->note,
                'author' => $rate->author?->name,
            ]),
            'needs_confirmation' => $latest === null || ! $latest->confirmed,
            'can_manage' => $actor instanceof User && $access->allows($actor, 'rates.manage'),
        ]);
    }

    public function store(Request $request, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'effective_on' => ['required', 'date'],
            'usd_lbp' => ['required', 'numeric', 'min:0.0001', 'max:999999999999.9999'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $actor = $access->effective($request);
        $rate = ExchangeRate::query()->create([
            'effective_on' => $data['effective_on'],
            'usd_lbp' => $data['usd_lbp'],
            'confirmed' => true,
            'note' => $data['note'] ?? null,
            'set_by' => $actor instanceof User ? $actor->id : null,
        ]);

        $audit->record('exchange_rate.recorded', $actor instanceof User ? $actor : null, [
            'id' => $rate->id,
            'effective_on' => $rate->effective_on->toDateString(),
            'usd_lbp' => (string) $rate->usd_lbp,
        ], $request);

        if ($actor instanceof User) {
            $actor->notify(new WorkspaceNotification(
                __('ui.notifications.rate_saved_title'),
                __('ui.notifications.rate_saved_body', ['rate' => (string) $rate->usd_lbp]),
            ));
        }

        return back()->with('status', __('ui.rates.saved'));
    }
}
