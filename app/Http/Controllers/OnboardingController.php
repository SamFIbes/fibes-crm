<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\OnboardingCard;
use App\Models\OnboardingSetting;
use App\Models\OnboardingStage;
use App\Models\User;
use App\Services\Onboarding\OnboardingDesk;
use App\Support\Access\AccessGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/*
 * GET /onboarding -> index (onboarding)
 * GET /onboarding/board/meta -> meta (onboarding.meta)
 * GET /onboarding/board/columns/{stage} -> column (onboarding.column)
 * GET /onboarding/attention -> attention (onboarding.attention)
 * GET /onboarding/cards/{card} -> show (onboarding.cards.show)
 * POST /onboarding/cards -> store (onboarding.cards.store)
 * POST /onboarding/cards/{card}/move -> move
 * POST /onboarding/cards/{card}/assign -> assign
 * POST /onboarding/cards/{card}/notes -> note
 * PUT /onboarding/cards/{card}/followup -> followup
 * POST /onboarding/sync -> sync
 * GET /onboarding/settings -> settings
 * PUT /onboarding/settings -> updateSettings
 * POST /leads/{lead}/onboarding-card -> storeFromLead (leads.onboarding)
 * POST /customers/{customer}/onboarding-card -> storeFromCustomer (customers.onboarding)
 */
class OnboardingController extends Controller
{
    public function __construct(
        private OnboardingDesk $desk,
        private AccessGate $access,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->viewer($request);
        $this->desk->syncIntake($actor);

        return Inertia::render('Onboarding/Index', [
            'can_edit' => $this->desk->canEdit($actor),
            'can_manage' => $this->desk->canManage($actor),
            'stale_days' => $this->desk->staleDays(),
            'filters' => $this->filters($request),
        ]);
    }

    public function meta(Request $request): JsonResponse
    {
        return response()->json($this->desk->meta($this->viewer($request), $this->filters($request)));
    }

    public function column(Request $request, string $stage): JsonResponse
    {
        $actor = $this->viewer($request);
        $data = $request->validate(['after' => ['nullable', 'integer', 'min:1']]);

        return response()->json($this->desk->column($actor, $stage, $this->filters($request), isset($data['after']) ? (int) $data['after'] : null));
    }

    public function attention(Request $request): JsonResponse
    {
        $actor = $this->viewer($request);

        return response()->json($this->desk->attention($actor) + ['stale_days' => $this->desk->staleDays()]);
    }

    public function show(Request $request, OnboardingCard $card): JsonResponse
    {
        $actor = $this->viewer($request);
        $this->visibleCard($actor, $card);

        return response()->json($this->desk->detail($actor, $card));
    }

    public function move(Request $request, OnboardingCard $card): JsonResponse|RedirectResponse
    {
        $actor = $this->editor($request, $card);
        $data = $request->validate(['stage' => ['required', 'string', 'max:40']]);
        $card = $this->desk->move($actor, $card, $data['stage']);

        return $this->result($request, $card, 'moved');
    }

    public function assign(Request $request, OnboardingCard $card): JsonResponse|RedirectResponse
    {
        $actor = $this->editor($request, $card);
        $data = $request->validate(['assigned_to' => ['present', 'nullable', 'integer', 'exists:users,id']]);
        $card = $this->desk->assign($actor, $card, isset($data['assigned_to']) ? (int) $data['assigned_to'] : null);

        return $this->result($request, $card, 'assigned');
    }

    public function note(Request $request, OnboardingCard $card): JsonResponse|RedirectResponse
    {
        $actor = $this->editor($request, $card);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $this->desk->addNote($actor, $card, $data['body']);

        return $this->result($request, $card, 'noted');
    }

