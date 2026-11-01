<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Campaign;
use App\Models\CoverageCheck;
use App\Models\Customer;
use App\Models\DisqualifyReason;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\Leads\LeadDesk;
use App\Services\Leads\LeadDuplicateException;
use App\Services\Leads\LeadMachine;
use App\Services\Opportunities\OpportunityDesk;
use App\Support\Access\AccessGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class LeadController extends Controller
{
    public function index(Request $request, LeadDesk $leads, AccessGate $access, LeadMachine $machine): Response
    {
        $actor = $this->actor($request, $access);
        Gate::authorize('viewAny', Lead::class);
        $view = (string) $request->string('view', 'queue');
        $query = $leads->visible($actor)->with('owner:id,name');

        if ($view === 'mine') {
            $query->where('owner_id', $actor->id);
        } elseif ($view === 'sales') {
            $query->where('queue', 'sales')->where('status', 'qualified');
        } elseif ($view === 'risk') {
            $query->where(function ($inner) {
                $inner->whereNotNull('sla_warned_at')->orWhereNotNull('sla_breached_at');
            })->whereNotIn('status', ['converted', 'disqualified', 'merged']);
        } elseif ($view === 'waitlisted') {
            $query->where('status', 'waitlisted');
        } else {
            $view = 'queue';
            $query->where('queue', 'cc')->whereNull('owner_id')->where('status', 'new');
        }

        $detail = null;
        $open = $request->integer('lead');

        if ($open > 0) {
            $lead = $leads->visible($actor)->with(['owner:id,name', 'activities.user:id,name'])->find($open);

            if ($lead instanceof Lead) {
                $detail = $leads->present($lead, $actor) + [
                    'activities' => $lead->activities->sortByDesc('id')->take(30)->map(fn ($row) => [
                        'id' => $row->id,
                        'kind' => $row->kind,
                        'body' => $row->body,
                        'at' => $row->created_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                        'user' => $row->user?->name,
                    ])->values(),
                    'next' => $machine->next($lead->status),
                    'matches' => $leads->matches($lead->phone_e164, $lead->email, $lead->name, $lead->area_id),
                    'latitude' => $lead->latitude,
                    'longitude' => $lead->longitude,
                    'coverage_result' => CoverageCheck::query()->where('subject_type', 'lead')->where('subject_id', $lead->id)->latest('id')->value('result'),
                ];
            }
        }

        return Inertia::render('Leads/Index', [
            'leads' => $query->latest('id')->limit(100)->get()->map(fn (Lead $lead) => $leads->present($lead, $actor))->values(),
            'detail' => $detail,
            'view' => $view,
            'sources' => LeadSource::query()->where('active', true)->orderBy('name_en')->get(['id', 'channel', 'name_en', 'name_ar']),
            'campaigns' => Campaign::query()->latest()->limit(30)->get()->map(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'budget' => $campaign->budget,
                'currency' => $campaign->currency,
                'leads' => $campaign->leads()->count(),
            ]),
            'reasons' => DisqualifyReason::query()->where('active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'areas' => Area::query()->orderBy('name_en')->limit(300)->get(['id', 'name_en', 'name_ar']),
            'people' => $access->allows($actor, 'leads.assign')
                ? User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'assignment' => $leads->assignment(),
            'breaches' => $leads->breachedCount(),
            'services' => array_keys(config('fibes.service_types')),
            'can_create' => $access->allows($actor, 'leads.create'),
            'can_claim' => $access->allows($actor, 'leads.claim'),
            'can_assign' => $access->allows($actor, 'leads.assign'),
            'can_edit' => $access->allows($actor, 'leads.edit'),
            'can_check' => $access->allows($actor, 'coverage.check'),
            'tiles' => (string) config('fibes.coverage.tile_url'),
        ]);
    }

    public function matches(Request $request, LeadDesk $leads, AccessGate $access): JsonResponse
    {
        $this->actor($request, $access);
        abort_unless($access->allows($request->user(), 'leads.view') || $access->allows($request->user(), 'leads.create'), 403);

        return response()->json($leads->matches(
            $request->string('phone')->toString(),
            $request->string('email')->toString(),
            $request->string('name')->toString(),
            $request->input('area_id'),
        ));
    }

    public function store(Request $request, LeadDesk $leads, AccessGate $access): RedirectResponse
    {
        Gate::authorize('create', Lead::class);
        $data = $this->validated($request);

        if (filled($data['referrer'] ?? null)) {
            $customer = Customer::query()->where('account_no', $data['referrer'])->first();
            $data['referrer_customer_id'] = $customer?->id;
            $data['channel'] = $data['channel'] ?: 'referral';
        }

        try {
            $leads->capture($data, $this->actor($request, $access), $request);
        } catch (LeadDuplicateException $exception) {
            return back()->withErrors(['phone' => __('ui.leads.duplicate')])->with('lead_matches', $exception->matches);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['phone' => __('ui.settings_page.phone_invalid')]);
        }

        return back()->with('status', __('ui.leads.saved'));
    }

    public function update(Request $request, Lead $lead, LeadDesk $leads, AccessGate $access): RedirectResponse
    {
        Gate::authorize('update', $lead);

        try {
            $leads->update($request->validate([
                'name' => ['nullable', 'string', 'max:160'],
                'phone' => ['nullable', 'string', 'max:40'],
                'email' => ['nullable', 'email', 'max:160'],
                'service_type_interest' => ['nullable', 'string', 'max:30'],
                'area_id' => ['nullable', 'integer', 'exists:areas,id'],
                'address_text' => ['nullable', 'string', 'max:240'],
                'location' => ['nullable', 'string', 'max:500'],
                'notes' => ['nullable', 'string', 'max:2000'],
                'force' => ['nullable', 'boolean'],
                'force_reason' => ['nullable', 'string', 'max:200'],
            ]), $this->actor($request, $access), $request);
        } catch (LeadDuplicateException $exception) {
            return back()->withErrors(['phone' => __('ui.leads.duplicate')])->with('lead_matches', $exception->matches);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['phone' => __('ui.settings_page.phone_invalid')]);
        }

        return back()->with('status', __('ui.leads.saved'));
    }

    public function claim(Request $request, Lead $lead, LeadDesk $leads, AccessGate $access): RedirectResponse
    {
        Gate::authorize('claim', $lead);
        $leads->claim($lead, $this->actor($request, $access), $request);

        return back()->with('status', __('ui.leads.claimed'));
    }

    public function assign(Request $request, Lead $lead, LeadDesk $leads, AccessGate $access): RedirectResponse
    {
        Gate::authorize('assign', $lead);
        $data = $request->validate(['assignee_id' => ['required', 'integer', 'exists:users,id']]);
        $assignee = User::query()->findOrFail($data['assignee_id']);
        $leads->assign($lead, $assignee, $this->actor($request, $access), $request);

        return back()->with('status', __('ui.leads.claimed'));
    }

    public function transition(Request $request, Lead $lead, LeadDesk $leads, OpportunityDesk $deals, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'leads.edit') || $access->allows($actor, 'leads.assign'), 403);
        Gate::authorize('view', $lead);
        $data = $request->validate([
            'to' => ['required', 'string', 'max:30'],
            'disqualify_reason_id' => ['nullable', 'integer', 'exists:disqualify_reasons,id'],
            'duplicate_of_id' => ['nullable', 'integer', 'exists:leads,id'],
            'body' => ['nullable', 'string', 'max:2000'],
            'customer_type' => ['nullable', 'in:individual,business,reseller'],
            'mrc' => ['nullable', 'numeric', 'min:0'],
            'nrc' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($data['to'] === 'converted') {
            $deals->openFromLead($lead, $actor, $request, $data);

            return back()->with('status', __('ui.opportunities.opened'));
        }

        if ($data['to'] === 'note') {
            Gate::authorize('update', $lead);
            $leads->note($lead, $actor, (string) ($data['body'] ?? ''), $request);

            return back()->with('status', __('ui.leads.saved'));
        }

        if ($data['to'] === 'no_answer') {
            Gate::authorize('update', $lead);
            $leads->noAnswer($lead, $actor, $request);

            return back()->with('status', __('ui.leads.saved'));
        }

        if ($access->scopeFor($actor, 'leads.edit') === 'own' && (int) $lead->owner_id !== $actor->id && ! $access->allows($actor, 'leads.assign')) {
            abort(403);
        }

        $leads->transition($lead, $data['to'], $actor, $request, $data);

        return back()->with('status', __('ui.leads.saved'));
    }

    public function import(Request $request, LeadDesk $leads, AccessGate $access): RedirectResponse
    {
        Gate::authorize('create', Lead::class);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'campaign_id' => ['required', 'integer', 'exists:campaigns,id'],
        ]);
        $count = $leads->importCsv($data['file']->getContent(), (int) $data['campaign_id'], $this->actor($request, $access), $request);

        return back()->with('status', __('ui.leads.imported', ['count' => $count]));
    }

    public function storeSource(Request $request, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'leads.assign'), 403);
        $data = $request->validate([
            'channel' => ['required', 'string', 'max:30', 'unique:lead_sources,channel'],
            'name_en' => ['required', 'string', 'max:80'],
            'name_ar' => ['required', 'string', 'max:80'],
        ]);
        LeadSource::query()->create($data);

        return back()->with('status', __('ui.leads.saved'));
    }

    public function storeCampaign(Request $request, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'leads.assign'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'channel' => ['nullable', 'string', 'max:30'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'external_id' => ['nullable', 'string', 'max:80'],
            'utm_source' => ['nullable', 'string', 'max:80'],
            'utm_medium' => ['nullable', 'string', 'max:80'],
            'utm_campaign' => ['nullable', 'string', 'max:80'],
        ]);
        Campaign::query()->create([
            'name' => $data['name'],
            'channel' => $data['channel'] ?? 'campaign',
            'budget' => $data['budget'] ?? null,
            'currency' => 'USD',
            'external_id' => $data['external_id'] ?? null,
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'owner_id' => $actor->id,
        ]);

        return back()->with('status', __('ui.leads.saved'));
    }

    public function storeReason(Request $request, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'leads.assign'), 403);
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:80'],
            'name_ar' => ['required', 'string', 'max:80'],
        ]);
        DisqualifyReason::query()->create([
            'key' => str($data['name_en'])->slug('_')->toString(),
            'name_en' => $data['name_en'],
            'name_ar' => $data['name_ar'],
        ]);

        return back()->with('status', __('ui.leads.saved'));
    }

    public function assignment(Request $request, LeadDesk $leads, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'leads.assign'), 403);
        $data = $request->validate([
            'round_robin' => ['required', 'boolean'],
            'by_area' => ['required', 'boolean'],
            'by_language' => ['required', 'boolean'],
            'by_service' => ['required', 'boolean'],
            'cap' => ['required', 'integer', 'min:0', 'max:100'],
            'no_answer_limit' => ['required', 'integer', 'min:1', 'max:10'],
        ]);
        $leads->saveAssignment($data, $actor, $request);

        return back()->with('status', __('ui.leads.rule_saved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
            'alt_phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'channel' => ['nullable', 'string', 'max:30'],
            'service_type_interest' => ['nullable', 'string', 'max:30'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'address_text' => ['nullable', 'string', 'max:240'],
            'location' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'referrer' => ['nullable', 'string', 'max:40'],
            'language' => ['nullable', 'string', 'max:8'],
            'force' => ['nullable', 'boolean'],
            'force_reason' => ['nullable', 'string', 'max:200'],
            'intent' => ['nullable', 'in:upsell,support'],
            'attach_to' => ['nullable', 'integer'],
            'brought_by_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
