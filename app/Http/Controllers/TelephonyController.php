<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Calls\CallDesk;
use App\Services\Customers\CustomerRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
