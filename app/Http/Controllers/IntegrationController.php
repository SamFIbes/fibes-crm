<?php

namespace App\Http\Controllers;

use App\Models\StockLocation;
use App\Models\ErpSyncRun;
use App\Models\User;
use App\Services\Integrations\ErpNext\ErpNextClient;
use App\Services\Integrations\GrandstreamClient;
use App\Services\Integrations\IntegrationRecorder;
use App\Services\Integrations\IntegrationSettings;
use App\Services\Integrations\KryptonRadiusClient;
use App\Services\Integrations\WhatsAppBusinessClient;
use App\Services\Integrations\WhishClient;
use App\Support\Access\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class IntegrationController extends Controller
{
    public function edit(Request $request, IntegrationSettings $settings, AccessGate $access): Response
    {
        $actor = $access->effective($request);

        return Inertia::render('Settings/Integrations', [
            'cards' => $settings->cards(),
            'locations' => StockLocation::query()->orderBy('name')->get(['id', 'name', 'kind']),
            'can_manage' => $actor instanceof User && $access->allows($actor, 'integrations.manage'),
            'erp_sync_runs' => ErpSyncRun::query()
                ->orderByDesc('started_at')
                ->limit(20)
                ->get([
                    'id', 'mode', 'status', 'scanned', 'would_create', 'would_update',
                    'unchanged', 'ambiguous', 'errors', 'contacts', 'addresses',
                    'hub_contact_count', 'hub_address_count', 'cursor', 'message',
                    'started_at', 'finished_at',
                ])
                ->toArray(),
        ]);
    }

    public function update(Request $request, IntegrationSettings $settings, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate([
            'integrations' => ['required', 'array'],
            'integrations.erpnext.enabled' => ['required', 'boolean'],
            'integrations.erpnext.url' => ['nullable', 'string', 'max:200'],
            'integrations.erpnext.company' => ['nullable', 'string', 'max:140'],
            'integrations.erpnext.warehouse' => ['nullable', 'string', 'max:140'],
            'integrations.erpnext.purpose' => ['required', 'in:Purchase,Material Transfer,Material Issue'],
            'integrations.erpnext.warehouses' => ['array'],
            'integrations.erpnext.warehouses.*.location_id' => ['required', 'integer', 'exists:stock_locations,id'],
            'integrations.erpnext.warehouses.*.warehouse' => ['nullable', 'string', 'max:140'],
            'integrations.krypton.enabled' => ['required', 'boolean'],
            'integrations.krypton.url' => ['nullable', 'string', 'max:200'],
            'integrations.whish.enabled' => ['required', 'boolean'],
            'integrations.whish.url' => ['nullable', 'string', 'max:200'],
            'integrations.grandstream.enabled' => ['required', 'boolean'],
            'integrations.grandstream.url' => ['nullable', 'string', 'max:200'],
            'integrations.whatsapp.enabled' => ['required', 'boolean'],
            'integrations.whatsapp.phone_number_id' => ['nullable', 'string', 'max:64'],
        ]);

        foreach (['erpnext', 'krypton', 'whish', 'grandstream'] as $key) {
            $url = trim((string) ($data['integrations'][$key]['url'] ?? ''));

            if ($url !== '' && ! filter_var($url, FILTER_VALIDATE_URL)) {
                return back()->withErrors(['integrations.'.$key.'.url' => __('ui.integrations.url_invalid')]);
            }
        }

        $settings->save($data['integrations'], $actor, $request);

        return back()->with('status', __('ui.integrations.saved'));
    }

    public function test(
        Request $request,
        string $integration,
        IntegrationSettings $settings,
        IntegrationRecorder $recorder,
        ErpNextClient $erp,
        KryptonRadiusClient $krypton,
        WhishClient $whish,
        GrandstreamClient $grandstream,
        WhatsAppBusinessClient $whatsapp,
        AccessGate $access,
    ): RedirectResponse {
        abort_unless(in_array($integration, IntegrationSettings::KEYS, true), 404);
        $actor = $this->actor($request, $access);

        $result = $integration === 'erpnext'
            ? $this->testErp($erp, $settings, $recorder, $actor, $request)
            : $this->testClient($integration, match ($integration) {
                'krypton' => $krypton->testConnection(),
                'whish' => $whish->testConnection(),
                'grandstream' => $grandstream->testConnection(),
                default => $whatsapp->testConnection(),
            }, $recorder, $actor, $request);

        if (! $result['ok']) {
            return back()->withErrors(['test' => $result['message']]);
        }

        return back()->with('status', $result['message']);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function testErp(ErpNextClient $erp, IntegrationSettings $settings, IntegrationRecorder $recorder, User $actor, Request $request): array
    {
        if (! $settings->runtime('erpnext')['live']) {
            $recorder->attempt('erpnext', 'ping', 'failed', 'not_configured', $actor, [], $request);

            return ['ok' => false, 'message' => __('ui.integrations.not_configured')];
        }

        try {
            $result = $erp->testConnection();
        } catch (Throwable $exception) {
            $recorder->attempt('erpnext', 'ping', 'failed', $exception->getMessage(), $actor, [], $request);

            return ['ok' => false, 'message' => __('ui.integrations.test_failed')];
        }

        $recorder->attempt('erpnext', 'ping', $result['ok'] ? 'succeeded' : 'failed', $result['message'], $actor, [], $request);

        return [
            'ok' => $result['ok'],
            'message' => $result['ok'] ? __('ui.integrations.tested') : __('ui.integrations.unexpected'),
        ];
    }

    /**
     * @param  array{ok: bool, message: string}  $result
     * @return array{ok: bool, message: string}
     */
    private function testClient(string $integration, array $result, IntegrationRecorder $recorder, User $actor, Request $request): array
    {
        if (! $result['ok'] && $result['message'] === 'not_configured') {
            $recorder->attempt($integration, 'ping', 'failed', 'not_configured', $actor, [], $request);

            return ['ok' => false, 'message' => __('ui.integrations.not_configured')];
        }

        $recorder->attempt($integration, 'ping', $result['ok'] ? 'succeeded' : 'failed', $result['message'], $actor, [], $request);

        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message'] === 'unexpected'
                ? __('ui.integrations.unexpected')
                : __('ui.integrations.test_failed')];
        }

        return ['ok' => true, 'message' => __('ui.integrations.tested_live')];
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
