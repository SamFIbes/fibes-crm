<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\CustomerService;
use App\Models\CustomerView;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\Ledger;
use App\Services\Catalog\CatalogDesk;
use App\Services\Customers\CustomerRecords;
use App\Services\Files\FileDesk;
use App\Services\Tickets\IncidentRecords;
use App\Services\Tickets\TicketRecords;
use App\Support\Access\AccessGate;
use App\Support\Text\ArabicFold;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request, CustomerRecords $records, AccessGate $access, CatalogDesk $catalog): Response
    {
        $actor = $this->actor($request, $access);
        $filters = [
            'q' => (string) $request->string('q'),
            'status' => (string) $request->string('status'),
            'area_id' => $request->integer('area_id') ?: null,
            'service_type' => (string) $request->string('service_type'),
        ];

        $query = $records->query($actor)->with(['phones', 'area.district.governorate', 'services', 'owner:id,name']);

        if ($filters['q'] !== '') {
            $raw = trim($filters['q']);
            $like = '%'.addcslashes($raw, '%_\\').'%';
            $digits = preg_replace('/\D+/', '', $raw) ?? '';
            $query->where(function ($inner) use ($raw, $like, $digits) {
                $inner->where('account_no', $raw)
                    ->orWhere('name_en', 'like', $like)
                    ->orWhere('name_ar', 'like', $like)
                    ->orWhere('email', 'like', $like);

                $folded = ArabicFold::variants($raw);
                $inner->orWhereRaw(ArabicFold::sql('name_ar').' like ?', ['%'.addcslashes($folded[array_key_last($folded)], '%_\\').'%']);

                if (strlen($digits) >= 6) {
                    $inner->orWhereIn('customers.id', function ($phones) use ($digits) {
                        $phones->select('customer_id')->from('customer_phones')->where('phone_e164', 'like', '%'.$digits);
                    });
                }
            });
        }

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if ($filters['area_id']) {
            $query->where('area_id', $filters['area_id']);
        }

        if ($filters['service_type'] !== '') {
            $query->whereHas('services', fn ($services) => $services->where('service_type', $filters['service_type']));
        }

        return Inertia::render('Customers/Index', [
            'customers' => $query->latest()->limit(100)->get()->map(fn (Customer $customer) => $records->present($customer, $actor))->values(),
            'filters' => $filters,
            'areas' => $this->areas(),
            'views' => CustomerView::query()->where('user_id', $actor->id)->orderBy('name')->get(['id', 'name', 'filters']),
            'can_create' => $access->allows($actor, 'customers.create'),
            'can_edit' => $access->allows($actor, 'customers.edit'),
            'service_types' => array_keys(config('fibes.service_types')),
            'plans' => $access->allows($actor, 'catalog.view') ? $catalog->choices(now()->toDateString()) : [],
        ]);
    }

    public function store(Request $request, CustomerRecords $records, AccessGate $access): RedirectResponse
    {
        $data = $this->validateCustomer($request, true);
        $customer = $records->create($data, $this->actor($request, $access), $request);

        return redirect()->route('customers.show', $customer)->with('status', __('ui.customers.saved'));
    }

    public function show(Request $request, Customer $customer, CustomerRecords $records, TicketRecords $tickets, IncidentRecords $incidents, AccessGate $access, CatalogDesk $catalog, Ledger $ledger): Response
    {
        $actor = $this->actor($request, $access);
        $this->authorizeCustomer($actor, $customer, $records);
        $seeMoney = $access->allows($actor, 'billing.view_amounts');
        $seeBalance = $seeMoney || $access->allows($actor, 'invoices.view_balance');

        return Inertia::render('Customers/Show', [
            'customer' => $records->present($customer, $actor, true),
            'notes' => $customer->customerNotes()->with('author:id,name')->latest()->get()->map(fn (CustomerNote $note) => [
                'id' => $note->id,
                'body' => $note->body,
                'author' => $note->author?->name,
                'at' => $note->created_at?->toIso8601String(),
            ]),
            'files' => $customer->files()->latest()->get()->map(fn (Attachment $file) => [
                'id' => $file->id,
                'name' => $file->original_name,
                'download_url' => URL::temporarySignedRoute('files.download', now()->addMinutes(10), ['attachment' => $file->id]),
            ]),
            'activity' => $records->timeline($customer),
            'tickets' => $tickets->forCustomer($customer, $actor),
            'incidents' => $incidents->bannersFor($customer),
            'areas' => $this->areas(),
            'can_edit' => $access->allows($actor, 'customers.edit'),
            'can_upload' => $access->allows($actor, 'attachments.upload'),
            'can_reveal' => $access->allows($actor, 'services.radius_password.reveal'),
            'can_see_money' => $access->allows($actor, 'billing.view_amounts'),
            'can_edit_radius' => $access->allows($actor, 'services.radius_fields.edit'),
            'service_types' => array_keys(config('fibes.service_types')),
            'tiles' => (string) config('fibes.coverage.tile_url'),
            'plans' => $access->allows($actor, 'catalog.view') ? $catalog->choices(now()->toDateString(), $customer->type, $customer->area_id) : [],
            'orders' => Order::query()->where('customer_id', $customer->id)->whereNull('archived_at')->latest('id')->limit(8)->get()->map(fn (Order $row) => [
                'id' => $row->id,
                'number' => $row->number,
                'type' => $row->type,
                'status' => $row->status,
                'sla_due' => $row->sla_due?->format('d/m/Y'),
            ])->values(),
            'billing_balance' => $seeBalance ? $ledger->balance($customer->id) : null,
            'billing_invoices' => $seeMoney ? Invoice::query()->where('customer_id', $customer->id)->whereNull('archived_at')->latest('id')->limit(20)->get()->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'type' => $invoice->type,
                'status' => $invoice->status,
                'total' => (string) $invoice->total,
                'lbp' => (string) $invoice->lbp_total,
            ])->values() : [],
        ]);
    }

    public function update(Request $request, Customer $customer, CustomerRecords $records, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $this->authorizeCustomer($actor, $customer, $records);
        $records->update($customer, $request->validate([
            'type' => ['sometimes', 'in:individual,business'],
            'status' => ['sometimes', 'in:lead,active,suspended,terminated'],
            'name_en' => ['sometimes', 'string', 'max:160'],
            'name_ar' => ['sometimes', 'string', 'max:160'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:160'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'phones' => ['sometimes', 'array'],
            'phones.*' => ['nullable', 'string', 'max:40'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'building' => ['nullable', 'string', 'max:80'],
            'floor' => ['nullable', 'string', 'max:40'],
            'landmark' => ['nullable', 'string', 'max:160'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'tags' => ['nullable', 'string', 'max:240'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]), $actor, $request);

        return back()->with('status', __('ui.customers.saved'));
    }

    public function storeService(Request $request, Customer $customer, CustomerRecords $records, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $this->authorizeCustomer($actor, $customer, $records);
        $records->addService($customer, $request->validate($this->serviceRules()), $actor, $request);

        return back()->with('status', __('ui.customers.service_saved'));
    }

    public function reveal(Request $request, Customer $customer, CustomerService $service, CustomerRecords $records, AccessGate $access): JsonResponse
    {
        $actor = $this->actor($request, $access);
        $this->authorizeCustomer($actor, $customer, $records);
        abort_unless($service->customer_id === $customer->id, 404);

        return response()->json([
            'password' => $records->revealPassword($service, $actor, $request),
        ]);
    }

    public function storeNote(Request $request, Customer $customer, CustomerRecords $records, AccessGate $access, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $this->authorizeCustomer($actor, $customer, $records);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $note = $customer->customerNotes()->create(['body' => $data['body'], 'author_id' => $actor->id]);
        $audit->record('customer.note.created', $actor, ['customer_id' => $customer->id, 'note_id' => $note->id], $request);

        return back()->with('status', __('ui.customers.note_saved'));
    }

    public function storeFile(Request $request, Customer $customer, CustomerRecords $records, AccessGate $access, FileDesk $files): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $this->authorizeCustomer($actor, $customer, $records);
        $max = (int) config('fibes.attachments.max_kilobytes');
        $upload = $request->validate([
            'file' => ['required', 'file', 'max:'.$max, 'mimes:pdf,jpg,jpeg,png,webp,txt,csv'],
        ])['file'];
        $files->store($upload, $actor, $request, $customer, 'customer.file.stored', ['customer_id' => $customer->id]);

        return back()->with('status', __('ui.files.saved'));
    }

    public function storeView(Request $request, AccessGate $access): RedirectResponse
    {
        $actor = $this->actor($request, $access);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'filters' => ['required', 'array'],
        ]);
        CustomerView::query()->create([
            'user_id' => $actor->id,
            'name' => $data['name'],
            'filters' => $data['filters'],
        ]);

        return back();
    }

    public function destroyView(Request $request, CustomerView $view, AccessGate $access): RedirectResponse
    {
        abort_unless($view->user_id === $this->actor($request, $access)->id, 403);
        $view->delete();

        return back();
    }

    private function actor(Request $request, AccessGate $access): User
    {
        $actor = $access->effective($request);
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function authorizeCustomer(User $actor, Customer $customer, CustomerRecords $records): void
    {
        abort_unless($records->query($actor)->whereKey($customer->id)->exists(), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCustomer(Request $request, bool $creating): array
    {
        return $request->validate([
            'type' => [$creating ? 'required' : 'sometimes', 'in:individual,business'],
            'status' => ['required', 'in:lead,active,suspended,terminated'],
            'name_en' => ['required', 'string', 'max:160'],
            'name_ar' => ['required', 'string', 'max:160'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:160'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'phones' => ['required', 'array', 'min:1'],
            'phones.*' => ['nullable', 'string', 'max:40'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'building' => ['nullable', 'string', 'max:80'],
            'floor' => ['nullable', 'string', 'max:40'],
            'landmark' => ['nullable', 'string', 'max:160'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'tags' => ['nullable', 'string', 'max:240'],
            'notes' => ['nullable', 'string', 'max:5000'],
            ...$this->serviceRules(false),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceRules(bool $required = true): array
    {
        $type = $required ? 'required' : 'nullable';

        return [
            'service_type' => [$type, 'in:ftth,fttb,ptmp,ptp,internal,dsl'],
            'plan_name' => ['nullable', 'string', 'max:120'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'speed' => ['nullable', 'string', 'max:40'],
            'service_area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'network_node_id' => ['nullable', 'integer', 'exists:network_nodes,id'],
            'install_date' => ['nullable', 'date'],
            'service_status' => ['nullable', 'in:pending,active,suspended,terminated'],
            'monthly_usd' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'radius_username' => ['nullable', 'string', 'max:80'],
            'radius_password' => ['nullable', 'string', 'max:120'],
            'static_ip' => ['nullable', 'string', 'max:45'],
            'mac' => ['nullable', 'string', 'max:40'],
            'nas' => ['nullable', 'string', 'max:80'],
            'vlan' => ['nullable', 'string', 'max:20'],
            'ont_serial' => ['nullable', 'string', 'max:80'],
            'router_model' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function areas(): array
    {
        return Area::query()->with('district.governorate')->orderBy('name_en')->get()->map(fn (Area $area) => [
            'id' => $area->id,
            'name_en' => $area->name_en,
            'name_ar' => $area->name_ar,
            'district_en' => $area->district?->name_en,
            'district_ar' => $area->district?->name_ar,
            'governorate_en' => $area->district?->governorate?->name_en,
            'governorate_ar' => $area->district?->governorate?->name_ar,
        ])->all();
    }
}
