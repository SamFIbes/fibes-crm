<?php

namespace App\Services\Integrations\ErpNext;

use App\Models\StockRequest;
use App\Services\Integrations\IntegrationSettings;

/**
 * CRM ↔ Hub ERP (ERPNext at erp.thehubholding.com).
 *
 * Confirmed on the live desk, 5 Nov 2026, without API credentials:
 * the company is Smart Network (LBP, Lebanon). Stock includes Item,
 * Warehouse, Material Request, Stock Entry, Purchase Receipt, Delivery
 * Note, Pick List, Assets, and the stock reports. A Material Request
 * list shows Title, Status, Purpose, Transaction Date, Required By, and ID.
 * Statuses seen there: Pending, Ordered, Received, Cancelled.
 *
 * Item.item_code                 ↔ stock_items.erp_item_code, else sku
 * Warehouse.name                 ↔ stock_locations via the integrations warehouse map
 * Material Request (draft)       ↔ stock_requests
 *   title                        ↔ stock request number plus the item name
 *   material_request_type        ↔ purpose (live purchase flow: Pending → Ordered → Received)
 *   transaction_date             ↔ the day the CRM queues the request
 *   schedule_date                ↔ required by
 *   name                         ↔ stock_requests.erp_name once ERP accepts the draft
 * Customer, base station, job,   written in the item description (remarks).
 * and expense                    Planned DocType fields, not sent until they exist:
 *                                custom_fibes_customer_id, custom_fibes_network_node_id,
 *                                custom_fibes_job_id, custom_fibes_expense_id,
 *                                custom_fibes_request.
 * Asset                          ↔ read-only list. FIBES has no asset register yet.
 * Expense Claim                  ↔ expenses.erp_name later. Expense Claim was not
 *                                  on the stock desk, so this phase only stores the
 *                                  FIBES expense and mentions it in the remarks.
 * Stock Entry                    ↔ read by name when ERP posts one. Not created here.
 */
class ErpNextMap
{
    /** @var list<string> */
    public const OBSERVED_STATUSES = ['Pending', 'Ordered', 'Received', 'Cancelled'];

    /** @var list<string> */
    public const PURPOSES = ['Purchase', 'Material Transfer', 'Material Issue', 'Manufacture', 'Customer Provided'];

    /** @var list<string> */
    public const ITEM_FIELDS = ['name', 'item_code', 'item_name', 'item_group', 'stock_uom', 'brand', 'disabled'];

    /** @var list<string> */
    public const WAREHOUSE_FIELDS = ['name', 'warehouse_name', 'company', 'is_group', 'disabled'];

    /** @var list<string> */
    public const MATERIAL_REQUEST_FIELDS = ['name', 'title', 'status', 'material_request_type', 'transaction_date', 'schedule_date'];

    /** @var list<string> */

    /** @var list<string> */
    public const CUSTOMER_FIELDS = ['name', 'customer_name', 'customer_type', 'customer_group', 'territory', 'mobile_no', 'email_id', 'tax_id', 'disabled', 'modified'];

    /** @var list<string> */
    public const LEAD_FIELDS = ['name', 'lead_name', 'first_name', 'last_name', 'email_id', 'mobile_no', 'phone', 'status', 'company_name', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'modified'];

    /** @var list<string> */
    public const QUOTATION_FIELDS = ['name', 'customer_name', 'party_name', 'currency', 'grand_total', 'status', 'valid_till', 'modified'];

    /** @var list<string> */
    public const SALES_INVOICE_FIELDS = ['name', 'customer', 'customer_name', 'currency', 'grand_total', 'status', 'docstatus', 'is_return', 'posting_date', 'modified'];

    public const ASSET_FIELDS = ['name', 'asset_name', 'item_code', 'status', 'location', 'company'];

    /** @var list<string> */
    public const CUSTOM_FIELDS = [
        'custom_fibes_customer_id',
        'custom_fibes_network_node_id',
        'custom_fibes_job_id',
        'custom_fibes_expense_id',
        'custom_fibes_request',
    ];

    public function __construct(private IntegrationSettings $settings) {}

    /**
     * Draft Material Request body. Custom fields are omitted on purpose:
     * Frappe rejects unknown fields, and those DocType fields do not exist yet.
     *
     * @return array<string, mixed>
     */
    public function materialRequest(StockRequest $request): array
    {
        $request->loadMissing('item');
        $item = $request->item;
        $code = trim((string) ($item->erp_item_code ?? ''));

        if ($code === '') {
            $code = (string) ($item->sku ?? '');
        }

        $when = $request->required_by?->toDateString() ?? now()->toDateString();
        $warehouse = $request->warehouse ?: $this->settings->warehouseFor($request->location_id);
        $name = trim((string) ($item->name_en ?? ''));
        $title = $name !== '' ? $name.' ('.$request->number.')' : (string) $request->number;
        $line = [
            'item_code' => $code,
            'qty' => (float) $request->quantity,
            'schedule_date' => $when,
            'description' => $this->remarks($request),
        ];

        if ($warehouse !== '') {
            $line['warehouse'] = $warehouse;
        }

        return [
            'doctype' => 'Material Request',
            'company' => $this->settings->company(),
            'material_request_type' => $this->purpose((string) $request->purpose),
            'transaction_date' => now()->toDateString(),
            'schedule_date' => $when,
            'title' => $title,
            'docstatus' => 0,
            'items' => [$line],
        ];
    }

    public function remarks(StockRequest $request): string
    {
        $lines = ['FIBES '.$request->number];

        if ($request->customer_id) {
            $lines[] = 'Customer '.$request->customer_id;
        }

        if ($request->network_node_id) {
            $lines[] = 'Base station '.$request->network_node_id;
        }

        if ($request->job_id) {
            $lines[] = 'Job '.$request->job_id;
        }

        if ($request->expense_id) {
            $lines[] = 'Expense '.$request->expense_id;
        }

        return implode("\n", $lines);
    }

    public function purpose(string $purpose): string
    {
        return in_array($purpose, self::PURPOSES, true) ? $purpose : 'Purchase';
    }
}
