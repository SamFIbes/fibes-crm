<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Customer;
use App\Models\EscalationTier;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketCheck;
use App\Models\TicketView;
use App\Models\User;
use App\Services\Customers\CustomerRecords;
use App\Services\Files\FileDesk;
use App\Services\Integrations\WhatsAppBusinessClient;
use App\Services\Integrations\WhatsAppDesk;
use App\Services\Tickets\EscalationLadder;
use App\Services\Tickets\IncidentRecords;
use App\Services\Tickets\TicketRecords;
use App\Support\Access\AccessGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class TicketController extends Controller
{
    public function index(Request $request, TicketRecords $records, AccessGate $access): Response
    {
        $actor = $this->actor($request, $access);
        $filters = [
            'q' => (string) $request->string('q'),
            'status' => (string) $request->string('status'),
            'priority' => (string) $request->string('priority'),
            'assignee_id' => $request->integer('assignee_id') ?: null,
            'area_id' => $request->integer('area_id') ?: null,
            'service_type' => (string) $request->string('service_type'),
            'sla' => (string) $request->string('sla'),
            'mine' => $request->boolean('mine'),
            'tier' => $request->integer('tier') ?: null,
            'customer' => $request->integer('customer') ?: null,
        ];

        $query = $records->query($actor)->with(['customer.area', 'category', 'assignee:id,name', 'service:id,service_type']);

        if ($filters['mine']) {
            $query->where('assignee_id', $actor->id);
        }
        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if ($filters['priority'] !== '') {
            $query->where('priority', $filters['priority']);
        }
        if ($filters['assignee_id']) {
            $query->where('assignee_id', $filters['assignee_id']);
        }
        if ($filters['area_id']) {
            $query->whereHas('customer', fn ($customers) => $customers->where('area_id', $filters['area_id']));
        }
        if ($filters['service_type'] !== '') {
            $query->whereHas('service', fn ($services) => $services->where('service_type', $filters['service_type']));
        }
        if ($filters['sla'] === 'warning') {
            $query->where(fn ($inner) => $inner->where('response_warned', true)->orWhere('resolve_warned', true));
        }
        if ($filters['sla'] === 'breached') {
            $query->where(fn ($inner) => $inner->where('response_breached', true)->orWhere('resolve_breached', true));
        }
        if ($filters['tier']) {
            $query->where('escalation_tier_id', $filters['tier']);
        }
        if ($filters['q'] !== '') {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($inner) => $inner->where('number', 'like', $term)->orWhere('subject', 'like', $term));
        }

        return Inertia::render('Tickets/Index', [
            'tickets' => $query->latest()->limit(200)->get()->map(fn (Ticket $ticket) => $records->present($ticket))->values(),
            'filters' => $filters,
            'categories' => TicketCategory::query()->where('is_active', true)->orderBy('sort')->get(['id', 'name_en', 'name_ar']),
            'areas' => Area::query()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'technicians' => User::query()->whereIn('role', ['field_technician', 'installer'])->orderBy('name')->get(['id', 'name']),
            'teams' => Team::query()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'views' => TicketView::query()->where('user_id', $actor->id)->orderBy('name')->get(['id', 'name', 'filters']),
            'can_create' => $access->allows($actor, 'tickets.create'),
            'can_move' => $records->canTouch($actor),
            'can_escalate' => $access->allows($actor, 'tickets.escalate'),
            'service_types' => array_keys(config('fibes.service_types')),
            'prefill' => $this->prefill($request, $records, $actor),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function prefill(Request $request, TicketRecords $records, User $actor): ?array
    {
        $id = $request->integer('customer');

        if ($id === 0) {
            return null;
        }

        $customer = Customer::query()->with('services:id,customer_id,service_type')->find($id);

        if (! $customer instanceof Customer || ! app(CustomerRecords::class)->query($actor)->whereKey($customer->id)->exists()) {
            return null;
        }

        return [
            'id' => $customer->id,
            'name_en' => $customer->name_en,
            'name_ar' => $customer->name_ar,
            'account_no' => $customer->account_no,
            'services' => $customer->services->map(fn ($service) => ['id' => $service->id, 'service_type' => $service->service_type])->all(),
        ];
    }

    public function lookup(Request $request, CustomerRecords $customers, AccessGate $access): JsonResponse
    {
        $actor = $this->actor($request, $access);
        $term = (string) $request->string('q');
        $digits = preg_replace('/\D+/', '', $term) ?? '';
        $like = '%'.$term.'%';

        $rows = $customers->query($actor)
            ->with('services:id,customer_id,service_type')
            ->where(function ($inner) use ($like, $digits) {
                $inner->where('name_en', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('account_no', 'like', $like);
                if ($digits !== '') {
                    $inner->orWhereHas('phones', fn ($phones) => $phones->where('phone_e164', 'like', '%'.$digits.'%'));
                }
            })
            ->limit(8)
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name_en' => $customer->name_en,
                'name_ar' => $customer->name_ar,
                'account_no' => $customer->account_no,
                'services' => $customer->services->map(fn ($service) => ['id' => $service->id, 'service_type' => $service->service_type])->all(),
            ]);

        return response()->json($rows);
    }

    public function store(Request $request, TicketRecords $records, AccessGate $access): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'customer_service_id' => ['nullable', 'integer', 'exists:customer_services,id'],
            'category_id' => ['required', 'integer', 'exists:ticket_categories,id'],
            'priority' => ['required', 'in:P1,P2,P3,P4'],
            'channel' => ['required', 'in:phone,whatsapp,walk_in,email,field'],
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
        $ticket = $records->create($data, $this->actor($request, $access), $request);

        return redirect()->route('tickets.show', $ticket)->with('status', __('ui.tickets.saved'));
    }

    public function show(Request $request, Ticket $ticket, TicketRecords $records, AccessGate $access, EscalationLadder $ladder, IncidentRecords $incidents): Response
    {
        $actor = $this->actor($request, $access);
        $this->authorizeTicket($actor, $ticket, $records);
        $ticket->loadMissing('tier', 'customer');
        $level = (int) ($ticket->tier?->level ?? 1);

        return Inertia::render('Tickets/Show', [
            'ticket' => $records->present($ticket, true),
            'technicians' => User::query()->whereIn('role', ['field_technician', 'installer', 'noc_engineer'])->orderBy('name')->get(['id', 'name']),
            'teams' => Team::query()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'ladder' => [
                'rule' => $ladder->rule(),
                'up' => $this->tierChoice(EscalationTier::query()->with('members:id,name')->where('level', $level + 1)->first()),
                'down' => $this->tierChoice(EscalationTier::query()->with('members:id,name')->where('level', $level - 1)->first()),
            ],
            'incidents' => $ticket->customer ? $incidents->bannersFor($ticket->customer) : [],
            'can_move' => $records->canTouch($actor),
            'can_escalate' => $access->allows($actor, 'tickets.escalate'),
            'can_upload' => $access->allows($actor, 'attachments.upload'),
            'can_whatsapp' => app(WhatsAppBusinessClient::class)->ready()
                && (bool) $ticket->customer?->whatsapp_consent
                && filled($ticket->customer?->whatsapp_e164),
        ]);
    }

    public function updateStatus(Request $request, Ticket $ticket, TicketRecords $records, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $this->authorizeTicket($actor, $ticket, $records);
        abort_unless($records->canTouch($actor), 403);
        $data = $request->validate([
            'status' => ['required', 'in:new,open,pending_customer,escalated,resolved,closed,reopened'],
        ]);
        $records->changeStatus($ticket, $data['status'], $actor, $request);

        return back();
    }

    public function whatsapp(Request $request, Ticket $ticket, TicketRecords $records, AccessGate $access, WhatsAppDesk $whatsapp): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $this->authorizeTicket($actor, $ticket, $records);
        $ticket->loadMissing('customer');
        $customer = $ticket->customer;

        if (! $customer instanceof Customer) {
            return back()->withErrors(['whatsapp' => __('ui.whatsapp.off')]);
        }

        try {
            $result = $whatsapp->queue($customer, 'ticket', [
                (string) $ticket->number,
                (string) $ticket->status,
            ], $actor);
        } catch (Throwable) {
            return back()->withErrors(['whatsapp' => __('ui.whatsapp.failed')]);
        }

        return match ($result) {
            'queued' => back()->with('status', __('ui.whatsapp.sent')),
            'no_consent' => back()->withErrors(['whatsapp' => __('ui.whatsapp.no_consent')]),
            default => back()->withErrors(['whatsapp' => __('ui.whatsapp.off')]),
        };
    }

    public function escalate(Request $request, Ticket $ticket, TicketRecords $records, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $this->authorizeTicket($actor, $ticket, $records);
        $data = $request->validate([
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'reason' => ['required', 'string', 'max:160'],
            'note' => ['required', 'string', 'max:2000'],
            'direction' => ['nullable', 'in:escalate,deescalate'],
            'tier_id' => ['nullable', 'integer', 'exists:escalation_tiers,id'],
        ]);
        $data['direction'] = $request->input('direction', 'escalate');
        $direction = $records->escalate($ticket, $data, $actor, $request);

        return back()->with('status', __($direction === 'deescalate' ? 'ui.tickets.deescalated' : 'ui.tickets.escalated'));
    }

    public function reply(Request $request, Ticket $ticket, TicketRecords $records, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $this->authorizeTicket($actor, $ticket, $records);
        abort_unless($records->canTouch($actor), 403);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'visibility' => ['required', 'in:public,internal'],
        ]);
        $records->reply($ticket, $data['body'], $data['visibility'], $actor, $request);

        return back()->with('status', __('ui.tickets.replied'));
    }

    public function storeFile(Request $request, Ticket $ticket, TicketRecords $records, AccessGate $access, FileDesk $files): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $this->authorizeTicket($actor, $ticket, $records);
        $max = (int) config('fibes.attachments.max_kilobytes');
        $upload = $request->validate([
            'file' => ['required', 'file', 'max:'.$max, 'mimes:pdf,jpg,jpeg,png,webp,txt,csv'],
        ])['file'];
        $files->store($upload, $actor, $request, $ticket, 'ticket.file.stored', [
            'customer_id' => $ticket->customer_id,
            'ticket_id' => $ticket->id,
        ]);

        return back()->with('status', __('ui.files.saved'));
    }

    public function toggleCheck(Request $request, Ticket $ticket, TicketCheck $check, TicketRecords $records, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $this->authorizeTicket($actor, $ticket, $records);
        abort_unless($check->ticket_id === $ticket->id, 404);
        $data = $request->validate(['done' => ['required', 'boolean']]);
        $check->done = (bool) $data['done'];
        $check->save();

        return back();
    }

    public function storeView(Request $request, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'filters' => ['required', 'array']]);
        TicketView::query()->create(['user_id' => $actor->id, 'name' => $data['name'], 'filters' => $data['filters']]);

        return back();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tierChoice(?EscalationTier $tier): ?array
    {
        if (! $tier instanceof EscalationTier) {
            return null;
        }

        return [
            'id' => $tier->id,
            'level' => $tier->level,
            'name_en' => $tier->name_en,
            'name_ar' => $tier->name_ar,
            'members' => $tier->members->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'on_duty' => (bool) $user->pivot->on_duty,
            ])->values(),
        ];
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function authorizeTicket(User $actor, Ticket $ticket, TicketRecords $records): void
    {
        abort_unless($records->query($actor)->whereKey($ticket->id)->exists(), 403);
    }
}
