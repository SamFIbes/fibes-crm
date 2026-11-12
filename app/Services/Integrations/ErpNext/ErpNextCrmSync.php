<?php

namespace App\Services\Integrations\ErpNext;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\ErpLink;
use App\Models\ErpSyncRun;
use App\Models\Setting;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class ErpNextCrmSync
{
    private const SOURCES = [
        'customers' => ['file' => 'customers.json', 'doctype' => 'Customer', 'mapper' => 'mapCustomer'],
        'leads' => ['file' => 'leads.json', 'doctype' => 'Lead', 'mapper' => 'mapLead'],
        'quotations' => ['file' => 'quotations.json', 'doctype' => 'Quotation', 'mapper' => 'mapQuotation'],
        'sales_invoices' => ['file' => 'sales_invoices.json', 'doctype' => 'Sales Invoice', 'mapper' => 'mapSalesInvoice'],
    ];

    private const CUSTOMER_FIELDS = [
        'name',
        'customer_name',
        'customer_type',
        'mobile_no',
        'email_id',
        'disabled',
        'modified',
        'tax_id',
        'customer_group',
        'territory',
    ];

    private const CONTACT_FIELDS = ['name', 'full_name', 'first_name', 'mobile_no', 'phone', 'email_id', 'modified'];

    private const ADDRESS_FIELDS = ['name', 'address_line1', 'address_line2', 'city', 'modified'];

    public function __construct(
        private readonly ?string $fixturesDirectory = null,
        private readonly ?string $sinceOverride = null,
        private readonly ?ErpNextClient $client = null,
    ) {
    }

    public function loadFixtures(): array
    {
        $directory = $this->fixturesDirectory ?? base_path('tests/fixtures/erpnext');
        $fixtures = [];

        foreach (self::SOURCES as $type => $source) {
            $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$source['file'];

            if (! File::exists($path)) {
                throw new RuntimeException("ERPNext fixture not found: {$path}");
            }

            $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded) || ! array_is_list($decoded)) {
                throw new RuntimeException("ERPNext fixture must contain a JSON list: {$path}");
            }

            $fixtures[$type] = $decoded;
        }

        return $fixtures;
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return 'would_create'|'would_update'|'unchanged'|'ambiguous'
     */
    public function classify(string $doctype, string $erpName, array $mapped): string
    {
        if ($doctype !== 'Customer') {
            $link = ErpLink::query()
                ->where('doctype', $doctype)
                ->where('erp_name', $erpName)
                ->first();

            if ($link === null) {
                return 'would_create';
            }

            return $link->content_hash !== ErpNextCrmMapper::contentHash($mapped)
                ? 'would_update'
                : 'unchanged';
        }

        $match = $this->resolveCustomerMatch($erpName, $mapped);

        if ($match['status'] === 'ambiguous') {
            return 'ambiguous';
        }

        $link = ErpLink::query()
            ->where('doctype', 'Customer')
            ->where('erp_name', $erpName)
            ->first();

        $hash = ErpNextCrmMapper::customerContentHash($mapped);

        if ($match['customer'] === null && $link === null) {
            return 'would_create';
        }

        if ($link !== null && $link->content_hash === $hash && $match['customer'] !== null) {
            return 'unchanged';
        }

        if ($link !== null && $link->content_hash === $hash && $match['customer'] === null) {
            return 'would_update';
        }

        return 'would_update';
    }

    public function dryRun(): array
    {
        $result = [];
        $fixtures = $this->loadFixtures();

        foreach (self::SOURCES as $type => $source) {
            $summary = [
                'would_create' => 0,
                'would_update' => 0,
                'unchanged' => 0,
                'samples' => [],
            ];

            foreach ($fixtures[$type] as $doc) {
                if (! is_array($doc)) {
                    throw new RuntimeException("Each {$type} fixture item must be an object.");
                }

                $mapped = ErpNextCrmMapper::{$source['mapper']}($doc);
                $erpName = $mapped['erp_name'] ?? $mapped['external_id'] ?? $mapped['erp_ref'] ?? $mapped['number'] ?? null;

                if ($erpName === null) {
                    throw new RuntimeException("A {$type} fixture item is missing its ERP name.");
                }

                $link = ErpLink::query()
                    ->where('doctype', $source['doctype'])
                    ->where('erp_name', $erpName)
                    ->first();

                if ($link === null) {
                    $summary['would_create']++;
                } elseif ($link->content_hash !== ErpNextCrmMapper::contentHash($mapped)) {
                    $summary['would_update']++;
                } else {
                    $summary['unchanged']++;
                }

                if (count($summary['samples']) < 2) {
                    $summary['samples'][] = $mapped;
                }
            }

            $result[$type] = $summary;
        }

        $result['wrote'] = false;

        return $result;
    }

    /**
     * @return array{customers: array{would_create: int, would_update: int, unchanged: int, ambiguous: int, errors: int, contacts: int, addresses: int, hub_contact_count: ?int, hub_address_count: ?int}, wrote: bool, scanned: int, cursor: ?string}
     */
    public function liveDryRun(): array
    {
        return $this->livePull(apply: false);
    }

    /**
     * @return array{customers: array{would_create: int, would_update: int, unchanged: int, ambiguous: int, errors: int, contacts: int, addresses: int, hub_contact_count: ?int, hub_address_count: ?int}, wrote: bool, scanned: int, cursor: ?string}
     */
    public function liveApply(): array
    {
        if (! (bool) config('fibes.erpnext.crm_apply', false)) {
            throw new RuntimeException('Apply refused: set FIBES_ERPNEXT_CRM_APPLY=true to enable writes.');
        }

        return $this->livePull(apply: true);
    }

    /**
     * @return array{ok: bool, customer_count: int, page_rows: int}
     */
    public function liveSmoke(): array
    {
        $client = $this->client();
        $client->ping();
        $count = $client->getCount('Customer');
        $page = $client->listModified('Customer', ['modified'], null, 1, 0);

        return [
            'ok' => true,
            'customer_count' => $count,
            'page_rows' => count($page),
        ];
    }

    /**
     * @return array{customers: array{would_create: int, would_update: int, unchanged: int, ambiguous: int, errors: int, contacts: int, addresses: int, hub_contact_count: ?int, hub_address_count: ?int}, wrote: bool, scanned: int, cursor: ?string}
     */
    private function livePull(bool $apply): array
    {
        $client = $this->client();
        $mode = $apply ? 'apply' : 'dry_run';

        $run = ErpSyncRun::query()->create([
            'mode' => $mode,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $summary = [
            'customers' => [
                'would_create' => 0,
                'would_update' => 0,
                'unchanged' => 0,
                'ambiguous' => 0,
                'errors' => 0,
                'contacts' => 0,
                'addresses' => 0,
                'hub_contact_count' => null,
                'hub_address_count' => null,
            ],
            'wrote' => false,
            'scanned' => 0,
            'cursor' => null,
        ];

        try {
            $hubContactCount = $client->getCount('Contact');
            $hubAddressCount = $client->getCount('Address');
            $summary['customers']['hub_contact_count'] = $hubContactCount;
            $summary['customers']['hub_address_count'] = $hubAddressCount;
        } catch (Throwable) {
            $hubContactCount = null;
            $hubAddressCount = null;
        }

        $cursor = $this->sinceOverride !== null && $this->sinceOverride !== ''
            ? $this->sinceOverride
            : $this->loadCursor();
        $maxModified = $cursor;
        $start = 0;

        try {
            do {
                $rows = $client->listModified('Customer', self::CUSTOMER_FIELDS, $cursor, 100, $start);

                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $summary['scanned']++;

                    try {
                        $erpName = (string) ($row['name'] ?? '');

                        if ($erpName === '') {
                            $summary['customers']['errors']++;

                            continue;
                        }

                        $contacts = [];
                        $addresses = [];

                        if ($apply) {
                            $contacts = $client->listLinkedToCustomer('Contact', $erpName, self::CONTACT_FIELDS);
                            $addresses = $client->listLinkedToCustomer('Address', $erpName, self::ADDRESS_FIELDS);
                            $summary['customers']['contacts'] += count($contacts);
                            $summary['customers']['addresses'] += count($addresses);
                        }

                        $mapped = $apply
                            ? ErpNextCrmMapper::mapCustomerBundle($row, $contacts, $addresses)
                            : ErpNextCrmMapper::mapCustomer($row);
                        $classification = $this->classify('Customer', $erpName, $mapped);

                        if ($classification === 'ambiguous') {
                            $summary['customers']['ambiguous']++;
                        } elseif ($classification === 'would_create') {
                            $summary['customers']['would_create']++;
                        } elseif ($classification === 'would_update') {
                            $summary['customers']['would_update']++;
                        } else {
                            $summary['customers']['unchanged']++;
                        }

                        if ($apply && in_array($classification, ['would_create', 'would_update'], true)) {
                            $this->applyCustomer($mapped, $row, $contacts, $addresses);
                            $summary['wrote'] = true;
                        }

                        $modified = isset($row['modified']) ? (string) $row['modified'] : null;

                        if ($modified !== null && ($maxModified === null || $modified > $maxModified)) {
                            $maxModified = $modified;
                        }
                    } catch (Throwable) {
                        $summary['customers']['errors']++;
                    }
                }

                $start += count($rows);
            } while (count($rows) === 100);

            if ($apply && $maxModified !== null && $summary['customers']['errors'] === 0) {
                $this->saveCursor($maxModified);
            }

            $summary['cursor'] = $maxModified;

            $run->update([
                'status' => 'succeeded',
                'scanned' => $summary['scanned'],
                'would_create' => $summary['customers']['would_create'],
                'would_update' => $summary['customers']['would_update'],
                'unchanged' => $summary['customers']['unchanged'],
                'ambiguous' => $summary['customers']['ambiguous'],
                'errors' => $summary['customers']['errors'],
                'contacts' => $summary['customers']['contacts'],
                'addresses' => $summary['customers']['addresses'],
                'hub_contact_count' => $summary['customers']['hub_contact_count'],
                'hub_address_count' => $summary['customers']['hub_address_count'],
                'cursor' => $maxModified,
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $summary['cursor'] = $maxModified;

            $run->update([
                'status' => 'failed',
                'scanned' => $summary['scanned'],
                'would_create' => $summary['customers']['would_create'],
                'would_update' => $summary['customers']['would_update'],
                'unchanged' => $summary['customers']['unchanged'],
                'ambiguous' => $summary['customers']['ambiguous'],
                'errors' => $summary['customers']['errors'],
                'contacts' => $summary['customers']['contacts'],
                'addresses' => $summary['customers']['addresses'],
                'hub_contact_count' => $summary['customers']['hub_contact_count'],
                'hub_address_count' => $summary['customers']['hub_address_count'],
                'cursor' => $maxModified,
                'message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $addresses
     */
    private function applyCustomer(array $mapped, array $row, array $contacts, array $addresses): void
    {
        $erpName = (string) $mapped['erp_name'];
        $match = $this->resolveCustomerMatch($erpName, $mapped);

        if ($match['status'] === 'ambiguous') {
            return;
        }

        $customer = $match['customer'] ?? new Customer;
        $customer->fill([
            'account_no' => $customer->exists ? $customer->account_no : $erpName,
            'erp_name' => $erpName,
            'name_en' => $mapped['name_en'] ?? $erpName,
            'name_ar' => $mapped['name_ar'] ?? $erpName,
            'type' => $mapped['type'] ?? 'residential',
            'status' => $mapped['status'] ?? 'active',
            'company_name' => $mapped['company_name'] ?? null,
        ]);

        $email = is_string($mapped['email'] ?? null) ? trim((string) $mapped['email']) : '';
        $whatsapp = is_string($mapped['whatsapp_e164'] ?? null) ? trim((string) $mapped['whatsapp_e164']) : '';

        if ($email !== '') {
            $customer->email = $email;
        }

        if ($whatsapp !== '') {
            $customer->whatsapp_e164 = $whatsapp;
        }

        if ($customer->building === null && ! empty($mapped['building'])) {
            $customer->building = $mapped['building'];
        }

        if ($customer->floor === null && ! empty($mapped['floor'])) {
            $customer->floor = $mapped['floor'];
        }

        if ($customer->landmark === null && ! empty($mapped['landmark'])) {
            $customer->landmark = $mapped['landmark'];
        }

        if ($customer->area_id === null) {
            $territory = is_string($mapped['territory'] ?? null) ? trim((string) $mapped['territory']) : '';

            if ($territory !== '') {
                $area = Area::query()
                    ->where(function ($query) use ($territory): void {
                        $query->whereRaw('LOWER(name_en) = ?', [strtolower($territory)])
                            ->orWhereRaw('LOWER(name_ar) = ?', [strtolower($territory)]);
                    })
                    ->get(['id']);

                if ($area->count() === 1) {
                    $customer->area_id = $area->first()->id;
                }
            }
        }

        if (($customer->email === null || $customer->email === '') && isset($mapped['contacts'])) {
            foreach ($mapped['contacts'] as $mc) {
                $contactEmail = is_string($mc['email'] ?? null) ? trim((string) $mc['email']) : '';

                if ($contactEmail !== '') {
                    $customer->email = $contactEmail;
                    break;
                }
            }
        }

        $customer->save();

        $phone = $mapped['whatsapp_e164'] ?? null;

        if (is_string($phone) && $phone !== '') {
            $existingPhone = CustomerPhone::query()->where('phone_e164', $phone)->first();

            if ($existingPhone === null || (int) $existingPhone->customer_id === (int) $customer->id) {
                CustomerPhone::query()->updateOrCreate(
                    ['customer_id' => $customer->id, 'phone_e164' => $phone],
                    ['is_primary' => true],
                );
            }
        }

        foreach ($mapped['contacts'] ?? [] as $mc) {
            $contactPhone = is_string($mc['phone_e164'] ?? null) ? trim((string) $mc['phone_e164']) : '';

            if ($contactPhone === '') {
                continue;
            }

            $existingContactPhone = CustomerPhone::query()->where('phone_e164', $contactPhone)->first();

            if ($existingContactPhone === null || (int) $existingContactPhone->customer_id === (int) $customer->id) {
                CustomerPhone::query()->updateOrCreate(
                    ['customer_id' => $customer->id, 'phone_e164' => $contactPhone],
                    ['is_primary' => false],
                );
            }
        }

        $meta = array_filter([
            'customer_group' => $mapped['customer_group'] ?? null,
            'territory' => $mapped['territory'] ?? null,
        ], static fn ($v) => $v !== null);

        ErpLink::query()->updateOrCreate(
            ['doctype' => 'Customer', 'erp_name' => $erpName],
            [
                'fibes_type' => 'customer',
                'fibes_id' => $customer->id,
                'erp_modified' => $row['modified'] ?? null,
                'content_hash' => ErpNextCrmMapper::customerContentHash($mapped),
                'last_synced_at' => now(),
                'last_error' => null,
                'meta' => $meta !== [] ? $meta : null,
            ],
        );

        foreach ($contacts as $contact) {
            $mappedContact = ErpNextCrmMapper::mapContact($contact);
            $contactName = $mappedContact['erp_name'] ?? null;

            if (! is_string($contactName) || $contactName === '') {
                continue;
            }

            ErpLink::query()->updateOrCreate(
                ['doctype' => 'Contact', 'erp_name' => $contactName],
                [
                    'fibes_type' => 'customer',
                    'fibes_id' => $customer->id,
                    'erp_modified' => $mappedContact['modified'] ?? null,
                    'content_hash' => ErpNextCrmMapper::contentHash($mappedContact),
                    'last_synced_at' => now(),
                    'last_error' => null,
                ],
            );
        }

        foreach ($addresses as $address) {
            $mappedAddress = ErpNextCrmMapper::mapAddress($address);
            $addressName = $mappedAddress['erp_name'] ?? null;

            if (! is_string($addressName) || $addressName === '') {
                continue;
            }

            ErpLink::query()->updateOrCreate(
                ['doctype' => 'Address', 'erp_name' => $addressName],
                [
                    'fibes_type' => 'customer',
                    'fibes_id' => $customer->id,
                    'erp_modified' => $mappedAddress['modified'] ?? null,
                    'content_hash' => ErpNextCrmMapper::contentHash($mappedAddress),
                    'last_synced_at' => now(),
                    'last_error' => null,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return array{status: 'matched'|'none'|'ambiguous', customer: ?Customer}
     */
    private function resolveCustomerMatch(string $erpName, array $mapped): array
    {
        $link = ErpLink::query()
            ->where('doctype', 'Customer')
            ->where('erp_name', $erpName)
            ->first();

        if ($link !== null && $link->fibes_id !== null) {
            $byLink = Customer::query()->find($link->fibes_id);

            if ($byLink instanceof Customer) {
                return ['status' => 'matched', 'customer' => $byLink];
            }
        }

        $byErpName = Customer::query()->where('erp_name', $erpName)->get();

        if ($byErpName->count() > 1) {
            return ['status' => 'ambiguous', 'customer' => null];
        }

        if ($byErpName->count() === 1) {
            return ['status' => 'matched', 'customer' => $byErpName->first()];
        }

        $candidates = collect();

        $email = is_string($mapped['email'] ?? null) ? trim((string) $mapped['email']) : '';

        if ($email !== '') {
            $candidates = $candidates->merge(Customer::query()->where('email', $email)->get());
        }

        $phone = is_string($mapped['whatsapp_e164'] ?? null) ? trim((string) $mapped['whatsapp_e164']) : '';

        if ($phone !== '') {
            $phoneCustomerIds = CustomerPhone::query()->where('phone_e164', $phone)->pluck('customer_id');
            $candidates = $candidates->merge(Customer::query()->whereIn('id', $phoneCustomerIds)->get());
            $candidates = $candidates->merge(Customer::query()->where('whatsapp_e164', $phone)->get());
        }

        $unique = $candidates->unique('id')->values();

        if ($unique->count() > 1) {
            return ['status' => 'ambiguous', 'customer' => null];
        }

        if ($unique->count() === 1) {
            return ['status' => 'matched', 'customer' => $unique->first()];
        }

        return ['status' => 'none', 'customer' => null];
    }

    private function loadCursor(): ?string
    {
        $setting = Setting::query()->where('key', 'erpnext_crm_cursors')->first();
        $value = is_array($setting?->value) ? $setting->value : [];
        $cursor = $value['Customer'] ?? null;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    private function saveCursor(string $cursor): void
    {
        $setting = Setting::query()->where('key', 'erpnext_crm_cursors')->first();
        $value = is_array($setting?->value) ? $setting->value : [];
        $value['Customer'] = $cursor;

        Setting::query()->updateOrCreate(
            ['key' => 'erpnext_crm_cursors'],
            ['value' => $value],
        );
    }

    private function client(): ErpNextClient
    {
        return $this->client ?? app(ErpNextClient::class);
    }
}
