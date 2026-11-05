<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Integrations\HttpKryptonRadiusClient;
use App\Services\Integrations\IntegrationRecorder;
use App\Services\Settings\CommercialSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PushKryptonRadiusAction implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $action,
        public string $username,
        public ?int $customerId = null,
        public ?int $actorId = null,
    ) {}

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
        $actor = $this->actor();

        try {
            $client->perform($this->action, $this->username);
            $recorder->attempt('krypton', $this->action, 'succeeded', 'Radius '.$this->action.' '.$this->username, $actor, [
                'customer_id' => $this->customerId,
                'username' => $this->username,
            ], null, $this->attempts());
        } catch (Throwable $exception) {
            $recorder->attempt('krypton', $this->action, 'failed', $exception->getMessage(), $actor, [
                'customer_id' => $this->customerId,
                'username' => $this->username,
            ], null, $this->attempts());

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(HttpKryptonRadiusClient::class)->recordFallback($this->customerId, $this->action, $this->actorId);
    }

    private function actor(): ?User
    {
        $actor = $this->actorId ? User::query()->find($this->actorId) : null;

        return $actor instanceof User ? $actor : null;
    }
}
