<?php

namespace App\Http\Controllers;

use App\Services\Integrations\IntegrationRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpNextWebhookController extends Controller
{
    public function __invoke(Request $request, IntegrationRecorder $recorder): JsonResponse
    {
        $payload = $request->json()->all();
        $doctype = is_string($payload['doctype'] ?? null) ? $payload['doctype'] : null;
        $name = is_string($payload['name'] ?? null) ? $payload['name'] : null;

        $recorder->attempt('erpnext', 'webhook', 'succeeded', 'Stored for a later stock update.', null, [
            'doctype' => $doctype,
            'erp_name' => $name,
        ], $request);

        return response()->json(['ok' => true, 'applied' => false], 202);
    }
}
