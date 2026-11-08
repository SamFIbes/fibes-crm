<?php

namespace App\Jobs;

use App\Models\StockRequest;
use App\Models\User;
use App\Services\Integrations\ErpNext\ErpNextClient;
use App\Services\Integrations\ErpNext\ErpNextMap;
use App\Services\Integrations\IntegrationRecorder;
use App\Services\Integrations\IntegrationSettings;
use App\Services\Settings\CommercialSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PushErpNextMaterialRequest implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $stockRequestId) {}

    public function tries(): int
    {
        return max(1, (int) app(CommercialSettings::class)->get('webhook_max_attempts'));
    }

    public function backoff(): int
    {
        return max(1, (int) app(CommercialSettings::class)->get('webhook_backoff_seconds'));
    }

    public function handle(ErpNextClient $client, ErpNextMap $map, IntegrationRecorder $recorder, IntegrationSettings $settings): void
    {
        $row = StockRequest::query()->find($this->stockRequestId);

        if (! $row instanceof StockRequest || $row->status === 'submitted') {
            return;
        }

        $actor = $row->requested_by ? User::query()->find($row->requested_by) : null;
        $actor = $actor instanceof User ? $actor : null;
        $row->status = 'queued';
        $row->save();

        try {
            if (! $settings->runtime('erpnext')['live']) {
                throw new \RuntimeException('Hub ERP is not configured.');
            }

            $payload = $map->materialRequest($row);
            $result = $client->createMaterialRequest($payload);
            $name = data_get($result, 'data.name');
            $row->status = 'submitted';
            $row->erp_name = is_string($name) ? $name : null;
            $row->remarks = $map->remarks($row);
            $row->last_error = null;
            $row->save();
            $recorder->attempt('erpnext', 'material_request', 'succeeded', 'Draft material request '.$row->number, $actor, [
                'stock_request_id' => $row->id,
                'erp_name' => $row->erp_name,
                'customer_id' => $row->customer_id,
                'network_node_id' => $row->network_node_id,
                'job_id' => $row->job_id,
                'expense_id' => $row->expense_id,
            ], null, $this->attemptNumber());
        } catch (Throwable $exception) {
            $message = $recorder->redact($exception->getMessage());
            $row->last_error = $message;
            $row->save();
            $recorder->attempt('erpnext', 'material_request', 'failed', $message, $actor, [
                'stock_request_id' => $row->id,
            ], null, $this->attemptNumber());

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $row = StockRequest::query()->find($this->stockRequestId);

        if ($row instanceof StockRequest && $row->status !== 'submitted') {
            $row->status = 'failed';
            $row->save();
        }
    }

    private function attemptNumber(): int
    {
        return max(1, $this->attempts());
    }
}
