<?php

namespace App\Services\Integrations\ErpNext;

use App\Models\Customer;
use App\Models\ErpLink;
use App\Models\ErpPaymentEntry;
use App\Models\ErpSalesInvoice;
use App\Models\Setting;
use RuntimeException;
use Throwable;

final class ErpNextMoneySync
{
    private const SALES_INVOICE_FIELDS = [
        'name', 'customer', 'customer_name', 'posting_date', 'due_date',
        'grand_total', 'outstanding_amount', 'status', 'docstatus', 'currency',
        'modified', 'is_return', 'company',
    ];

    private const PAYMENT_ENTRY_FIELDS = [
        'name', 'party', 'party_type', 'paid_amount', 'received_amount',
        'posting_date', 'mode_of_payment', 'status', 'docstatus', 'modified', 'company',
    ];

    public function __construct(
        private readonly ?string $sinceOverride = null,
        private readonly ?ErpNextClient $client = null,
    ) {
    }

    public function liveDryRun(): array
    {
        return $this->livePull(apply: false);
    }

    public function liveApply(): array
    {
        if (! (bool) config('fibes.erpnext.crm_apply', false)) {
            throw new RuntimeException('Apply refused: set FIBES_ERPNEXT_CRM_APPLY=true to enable writes.');
        }

        return $this->livePull(apply: true);
    }

    /**
     * @return array{ok: bool, invoice_count: int, payment_count: int, invoice_page_rows: int, payment_page_rows: int}
     */
    public function liveSmoke(): array
    {
        $client = $this->client();
        $client->ping();

        return [
            'ok' => true,
            'invoice_count' => $client->getCount('Sales Invoice'),
            'payment_count' => $client->getCount('Payment Entry'),
            'invoice_page_rows' => count($client->listModified('Sales Invoice', ['modified'], null, 1, 0)),
            'payment_page_rows' => count($client->listModified('Payment Entry', ['modified'], null, 1, 0)),
        ];
    }

    /**
     * @return array{
     *   sales_invoices: array{would_create: int, would_update: int, unchanged: int, unlinked: int, errors: int, scanned: int, grand_total_sum: float},
     *   payment_entries: array{would_create: int, would_update: int, unchanged: int, unlinked: int, errors: int, scanned: int, paid_amount_sum: float},
     *   wrote: bool
     * }
     */
    private function livePull(bool $apply): array
    {
        $result = [
            'sales_invoices' => [
                'would_create' => 0,
                'would_update' => 0,
                'unchanged' => 0,
                'unlinked' => 0,
                'errors' => 0,
                'scanned' => 0,
                'grand_total_sum' => 0.0,
            ],
            'payment_entries' => [
                'would_create' => 0,
                'would_update' => 0,
                'unchanged' => 0,
                'unlinked' => 0,
                'errors' => 0,
                'scanned' => 0,
                'paid_amount_sum' => 0.0,
            ],
            'wrote' => false,
        ];

        $si = $this->pullDoctype(
            doctype: 'Sales Invoice',
            fields: self::SALES_INVOICE_FIELDS,
            mapper: 'mapSalesInvoice',
            apply: $apply,
            fibesType: 'erp_sales_invoice',
            sumField: 'grand_total',
        );
        $result['sales_invoices'] = $si['counts'];
        $result['wrote'] = $result['wrote'] || $si['wrote'];

        $pe = $this->pullDoctype(
            doctype: 'Payment Entry',
            fields: self::PAYMENT_ENTRY_FIELDS,
            mapper: 'mapPaymentEntry',
            apply: $apply,
            fibesType: 'erp_payment_entry',
            sumField: 'paid_amount',
        );
        $result['payment_entries'] = $pe['counts'];
        $result['wrote'] = $result['wrote'] || $pe['wrote'];

        return $result;
    }

