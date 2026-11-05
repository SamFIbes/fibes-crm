<?php

namespace App\Http\Controllers;

use App\Services\Payments\WhishSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhishWebhookController extends Controller
{
    public function __invoke(Request $request, WhishSettlement $settlement): JsonResponse
    {
        $payload = $request->json()->all();
        $result = $settlement->apply(is_array($payload) ? $payload : [], $request);

        return response()->json([
            'ok' => $result['ok'],
            'applied' => $result['applied'],
        ], $result['status']);
    }
}
