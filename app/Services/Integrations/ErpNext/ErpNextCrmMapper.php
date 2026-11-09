<?php

namespace App\Services\Integrations\ErpNext;

use InvalidArgumentException;

final class ErpNextCrmMapper
{
    public static function mapCustomer(array $doc): array
    {
        $customerType = strtolower((string) ($doc['customer_type'] ?? ''));
        $status = strtolower((string) ($doc['status'] ?? ''));

        return [
            'account_no' => $doc['name'] ?? null,
            'name_en' => $doc['customer_name'] ?? null,
            'name_ar' => $doc['customer_name'] ?? null,
            'email' => $doc['email_id'] ?? ($doc['email'] ?? null),
            'whatsapp_e164' => self::phone($doc['mobile_no'] ?? null),
            'type' => $customerType === 'company' ? 'business' : 'residential',
            'status' => in_array($status, ['disabled', 'inactive'], true) || (bool) ($doc['disabled'] ?? false)
                ? 'disabled'
                : 'active',
            'company_name' => $doc['company_name'] ?? null,
            'erp_name' => $doc['name'] ?? null,
        ];
    }

    public static function mapLead(array $doc): array
    {
        $phone = $doc['mobile_no'] ?? ($doc['phone'] ?? null);

        return [
            'name' => $doc['lead_name'] ?? ($doc['name'] ?? null),
            'first_name' => $doc['first_name'] ?? null,
            'last_name' => $doc['last_name'] ?? null,
            'email' => $doc['email_id'] ?? ($doc['email'] ?? null),
            'phone_e164' => self::phone($phone),
            'status' => 'new',
            'source' => 'erpnext_import',
            'notes' => $doc['notes'] ?? null,
            'external_id' => $doc['name'] ?? null,
            'utm' => array_filter([
                'source' => $doc['utm_source'] ?? null,
                'medium' => $doc['utm_medium'] ?? null,
                'campaign' => $doc['utm_campaign'] ?? null,
                'content' => $doc['utm_content'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
        ];
    }

    public static function mapQuotation(array $doc): array
    {
        return [
            'number' => $doc['name'] ?? null,
            'customer_name' => $doc['customer_name'] ?? null,
            'customer_email' => $doc['customer_email'] ?? ($doc['contact_email'] ?? null),
            'currency' => $doc['currency'] ?? null,
            'total' => isset($doc['grand_total']) ? (float) $doc['grand_total'] : null,
            'status' => self::quotationStatus($doc),
            'valid_until' => $doc['valid_till'] ?? null,
            'erp_ref' => $doc['name'] ?? null,
        ];
    }

    public static function mapSalesInvoice(array $doc): array
    {
        $docStatus = (int) ($doc['docstatus'] ?? 0);
        $status = strtolower((string) ($doc['status'] ?? ''));

        return [
            'number' => $doc['name'] ?? null,
            'type' => (bool) ($doc['is_return'] ?? false) ? 'credit' : 'one_time',
            'total' => isset($doc['grand_total']) ? (float) $doc['grand_total'] : null,
            'currency' => $doc['currency'] ?? null,
            'status' => in_array($status, ['paid', 'unpaid', 'overdue', 'cancelled'], true)
                ? ($status === 'paid' ? 'paid' : 'open')
                : ($docStatus === 0 ? 'draft' : 'open'),
            'erp_name' => $doc['name'] ?? null,
            'customer_erp_name' => $doc['customer'] ?? null,
        ];
    }

    public static function contentHash(array $mapped): string
    {
        $canonical = self::canonicalize($mapped);
        $encoded = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        if ($encoded === false) {
            throw new InvalidArgumentException('The mapped ERP payload cannot be encoded as JSON.');
        }

        return hash('sha256', $encoded);
    }

    private static function phone(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $phone = (string) $value;

        return str_starts_with($phone, '+') || !ctype_digit($phone) ? $phone : '+' . $phone;
    }

    private static function quotationStatus(array $doc): string
    {
        $status = strtolower((string) ($doc['status'] ?? ''));

        return match (true) {
            in_array($status, ['lost', 'closed'], true) => 'lost',
            in_array($status, ['accepted', 'ordered'], true) => 'accepted',
            default => 'draft',
        };
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
