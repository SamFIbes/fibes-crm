<?php

namespace App\Jobs;

use App\Models\CustomerService;
use App\Services\Integrations\HttpKryptonRadiusClient;
use App\Services\Integrations\IntegrationRecorder;
use App\Services\Settings\CommercialSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PushKryptonRadiusUser implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $serviceId) {}

    public function tries(): int
    {
        return max(1, (int) app(CommercialSettings::class)->get('webhook_max_attempts'));
    }

    public function backoff(): int
    {
        return max(1, (int) app(CommercialSettings::class)->get('webhook_backoff_seconds'));
    }

    public function handle(HttpKryptonRadiusClient $client, IntegrationRecorder $recorder): void
    {
        $service = CustomerService::query()->find($this->serviceId);

        if (! $service instanceof CustomerService || ! filled($service->radius_username)) {
            return;
        }

        try {
            $client->performUpsert($service);
            $recorder->attempt('krypton', 'upsert', 'succeeded', 'Radius user '.$service->radius_username, null, [
                'customer_id' => $service->customer_id,
                'service_id' => $service->id,
                'username' => $service->radius_username,
            ], null, $this->attempts());
        } catch (Throwable $exception) {
            $recorder->attempt('krypton', 'upsert', 'failed', $recorder->redact($exception->getMessage()), null, [
                'customer_id' => $service->customer_id,
                'service_id' => $service->id,
                'username' => $service->radius_username,
            ], null, $this->attempts());

            throw $exception;
        }
    }
}
