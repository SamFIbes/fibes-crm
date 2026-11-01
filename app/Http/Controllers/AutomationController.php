<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogger;
use App\Services\Automations\AutomationDesk;
use App\Services\Settings\CommercialSettings;
use App\Services\Settings\SettingsStore;
use App\Support\Access\AccessGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    public function index(Request $request, AccessGate $access): Response
    {
        $actor = $this->actor($request, $access);

        return Inertia::render('Automations/Index', [
            'rules' => Automation::query()->where('is_current', true)->orderBy('key')->get()->map(fn (Automation $rule) => [
                'id' => $rule->id,
                'key' => $rule->key,
                'version' => $rule->version,
                'name' => app()->getLocale() === 'ar' ? $rule->name_ar : $rule->name_en,
                'trigger' => $rule->trigger,
                'enabled' => $rule->enabled,
            ]),
            'runs' => AutomationRun::query()->latest('id')->limit(12)->get(['id', 'trigger', 'status', 'depth', 'message']),
            'webhooks' => WebhookEndpoint::query()->latest('id')->get()->map(fn (WebhookEndpoint $hook) => [
                'id' => $hook->id,
                'name' => $hook->name,
                'url' => $hook->url,
                'enabled' => $hook->enabled,
                'events' => $hook->events,
            ]),
            'deliveries' => WebhookDelivery::query()->latest('id')->limit(8)->get(['id', 'event', 'status', 'attempts', 'last_error']),
            'keys' => ApiKey::query()->latest('id')->get(['id', 'name', 'scopes', 'last_used_at', 'expires_at']),
            'health' => $this->health(),
            'paused' => (bool) app(CommercialSettings::class)->get('automations_paused'),
            'can_manage' => $access->allows($actor, 'automations.manage'),
            'plain_key' => $request->session()->get('api_key'),
            'plain_secret' => $request->session()->get('webhook_secret'),
        ]);
    }

    public function store(Request $request, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
            'trigger' => ['required', 'string', 'max:40'],
            'field' => ['nullable', 'string', 'max:40'],
            'cmp' => ['nullable', 'in:eq,gt,lt'],
            'value' => ['nullable', 'string', 'max:80'],
            'action' => ['required', 'in:create_task,notify,add_tag,noc_task,send_whatsapp,dispatch'],
            'action_value' => ['nullable', 'string', 'max:120'],
        ]);
        $conditions = ['op' => 'and', 'rules' => []];

        if (! empty($data['field'])) {
            $conditions['rules'][] = ['field' => $data['field'], 'cmp' => $data['cmp'] ?? 'eq', 'value' => $data['value'] ?? ''];
        }

        $action = ['type' => $data['action']];

        if ($data['action'] === 'notify') {
            $action['role'] = $data['action_value'] ?: 'cc_supervisor';
            $action['title'] = $data['name_en'];
        } elseif ($data['action'] === 'add_tag') {
            $action['tag'] = $data['action_value'] ?: 'at-risk';
        } elseif ($data['action'] === 'dispatch') {
            $action['trigger'] = $data['action_value'] ?: $data['trigger'];
        } elseif ($data['action'] === 'create_task') {
            $action['title'] = $data['action_value'] ?: $data['name_en'];
            $action['hours'] = (int) app(CommercialSettings::class)->get('followup_hours');
        }

        $rule = Automation::query()->create([
            'key' => 'custom_'.Str::lower(Str::random(8)),
            'version' => 1,
            'name_en' => $data['name_en'],
            'name_ar' => $data['name_ar'],
            'trigger' => $data['trigger'],
            'conditions' => $conditions,
            'actions' => [$action],
            'enabled' => true,
            'is_current' => true,
            'rate_per_hour' => (int) app(CommercialSettings::class)->get('automations_rate_per_hour'),
            'created_by' => $actor->id,
        ]);
        $audit->record('automation.created', $actor, ['id' => $rule->id, 'key' => $rule->key, 'trigger' => $rule->trigger], $request);

        return back()->with('status', __('ui.automations.saved'));
    }

    public function update(Request $request, Automation $automation, AccessGate $access, AutomationDesk $desk): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'name_ar' => ['nullable', 'string', 'max:120'],
        ]);
        $desk->revise($automation, $data, $actor, $request);

        return back()->with('status', __('ui.automations.saved'));
    }

    public function dry(Request $request, Automation $automation, AccessGate $access, AutomationDesk $desk): RedirectResponse
    {
        $this->actor($request, $access);
        $preview = $desk->dryRun($automation);

        return back()->with('status', __('ui.automations.preview', ['count' => $preview['matches']]));
    }

    public function pause(Request $request, AccessGate $access, AuditLogger $audit, SettingsStore $settings, CommercialSettings $commercial): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate(['paused' => ['required', 'boolean']]);
        $next = $commercial->all();
        $next['automations_paused'] = $data['paused'];
        $settings->put('commercial', $next, $actor, $request);
        $audit->record('automation.paused', $actor, ['paused' => $data['paused']], $request);

        return back()->with('status', __('ui.automations.saved'));
    }

    public function webhook(Request $request, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:200'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'max:40'],
        ]);
        $secret = Str::random(32);
        $hook = WebhookEndpoint::query()->create([
            'name' => $data['name'],
            'url' => $data['url'],
            'secret' => $secret,
            'events' => $data['events'],
            'enabled' => true,
            'created_by' => $actor->id,
        ]);
        $audit->record('webhook.created', $actor, ['id' => $hook->id, 'name' => $hook->name], $request);

        return back()->with('webhook_secret', $secret)->with('status', __('ui.automations.secret_once'));
    }

    public function key(Request $request, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);
        $plain = 'fibes_'.Str::random(40);
        ApiKey::query()->create([
            'name' => $data['name'],
            'token_hash' => hash('sha256', $plain),
            'scopes' => ['automations.read'],
            'expires_at' => now()->addDays((int) ($data['days'] ?? (int) app(CommercialSettings::class)->get('api_key_days'))),
            'created_by' => $actor->id,
        ]);
        $audit->record('api_key.created', $actor, ['name' => $data['name']], $request);

        return back()->with('api_key', $plain)->with('status', __('ui.automations.secret_once'));
    }

    public function ping(Request $request): JsonResponse
    {
        $header = (string) $request->bearerToken();
        $key = $header === '' ? null : ApiKey::query()->where('token_hash', hash('sha256', $header))->first();

        if (! $key instanceof ApiKey || ($key->expires_at && $key->expires_at->isPast()) || ! in_array('automations.read', $key->scopes ?? [], true)) {
            abort(401);
        }

        $key->last_used_at = now();
        $key->save();

        return response()->json(['ok' => true]);
    }

    /**
     * @return list<array{key: string, ok: bool, detail: string}>
     */
    private function health(): array
    {
        $last = WebhookDelivery::query()->where('status', 'delivered')->latest('delivered_at')->first();
        $error = WebhookDelivery::query()->where('status', 'dead')->latest('id')->value('last_error');

        return [
            ['key' => 'webhooks', 'ok' => $error === null, 'detail' => $last?->delivered_at?->timezone('Asia/Beirut')->format('d/m/Y H:i') ?? ''],
            ['key' => 'email', 'ok' => (string) config('mail.default') !== '', 'detail' => (string) config('mail.default')],
            ['key' => 'sms', 'ok' => true, 'detail' => (string) config('fibes.documents.sms')],
            ['key' => 'pdf', 'ok' => true, 'detail' => (string) config('fibes.documents.driver')],
            ['key' => 'tiles', 'ok' => (string) config('fibes.coverage.tile_url') !== '', 'detail' => 'configured'],
        ];
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
