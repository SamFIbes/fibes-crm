<?php

namespace App\Services\Integrations\ErpNext;

use App\Models\ErpLink;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class ErpNextCrmSync
{
    private const SOURCES = [
        'customers' => ['file' => 'customers.json', 'doctype' => 'Customer', 'mapper' => 'mapCustomer'],
        'leads' => ['file' => 'leads.json', 'doctype' => 'Lead', 'mapper' => 'mapLead'],
        'quotations' => ['file' => 'quotations.json', 'doctype' => 'Quotation', 'mapper' => 'mapQuotation'],
        'sales_invoices' => ['file' => 'sales_invoices.json', 'doctype' => 'Sales Invoice', 'mapper' => 'mapSalesInvoice'],
    ];

    public function __construct(private readonly ?string $fixturesDirectory = null)
    {
    }

    public function loadFixtures(): array
    {
        $directory = $this->fixturesDirectory ?? base_path('tests/fixtures/erpnext');
        $fixtures = [];

        foreach (self::SOURCES as $type => $source) {
            $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $source['file'];

            if (!File::exists($path)) {
                throw new RuntimeException("ERPNext fixture not found: {$path}");
            }

            $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded) || !array_is_list($decoded)) {
                throw new RuntimeException("ERPNext fixture must contain a JSON list: {$path}");
            }

            $fixtures[$type] = $decoded;
        }

        return $fixtures;
    }


    /**
     * @param  array<string, mixed>  $mapped
     * @return 'would_create'|'would_update'|'unchanged'
     */
    public function classify(string $doctype, string $erpName, array $mapped): string
    {
        $link = ErpLink::query()
            ->where('doctype', $doctype)
            ->where('erp_name', $erpName)
            ->first();

        if ($link === null) {
            return 'would_create';
        }

        if ($link->content_hash !== ErpNextCrmMapper::contentHash($mapped)) {
            return 'would_update';
        }

        return 'unchanged';
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
                if (!is_array($doc)) {
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
}
