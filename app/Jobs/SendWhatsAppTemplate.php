<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\User;
use App\Services\Integrations\IntegrationRecorder;
use App\Services\Integrations\WhatsAppBusinessClient;
use App\Services\Settings\CommercialSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendWhatsAppTemplate implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $parameters
     */
    public function __construct(
        public int $customerId,
        public string $kind,
        public array $parameters,
        public string $locale,
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

    public function handle(WhatsAppBusinessClient $client, IntegrationRecorder $recorder): void
    {
        $customer = Customer::query()->find($this->customerId);
        $actor = $this->actorId ? User::query()->find($this->actorId) : null;
        $actor = $actor instanceof User ? $actor : null;

        if (! $customer instanceof Customer || ! $customer->whatsapp_consent || ! filled($customer->whatsapp_e164)) {
            $recorder->attempt('whatsapp', $this->kind, 'failed', 'no_consent', $actor, [
                'customer_id' => $this->customerId,
            ], null, $this->attempts());

            return;
        }

        $template = (string) config('fibes.whatsapp.templates.'.$this->kind);

        try {
            if (! $client->ready()) {
                throw new \RuntimeException('WhatsApp Business is not configured.');
            }

            $result = $client->sendTemplate((string) $customer->whatsapp_e164, $template, $this->locale, $this->parameters);
            $recorder->attempt('whatsapp', $this->kind, $result['ok'] ? 'succeeded' : 'failed', $result['ok'] ? 'Template '.$template : 'unexpected', $actor, [
                'customer_id' => $customer->id,
                'template' => $template,
            ], null, $this->attempts());

            if (! $result['ok']) {
                throw new \RuntimeException('WhatsApp did not accept the template.');
            }
        } catch (Throwable $exception) {
            if ($exception->getMessage() !== 'WhatsApp did not accept the template.') {
                $recorder->attempt('whatsapp', $this->kind, 'failed', $recorder->redact($exception->getMessage()), $actor, [
                    'customer_id' => $customer->id,
                    'template' => $template,
                ], null, $this->attempts());
            }

            throw $exception;
        }
    }
}