    /**
     * @param  list<string>  $fields
     * @return array{counts: array{would_create: int, would_update: int, unchanged: int, unlinked: int, errors: int, scanned: int, grand_total_sum?: float, paid_amount_sum?: float}, wrote: bool}
     */
    private function pullDoctype(string $doctype, array $fields, string $mapper, bool $apply, string $fibesType, string $sumField): array
    {
        $client = $this->client();
        $sumKey = $sumField === 'grand_total' ? 'grand_total_sum' : 'paid_amount_sum';
        $counts = [
            'would_create' => 0,
            'would_update' => 0,
            'unchanged' => 0,
            'unlinked' => 0,
            'errors' => 0,
            'scanned' => 0,
            $sumKey => 0.0,
        ];
        $wrote = false;
        $cursor = $this->sinceOverride !== null && $this->sinceOverride !== ''
            ? $this->sinceOverride
            : $this->loadCursor($doctype);
        $maxModified = $cursor;
        $start = 0;

        do {
            $rows = $client->listModified($doctype, $fields, $cursor, 100, $start);

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $counts['scanned']++;

                try {
                    /** @var array<string, mixed> $mapped */
                    $mapped = ErpNextCrmMapper::{$mapper}($row);
                    $erpName = is_string($mapped['erp_name'] ?? null) ? (string) $mapped['erp_name'] : '';

                    if ($erpName === '') {
                        $counts['errors']++;

                        continue;
                    }

                    $amount = (float) ($mapped[$sumField] ?? ($mapped['total'] ?? 0));
                    $counts[$sumKey] += $amount;

                    $customerErp = is_string($mapped['customer_erp_name'] ?? null)
                        ? (string) $mapped['customer_erp_name']
                        : '';
                    $customer = $customerErp !== ''
                        ? Customer::query()->where('erp_name', $customerErp)->first()
                        : null;
                    $linked = $customer instanceof Customer;

                    $link = ErpLink::query()
                        ->where('doctype', $doctype)
                        ->where('erp_name', $erpName)
                        ->first();
                    $hash = ErpNextCrmMapper::contentHash($mapped);

                    if (! $linked) {
                        $counts['unlinked']++;
                        $classification = 'unlinked';
                    } elseif ($link === null) {
                        $counts['would_create']++;
                        $classification = 'would_create';
                    } elseif ($link->content_hash !== $hash) {
                        $counts['would_update']++;
                        $classification = 'would_update';
                    } else {
                        $counts['unchanged']++;
                        $classification = 'unchanged';
                    }

                    $modified = isset($row['modified']) ? (string) $row['modified'] : null;

                    if ($modified !== null && ($maxModified === null || $modified > $maxModified)) {
                        $maxModified = $modified;
                    }

                    if ($apply && $linked && in_array($classification, ['would_create', 'would_update'], true)) {
                        $this->applyMirror($doctype, $mapped, $row, $customer, $hash, $fibesType);
                        $wrote = true;
                    }
                } catch (Throwable) {
                    $counts['errors']++;
                }
            }

            $start += count($rows);
        } while (count($rows) === 100);

        if ($apply && $maxModified !== null && $counts['errors'] === 0) {
            $this->saveCursor($doctype, $maxModified);
        }

        return ['counts' => $counts, 'wrote' => $wrote];
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<string, mixed>  $row
     */
    private function applyMirror(string $doctype, array $mapped, array $row, Customer $customer, string $hash, string $fibesType): void
    {
        $erpName = (string) $mapped['erp_name'];

        if ($doctype === 'Sales Invoice') {
            $mirror = ErpSalesInvoice::query()->updateOrCreate(
                ['erp_name' => $erpName],
                [
                    'customer_id' => $customer->id,
                    'customer_erp_name' => $mapped['customer_erp_name'] ?? null,
                    'posting_date' => $mapped['posting_date'] ?? null,
                    'due_date' => $mapped['due_date'] ?? null,
                    'currency' => $mapped['currency'] ?? null,
                    'grand_total' => (float) ($mapped['total'] ?? 0),
                    'outstanding_amount' => (float) ($mapped['outstanding_amount'] ?? 0),
                    'status' => (string) ($mapped['status'] ?? 'open'),
                    'docstatus' => (int) ($row['docstatus'] ?? 0),
                    'is_return' => (bool) ($row['is_return'] ?? false),
                    'content_hash' => $hash,
                    'erp_modified' => $row['modified'] ?? null,
                    'last_synced_at' => now(),
                ],
            );
            $fibesId = $mirror->id;
        } else {
            $mirror = ErpPaymentEntry::query()->updateOrCreate(
                ['erp_name' => $erpName],
                [
                    'customer_id' => $customer->id,
                    'customer_erp_name' => $mapped['customer_erp_name'] ?? null,
                    'posting_date' => $mapped['posting_date'] ?? null,
                    'paid_amount' => (float) ($mapped['paid_amount'] ?? 0),
                    'received_amount' => (float) ($mapped['received_amount'] ?? 0),
                    'mode_of_payment' => $mapped['mode_of_payment'] ?? null,
                    'status' => $mapped['status'] ?? null,
                    'docstatus' => (int) ($mapped['docstatus'] ?? ($row['docstatus'] ?? 0)),
                    'party_type' => $mapped['party_type'] ?? null,
                    'content_hash' => $hash,
                    'erp_modified' => $row['modified'] ?? null,
                    'last_synced_at' => now(),
                ],
            );
            $fibesId = $mirror->id;
        }

        ErpLink::query()->updateOrCreate(
            ['doctype' => $doctype, 'erp_name' => $erpName],
            [
                'fibes_type' => $fibesType,
                'fibes_id' => $fibesId,
                'erp_modified' => $row['modified'] ?? null,
                'content_hash' => $hash,
                'last_synced_at' => now(),
                'last_error' => null,
            ],
        );
    }

    private function loadCursor(string $doctype): ?string
    {
        $setting = Setting::query()->where('key', 'erpnext_crm_cursors')->first();
        $value = is_array($setting?->value) ? $setting->value : [];
        $cursor = $value[$doctype] ?? null;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    private function saveCursor(string $doctype, string $cursor): void
    {
        $setting = Setting::query()->where('key', 'erpnext_crm_cursors')->first();
        $value = is_array($setting?->value) ? $setting->value : [];
        $value[$doctype] = $cursor;

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
