<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Integrations\GrandstreamClient;
use App\Services\Integrations\IntegrationRecorder;
use App\Services\Settings\CommercialSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DialGrandstreamCall implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $actorId,
        public string $extension,
        public string $destination,
    ) {}

    public function tries(): int
    {
        return max(1, (int) app(CommercialSettings::class)->get('webhook_max_attempts'));
    }

    public function backoff(): int
    {
        return max(1, (int) app(CommercialSettings::class)->get('webhook_backoff_seconds'));
    }

    public function handle(GrandstreamClient $client, IntegrationRecorder $recorder): void
    {
        $actor = User::query()->find($this->actorId);
        $actor = $actor instanceof User ? $actor : null;

        try {
            $client->dial($this->extension, $this->destination);
            $recorder->attempt('grandstream', 'dial', 'succeeded', 'Click-to-dial '.$this->extension, $actor, [
                'extension' => $this->extension,
            ], null, $this->attempts());
        } catch (Throwable $exception) {
            $recorder->attempt('grandstream', 'dial', 'failed', $recorder->redact($exception->getMessage()), $actor, [
                'extension' => $this->extension,
            ], null, $this->attempts());

            throw $exception;
        }
    }
}
