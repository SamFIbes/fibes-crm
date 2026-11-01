<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\User;
use App\Models\WrapUpCode;
use App\Services\Calls\CallDesk;
use App\Services\Customers\CustomerRecords;
use App\Services\Leads\LeadDuplicateException;
use App\Support\Access\AccessGate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class CallController extends Controller
{
    public function index(Request $request, CallDesk $desk, AccessGate $access): Response
    {
        $actor = $this->actor($request, $access);
        $phone = (string) $request->string('phone');
        $pop = $desk->pullPop($actor);

        if ($phone === '' && is_array($pop) && filled($pop['phone'] ?? null)) {
            $phone = (string) $pop['phone'];
        }

        return Inertia::render('Calls/Index', [
            'phone' => $phone,
            'result' => $phone === '' ? null : $desk->lookup($phone, $actor),
            'wrap_up_codes' => WrapUpCode::query()->where('is_active', true)->orderBy('sort')->get(['id', 'name_en', 'name_ar']),
            'on_duty' => $desk->onDuty($actor),
            'extension' => $actor->extension,
            'can_log' => $access->allows($actor, 'calls.log'),
            'can_lead' => $access->allows($actor, 'leads.create'),
            'can_edit' => $access->allows($actor, 'customers.edit'),
            'can_ticket' => $access->allows($actor, 'tickets.create'),
            'can_board' => $access->scopeFor($actor, 'calls.view') === 'all',
        ]);
    }

    public function pop(Request $request, CallDesk $desk, AccessGate $access): JsonResponse
    {
        $payload = $desk->pullPop($this->actor($request, $access));

        return response()->json(['pop' => $payload]);
    }

    public function store(Request $request, CallDesk $desk, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'calls.log'), 403);
        $data = $request->validate([
            'direction' => ['required', 'in:inbound,outbound'],
            'channel' => ['required', 'in:phone,whatsapp'],
            'wrap_up_code_id' => ['required', 'integer', 'exists:wrap_up_codes,id'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'phone' => ['nullable', 'string', 'max:40'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'ticket_id' => ['nullable', 'integer', 'exists:tickets,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'callback_at' => ['nullable', 'date'],
        ]);
        $desk->log($data, $actor, $request);

        return back()->with('status', __('ui.calls.logged'));
    }

    public function storeLead(Request $request, CallDesk $desk, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->allows($actor, 'leads.create'), 403);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $desk->createLead($data, $actor, $request);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['phone' => __('ui.settings_page.phone_invalid')]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => __('ui.calls.lead_exists')]);
        } catch (LeadDuplicateException) {
            throw ValidationException::withMessages(['phone' => __('ui.calls.lead_exists')]);
        }

        return back()->with('status', __('ui.calls.lead_saved'));
    }

    public function duty(Request $request, CallDesk $desk, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate(['on_duty' => ['required', 'boolean']]);
        $desk->setDuty($actor, (bool) $data['on_duty'], $request);

        return back()->with('status', __('ui.calls.duty_saved'));
    }

    public function extension(Request $request, CallDesk $desk, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate([
            'extension' => ['nullable', 'string', 'max:20', 'unique:users,extension,'.$actor->id],
        ]);
        $desk->setExtension($actor, $data['extension'] ?? null, $request);

        return back()->with('status', __('ui.calls.extension_saved'));
    }

    public function board(Request $request, CallDesk $desk, AccessGate $access): Response
    {
        $actor = $this->actor($request, $access);
        abort_unless($access->scopeFor($actor, 'calls.view') === 'all', 403);

        return Inertia::render('Calls/Board', $desk->board());
    }

    public function updateContact(Request $request, Customer $customer, CustomerRecords $records, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        abort_unless($records->query($actor)->whereKey($customer->id)->exists(), 403);
        abort_unless($access->allows($actor, 'customers.edit'), 403);
        $records->update($customer, $request->validate([
            'email' => ['nullable', 'email', 'max:160'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
        ]), $actor, $request);

        return back()->with('status', __('ui.customers.saved'));
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
