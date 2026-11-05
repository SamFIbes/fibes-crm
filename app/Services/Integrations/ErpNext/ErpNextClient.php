<?php

namespace App\Services\Integrations\ErpNext;

use App\Services\Integrations\IntegrationSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Frappe token auth: Authorization: token api_key:api_secret.
 * Nothing is called unless the environment flag, the desk switch, and both secrets are set.
 */
class ErpNextClient
{
    public function __construct(private IntegrationSettings $settings) {}

    public function ready(): bool
    {
        return $this->settings->runtime('erpnext')['live'];
    }

    /**
     * @return array<string, mixed>
     */
    public function ping(): array
    {
        return $this->send('GET', '/api/method/frappe.ping');
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        $body = $this->ping();
        $pong = ($body['message'] ?? null) === 'pong';

        return ['ok' => $pong, 'message' => $pong ? 'pong' : 'unexpected'];
    }

    /**
     * @return array<string, mixed>
     */
    public function listItems(int $limit = 20, int $start = 0): array
    {
        return $this->listResource('Item', ErpNextMap::ITEM_FIELDS, $limit, $start);
    }

    /**
     * @return array<string, mixed>
     */
    public function listWarehouses(int $limit = 20, int $start = 0): array
    {
        return $this->listResource('Warehouse', ErpNextMap::WAREHOUSE_FIELDS, $limit, $start);
    }

    /**
     * @return array<string, mixed>
     */
    public function listMaterialRequests(int $limit = 20, int $start = 0): array
    {
        return $this->listResource('Material Request', ErpNextMap::MATERIAL_REQUEST_FIELDS, $limit, $start);
    }

    /**
     * @return array<string, mixed>
     */
    public function listAssets(int $limit = 20, int $start = 0): array
    {
        return $this->listResource('Asset', ErpNextMap::ASSET_FIELDS, $limit, $start);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function createMaterialRequest(array $document): array
    {
        $document['doctype'] = 'Material Request';
        $document['docstatus'] = 0;

        foreach (ErpNextMap::CUSTOM_FIELDS as $field) {
            unset($document[$field]);
        }

        return $this->send('POST', $this->resource('Material Request'), $document);
    }

    /**
     * @return array<string, mixed>
     */
    public function getMaterialRequest(string $name): array
    {
        return $this->send('GET', $this->resource('Material Request', $name));
    }

    /**
     * @return array<string, mixed>
     */
    public function getStockEntry(string $name): array
    {
        return $this->send('GET', $this->resource('Stock Entry', $name));
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function listResource(string $doctype, array $fields, int $limit, int $start): array
    {
        return $this->send('GET', $this->resource($doctype), [
            'limit_page_length' => max(1, min(100, $limit)),
            'limit_start' => max(0, $start),
            'fields' => json_encode($fields),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body = null): array
    {
        if (! $this->ready()) {
            throw new RuntimeException('Hub ERP is not configured.');
        }

        $pending = $this->http();
        $response = $method === 'POST'
            ? $pending->asJson()->post($path, $body ?? [])
            : $pending->get($path, $body ?? []);

        try {
            $response->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException($this->settings->redact($exception->getMessage()), 0, $exception);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function http(): PendingRequest
    {
        $key = (string) config('fibes.erpnext.api_key');
        $secret = (string) config('fibes.erpnext.api_secret');

        return Http::baseUrl($this->baseUrl())
            ->timeout(15)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'token '.$key.':'.$secret,
            ]);
    }

    private function baseUrl(): string
    {
        return rtrim($this->settings->runtime('erpnext')['url'], '/');
    }

    private function resource(string $doctype, ?string $name = null): string
    {
        $this->assertName($doctype);
        $path = '/api/resource/'.rawurlencode($doctype);

        if ($name !== null) {
            $this->assertName($name);
            $path .= '/'.rawurlencode($name);
        }

        return $path;
    }

    private function assertName(string $name): void
    {
        if (trim($name) === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
            throw new RuntimeException('Invalid ERP document name.');
        }
    }
}
