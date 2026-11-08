<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Integrations\IntegrationRecorder;
use App\Services\Integrations\WhishClient;
use App\Services\Payments\PaymentDesk;
use App\Services\Settings\CommercialSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CreateWhishCheckout implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $invoiceId, public int $actorId) {}

    public function tries(): int
    {
        return max(1, (int) app(CommercialSettings::class)->get('webhook_max_attempts'));
    }

    public function backoff(): int
    {
        return max(1, (int) app(CommercialSettings::class)->get('webhook_backoff_seconds'));
    }

    public function handle(WhishClient $client, PaymentDesk $desk, IntegrationRecorder $recorder): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);
        $actor = User::query()->find($this->actorId);

        if (! $invoice instanceof Invoice || ! $actor instanceof User) {
            return;
        }

        if (! in_array($invoice->status, ['issued', 'partially_paid', 'overdue'], true) || $invoice->type !== 'invoice') {
            return;
        }

        if (filled($invoice->whish_checkout_id)) {
            return;
        }

        try {
            if (! $client->ready()) {
                throw new \RuntimeException('Whish is not configured.');
            }

            $checkout = $client->createCheckout($invoice);
            $desk->openWhish($invoice, $checkout['id'], $checkout['url'], $actor);
            $recorder->attempt('whish', 'checkout', 'succeeded', 'Checkout '.$checkout['id'], $actor, [
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'checkout_id' => $checkout['id'],
            ], null, $this->attempts());
        } catch (Throwable $exception) {
            $recorder->attempt('whish', 'checkout', 'failed', $recorder->redact($exception->getMessage()), $actor, [
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
            ], null, $this->attempts());

            throw $exception;
        }
    }
}
