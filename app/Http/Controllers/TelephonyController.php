<?php

namespace App\Http\Controllers;

use App\Models\AbandonedCall;
use App\Models\User;
use App\Services\Calls\CallDesk;
use App\Services\Customers\CustomerRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TelephonyController extends Controller
{
    public function incoming(Request $request, CallDesk $desk, CustomerRecords $customers): JsonResponse
    {
        $data = $request->validate([
            'caller' => ['required', 'string', 'max:40'],
            'extension' => ['required', 'string', 'max:20'],
            'direction' => ['nullable', 'in:inbound,outbound'],
            'call_id' => ['nullable', 'string', 'max:80'],
        ]);

        $agent = User::query()->where('extension', $data['extension'])->where('is_active', true)->first();
        $viewer = $agent ?? User::query()->where('role', 'super_admin')->where('is_active', true)->first();
        $lookup = $viewer instanceof User ? $desk->lookup($data['caller'], $viewer) : ['matched' => false];
        $phone = is_string($lookup['phone'] ?? null) ? $lookup['phone'] : $data['caller'];

        $desk->rememberPop($data['extension'], [
            'phone' => $phone,
            'caller' => $data['caller'],
            'customer_id' => $lookup['customer']['id'] ?? null,
            'call_id' => $data['call_id'] ?? null,
        ]);

        return response()->json([
            'matched' => (bool) ($lookup['matched'] ?? false),
            'customer_id' => $lookup['customer']['id'] ?? null,
            'account_no' => $lookup['customer']['account_no'] ?? null,
            'name' => $lookup['customer']['name_en'] ?? null,
            'pop_path' => '/calls?phone='.urlencode($phone),
        ]);
    }

    public function abandoned(Request $request): JsonResponse
    {
        $data = $request->validate([
            'caller' => ['required', 'string', 'max:40'],
            'extension' => ['nullable', 'string', 'max:20'],
            'call_id' => ['nullable', 'string', 'max:80'],
            'abandoned_at' => ['nullable', 'date'],
        ]);
        $values = [
            'caller' => $data['caller'],
            'extension' => $data['extension'] ?? null,
            'abandoned_at' => isset($data['abandoned_at']) ? Carbon::parse($data['abandoned_at']) : now(),
            'created_at' => now(),
        ];

        if (filled($data['call_id'] ?? null)) {
            $row = AbandonedCall::query()->firstOrCreate(['call_id' => $data['call_id']], $values);
        } else {
            $row = AbandonedCall::query()->create($values);
        }

        return response()->json(['ok' => true, 'id' => $row->id, 'stored' => $row->wasRecentlyCreated]);
    }
}