    public function followup(Request $request, OnboardingCard $card): JsonResponse|RedirectResponse
    {
        $actor = $this->editor($request, $card);
        $data = $request->validate([
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
            'snooze_days' => ['nullable', 'integer', 'in:1,3,7'],
        ]);

        if (isset($data['snooze_days'])) {
            $this->desk->snooze($actor, $card, (int) $data['snooze_days']);
        } else {
            $this->desk->setFollowup($actor, $card, $data['due_on'] ?? null, $data['note'] ?? null);
        }

        return $this->result($request, $card, 'followup_saved');
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $actor = $this->editor($request);
        $data = $request->validate([
            'lead_id' => ['nullable', 'integer', 'exists:leads,id', 'prohibits:customer_id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'title' => ['required_without_all:lead_id,customer_id', 'nullable', 'string', 'max:255'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'language' => ['nullable', 'string', 'max:5'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'salesperson_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (! empty($data['lead_id'])) {
            $card = $this->desk->fromLead($actor, Lead::query()->findOrFail($data['lead_id']));
        } elseif (! empty($data['customer_id'])) {
            $card = $this->desk->fromCustomer($actor, Customer::query()->findOrFail($data['customer_id']));
        } else {
            $card = $this->desk->createManual($actor, $data);
        }

        return $this->created($request, $card);
    }

    public function storeFromLead(Request $request, Lead $lead): JsonResponse|RedirectResponse
    {
        return $this->created($request, $this->desk->fromLead($this->editor($request), $lead));
    }

    public function storeFromCustomer(Request $request, Customer $customer): JsonResponse|RedirectResponse
    {
        return $this->created($request, $this->desk->fromCustomer($this->editor($request), $customer));
    }

    public function sync(Request $request): JsonResponse|RedirectResponse
    {
        $count = $this->desk->syncIntake($this->viewer($request));

        return $request->wantsJson()
            ? response()->json(['created' => $count])
            : back()->with('status', __('ui.onboarding.synced'));
    }

    public function settings(Request $request): JsonResponse
    {
        $this->manager($request);

        return response()->json([
            'stages' => $this->desk->stages(),
            'stale_days' => $this->desk->staleDays(),
            'backfill_days' => $this->desk->backfillDays(),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse|RedirectResponse
    {
        $this->manager($request);
        $data = $request->validate([
            'stale_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:90'],
            'backfill_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:365'],
            'stages' => ['sometimes', 'required', 'array', 'min:1'],
            'stages.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'stages.*.name_en' => ['required', 'string', 'max:255'],
            'stages.*.name_ar' => ['required', 'string', 'max:255'],
            'stages.*.position' => ['required', 'integer', 'min:0', 'max:65535'],
            'stages.*.task_title_en' => ['required', 'string', 'max:255'],
            'stages.*.task_title_ar' => ['required', 'string', 'max:255'],
            'stages.*.is_terminal' => ['required', 'boolean'],
        ]);

        if (isset($data['stages']) && collect($data['stages'])->filter(fn (array $stage) => (bool) $stage['is_terminal'])->count() !== 1) {
            throw ValidationException::withMessages(['stages' => __('ui.onboarding.one_terminal_required')]);
        }

        DB::transaction(function () use ($data): void {
            $this->desk->ensureStages();

            if (isset($data['stages'])) {
                $existing = OnboardingStage::query()->lockForUpdate()->get();
                $keys = collect($data['stages'])->pluck('key')->all();
                $removed = $existing->pluck('key')->diff($keys)->values()->all();

                if ($removed !== [] && OnboardingCard::query()->whereIn('stage_key', $removed)->exists()) {
                    throw ValidationException::withMessages(['stages' => __('ui.onboarding.stage_has_cards')]);
                }

                foreach ($data['stages'] as $stage) {
                    OnboardingStage::query()->updateOrCreate(['key' => $stage['key']], $stage);
                }

                if ($removed !== []) {
                    OnboardingStage::query()->whereIn('key', $removed)->delete();
                }
            }

            foreach (['stale_days', 'backfill_days'] as $key) {
                if (array_key_exists($key, $data)) {
                    OnboardingSetting::query()->updateOrCreate(['key' => $key], ['value' => (string) $data[$key]]);
                }
            }
        });

        return $request->wantsJson()
            ? response()->json(['stages' => $this->desk->stages(), 'stale_days' => $this->desk->staleDays(), 'backfill_days' => $this->desk->backfillDays()])
            : back()->with('status', __('ui.onboarding.settings_saved'));
    }

    private function actor(Request $request): User
    {
        $actor = $this->access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function viewer(Request $request): User
    {
        $actor = $this->actor($request);
        abort_unless($this->desk->canView($actor), 403);

        return $actor;
    }

    private function editor(Request $request, ?OnboardingCard $card = null): User
    {
        $actor = $this->actor($request);
        abort_unless($this->desk->canEdit($actor), 403);

        if ($card) {
            $this->visibleCard($actor, $card);
        }

        return $actor;
    }

    private function manager(Request $request): User
    {
        $actor = $this->actor($request);
        abort_unless($this->desk->canManage($actor), 403);

        return $actor;
    }

    private function visibleCard(User $actor, OnboardingCard $card): void
    {
        abort_unless($this->desk->visible($actor)->whereKey($card->id)->exists(), 404);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'assignee' => ['nullable', 'regex:/^(me|[1-9][0-9]*)$/'],
            'area_id' => ['nullable', 'integer', 'min:1'],
            'stale' => ['nullable', 'boolean'],
            'mine' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function result(Request $request, OnboardingCard $card, string $status): JsonResponse|RedirectResponse
    {
        return $request->wantsJson()
            ? response()->json(['card' => $this->desk->present($card->fresh(), $this->actor($request))])
            : back()->with('status', __("ui.onboarding.{$status}"));
    }

    private function created(Request $request, OnboardingCard $card): JsonResponse|RedirectResponse
    {
        return $request->wantsJson()
            ? response()->json(['card' => $this->desk->present($card, $this->actor($request))])
            : redirect('/onboarding?card='.$card->id)->with('status', __('ui.onboarding.created'));
    }
}
