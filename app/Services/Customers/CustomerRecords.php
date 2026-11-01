<?php

namespace App\Services\Customers;

use App\Models\Area;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\CustomerService;
use App\Models\ExchangeRate;
use App\Models\Plan;
use App\Models\StockSerial;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\PriceResolver;
use App\Support\Access\AccessGate;
use App\Support\Access\FieldPolicy;
use App\Support\Phone\PhoneNumbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CustomerRecords
{
    public function __construct(
        private AccessGate $access,
        private FieldPolicy $fields,
        private PhoneNumbers $phones,
        private AuditLogger $audit,
        private PriceResolver $prices,
    ) {}

    public function query(User $viewer): Builder
    {
        return $this->access->restrict(Customer::query()->whereNull('archived_at'), $viewer, 'customers.view', 'owner_id', 'team_id');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, User $actor, Request $request): Customer
    {
        $phones = $this->numbers($input['phones'] ?? []);
        if ($phones === []) {
            throw ValidationException::withMessages(['phones' => __('ui.customers.phone_invalid')]);
        }
        $whatsapp = $this->optional($input['whatsapp'] ?? null);
        $this->guardDuplicate([...$phones, ...($whatsapp ? [$whatsapp] : [])]);

        return DB::transaction(function () use ($input, $phones, $whatsapp, $actor, $request) {
            $customer = Customer::query()->create([
                'account_no' => $this->nextAccountNo(),
                'type' => $input['type'],
                'status' => $input['status'],
                'name_en' => $input['name_en'],
                'name_ar' => $input['name_ar'],
                'company_name' => $input['company_name'] ?? null,
                'email' => $input['email'] ?? null,
                'whatsapp_e164' => $whatsapp,
                'area_id' => $input['area_id'] ?? null,
                'building' => $input['building'] ?? null,
                'floor' => $input['floor'] ?? null,
                'landmark' => $input['landmark'] ?? null,
                'latitude' => $input['latitude'] ?? null,
                'longitude' => $input['longitude'] ?? null,
                'tags' => $this->tags($input['tags'] ?? ''),
                'notes' => $input['notes'] ?? null,
                'owner_id' => $actor->id,
                'team_id' => $actor->teams()->value('id'),
            ]);

            foreach ($phones as $index => $e164) {
                $customer->phones()->create(['phone_e164' => $e164, 'is_primary' => $index === 0]);
            }

            if (! empty($input['service_type'])) {
                $this->writeService($customer, $input, $actor, $request, null);
            }

            $this->audit->record('customer.created', $actor, [
                'customer_id' => $customer->id,
                'account_no' => $customer->account_no,
            ], $request);
            Log::info('customer.created', [
                'customer_id' => $customer->id,
                'request_id' => $request->attributes->get('request_id'),
            ]);

            return $customer;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Customer $customer, array $input, User $actor, Request $request): void
    {
        $phones = array_key_exists('phones', $input) ? $this->numbers($input['phones']) : null;
        $whatsapp = array_key_exists('whatsapp', $input) ? $this->optional($input['whatsapp']) : $customer->whatsapp_e164;
        $check = array_filter([
            ...($phones ?? []),
            ...(is_string($whatsapp) && $whatsapp !== '' ? [$whatsapp] : []),
        ]);
        if ($check !== []) {
            $this->guardDuplicate($check, $customer->id);
        }

        $before = $customer->only(['name_en', 'name_ar', 'status', 'email', 'area_id']);
        $customer->fill(array_filter([
            'type' => $input['type'] ?? null,
            'status' => $input['status'] ?? null,
            'name_en' => $input['name_en'] ?? null,
            'name_ar' => $input['name_ar'] ?? null,
            'company_name' => $input['company_name'] ?? null,
            'email' => $input['email'] ?? null,
            'area_id' => $input['area_id'] ?? null,
            'building' => $input['building'] ?? null,
            'floor' => $input['floor'] ?? null,
            'landmark' => $input['landmark'] ?? null,
            'latitude' => $input['latitude'] ?? null,
            'longitude' => $input['longitude'] ?? null,
            'notes' => $input['notes'] ?? null,
        ], fn ($value) => $value !== null));

        if (array_key_exists('whatsapp', $input)) {
            $customer->whatsapp_e164 = $whatsapp;
        }
        if (array_key_exists('tags', $input)) {
            $customer->tags = $this->tags($input['tags']);
        }
        $customer->save();

        if ($phones !== null) {
            $customer->phones()->delete();
            foreach ($phones as $index => $e164) {
                $customer->phones()->create(['phone_e164' => $e164, 'is_primary' => $index === 0]);
            }
        }

        $this->audit->record('customer.updated', $actor, [
            'customer_id' => $customer->id,
            'before' => $before,
            'after' => $customer->only(['name_en', 'name_ar', 'status', 'email', 'area_id']),
        ], $request);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function addService(Customer $customer, array $input, User $actor, Request $request): CustomerService
    {
        return DB::transaction(function () use ($customer, $input, $actor, $request) {
            $service = $this->writeService($customer, $input, $actor, $request, null);
            $this->audit->record('customer.service.created', $actor, [
                'customer_id' => $customer->id,
                'service_id' => $service->id,
                'service_type' => $service->service_type,
            ], $request);

            return $service;
        });
    }

    public function revealPassword(CustomerService $service, User $actor, Request $request): string
    {
        $this->audit->record('customer.radius_password.revealed', $actor, [
            'customer_id' => $service->customer_id,
            'service_id' => $service->id,
            'radius_username' => $service->radius_username,
        ], $request);

        return (string) $service->radius_password;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Customer $customer, User $viewer, bool $detail = false): array
    {
        $customer->loadMissing(['phones', 'area.district.governorate', 'services.area', 'owner:id,name']);
        $seePii = $this->fields->canSeePii($viewer);
        $seeMoney = $this->access->allows($viewer, 'billing.view_amounts');
        $seeRadius = $this->access->allows($viewer, 'services.radius_fields.view')
            || $this->access->allows($viewer, 'services.radius_fields.edit');
        $seeTechnical = $seeRadius
            || $this->access->allows($viewer, 'services.technical.view')
            || $this->access->allows($viewer, 'services.technical.edit');

        $phones = $customer->phones->map(fn (CustomerPhone $phone) => $seePii
            ? ($viewer->hasRole('auditor') ? $this->fields->maskPhone($phone->phone_e164) : $this->phones->display($phone->phone_e164))
            : null)->filter()->values()->all();

        $row = [
            'id' => $customer->id,
            'account_no' => $customer->account_no,
            'type' => $customer->type,
            'status' => $customer->status,
            'name_en' => $customer->name_en,
            'name_ar' => $customer->name_ar,
            'company_name' => $customer->company_name,
            'phones' => $phones,
            'area' => $customer->area ? $this->areaLabel($customer->area) : null,
            'area_id' => $seePii ? $customer->area_id : null,
            'tags' => $customer->tags ?? [],
            'services' => $customer->services->map(fn (CustomerService $service) => $this->presentService($service, $seeMoney, $seeRadius, $seeTechnical))->values()->all(),
        ];

        if ($seePii) {
            $row['email'] = $viewer->hasRole('auditor') && $customer->email
                ? $this->maskEmail($customer->email)
                : $customer->email;
            $row['whatsapp'] = $customer->whatsapp_e164
                ? ($viewer->hasRole('auditor') ? $this->fields->maskPhone($customer->whatsapp_e164) : $this->phones->display($customer->whatsapp_e164))
                : null;
        }

        if (! $detail) {
            return $row;
        }

        $row['building'] = $seePii ? $customer->building : null;
        $row['floor'] = $seePii ? $customer->floor : null;
        $row['landmark'] = $seePii ? $customer->landmark : null;
        $row['latitude'] = $seePii ? $customer->latitude : null;
        $row['longitude'] = $seePii ? $customer->longitude : null;
        $row['notes'] = $customer->notes;
        $row['owner'] = $customer->owner?->name;

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function timeline(Customer $customer): array
    {
        $direct = AuditLog::query()->where('customer_id', $customer->id)->latest('occurred_at')->limit(40)->get();
        $legacy = $direct->count() >= 40
            ? collect()
            : AuditLog::query()->whereNull('customer_id')->where('metadata->customer_id', $customer->id)->latest('occurred_at')->limit(40 - $direct->count())->get();

        return $direct->concat($legacy)
            ->map(fn (AuditLog $row) => [
                'id' => $row->id,
                'event' => $row->event,
                'at' => $row->occurred_at?->toIso8601String(),
                'metadata' => collect($row->metadata ?? [])->except(['radius_password'])->all(),
            ])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $raw
     * @return list<string>
     */
    private function numbers(array $raw): array
    {
        $out = [];

        foreach ($raw as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            try {
                $out[] = $this->phones->normalize($value);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'phones' => __('ui.customers.phone_invalid'),
                ]);
            }
        }

        return array_values(array_unique($out));
    }

    private function optional(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return $this->phones->normalize($value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'whatsapp' => __('ui.customers.phone_invalid'),
            ]);
        }
    }

    /**
     * @param  list<string>  $numbers
     */
    private function guardDuplicate(array $numbers, ?int $ignoreId = null): void
    {
        if ($numbers === []) {
            return;
        }

        $phone = CustomerPhone::query()->whereIn('phone_e164', $numbers)
            ->when($ignoreId, fn (Builder $query) => $query->where('customer_id', '!=', $ignoreId))
            ->first();
        $owner = $phone?->customer ?? Customer::query()->whereIn('whatsapp_e164', $numbers)
            ->when($ignoreId, fn (Builder $query) => $query->where('id', '!=', $ignoreId))
            ->first();

        if ($owner) {
            throw ValidationException::withMessages([
                'phones' => __('ui.customers.duplicate', ['account' => $owner->account_no, 'name' => $owner->name_en]),
            ]);
        }
    }

    private function nextAccountNo(): string
    {
        $last = Customer::query()->lockForUpdate()->orderByDesc('id')->value('account_no');
        $next = 1;

        if (is_string($last) && preg_match('/(\d+)$/', $last, $match)) {
            $next = ((int) $match[1]) + 1;
        }

        return 'FB-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return list<string>
     */
    private function tags(mixed $raw): array
    {
        $split = preg_split('/[,،]+/', (string) $raw);
        $parts = is_array($raw) ? $raw : ($split === false ? [] : $split);

        return array_values(array_filter(array_map(fn ($tag) => trim((string) $tag), $parts)));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function writeService(Customer $customer, array $input, User $actor, Request $request, ?CustomerService $service): CustomerService
    {
        $seeRadius = $this->access->allows($actor, 'services.radius_fields.edit');
        $seeTechnical = $seeRadius || $this->access->allows($actor, 'services.technical.edit');
        $seeMoney = $this->access->allows($actor, 'billing.view_amounts') || $this->access->allows($actor, 'customers.edit');

        $payload = [
            'service_type' => $input['service_type'],
            'plan_name' => $input['plan_name'] ?? null,
            'speed' => $input['speed'] ?? null,
            'area_id' => $input['service_area_id'] ?? $input['area_id'] ?? null,
            'network_node_id' => $input['network_node_id'] ?? null,
            'install_date' => $input['install_date'] ?? null,
            'status' => $input['service_status'] ?? 'pending',
        ];

        if ($seeMoney && array_key_exists('monthly_usd', $input)) {
            $payload['monthly_usd'] = $input['monthly_usd'] === '' || $input['monthly_usd'] === null ? 0 : $input['monthly_usd'];
        }

        if (filled($input['plan_id'] ?? null)) {
            $plan = Plan::query()->with('product')->find($input['plan_id']);

            if ($plan instanceof Plan && $plan->is_public && $plan->product?->is_active) {
                $payload['plan_id'] = $plan->id;
                $payload['plan_name'] = $plan->product->name_en;

                if ($plan->down_mbps) {
                    $payload['speed'] = $plan->down_mbps.' / '.($plan->up_mbps ?? $plan->down_mbps).' Mb';
                }

                if ($plan->product->service_type) {
                    $payload['service_type'] = $plan->product->service_type;
                }

                $quote = $this->prices->resolve($plan->product_id, now()->toDateString(), $customer->type, $customer->area_id);

                if ($quote !== null) {
                    $payload['monthly_usd'] = $quote['price'];
                }
            }
        }

        if ($seeRadius) {
            foreach (['radius_username', 'static_ip', 'mac', 'nas', 'vlan'] as $field) {
                if (array_key_exists($field, $input)) {
                    $payload[$field] = $input[$field] ?: null;
                }
            }
            if (! empty($input['radius_password'])) {
                $payload['radius_password'] = $input['radius_password'];
            }
        }

        if ($seeTechnical) {
            foreach (['ont_serial', 'router_model'] as $field) {
                if (array_key_exists($field, $input)) {
                    $payload[$field] = $input[$field] ?: null;
                }
            }
        }

        if ($service) {
            $service->fill($payload)->save();

            return $service;
        }

        return $customer->services()->create($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentService(CustomerService $service, bool $seeMoney, bool $seeRadius, bool $seeTechnical): array
    {
        $row = [
            'id' => $service->id,
            'service_type' => $service->service_type,
            'plan_name' => $service->plan_name,
            'speed' => $service->speed,
            'status' => $service->status,
            'install_date' => $service->install_date?->toDateString(),
            'area' => $service->area?->name_en,
            'has_radius_password' => $service->radius_password !== null && $service->radius_password !== '',
            'installed' => StockSerial::query()
                ->where('customer_service_id', $service->id)
                ->where('status', 'installed')
                ->get(['serial', 'mac'])
                ->map(fn (StockSerial $serial) => ['serial' => $serial->serial, 'mac' => $serial->mac])
                ->all(),
        ];

        if ($seeMoney) {
            $row['monthly_usd'] = (string) $service->monthly_usd;
            $row['monthly_lbp'] = $this->lbp((string) $service->monthly_usd);
        }

        if ($seeRadius) {
            $row['radius_username'] = $service->radius_username;
            $row['static_ip'] = $service->static_ip;
            $row['mac'] = $service->mac;
            $row['nas'] = $service->nas;
            $row['vlan'] = $service->vlan;
        }

        if ($seeTechnical) {
            $row['ont_serial'] = $service->ont_serial;
            $row['router_model'] = $service->router_model;
        }

        return $row;
    }

    private function lbp(string $usd): ?int
    {
        $rate = ExchangeRate::query()->orderByDesc('effective_on')->orderByDesc('id')->first();

        if (! $rate) {
            return null;
        }

        return (int) (round(((float) $usd * (float) $rate->usd_lbp) / 1000) * 1000);
    }

    /**
     * @return array{name_en: string, name_ar: string, district_en: string, district_ar: string, governorate_en: string, governorate_ar: string}
     */
    private function areaLabel(Area $area): array
    {
        $area->loadMissing('district.governorate');

        return [
            'name_en' => $area->name_en,
            'name_ar' => $area->name_ar,
            'district_en' => $area->district?->name_en ?? '',
            'district_ar' => $area->district?->name_ar ?? '',
            'governorate_en' => $area->district?->governorate?->name_en ?? '',
            'governorate_ar' => $area->district?->governorate?->name_ar ?? '',
        ];
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);

        return count($parts) === 2 ? substr($parts[0], 0, 1).'•••@'.$parts[1] : '•••';
    }
}
