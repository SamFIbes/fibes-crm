<?php

namespace App\Services\Integrations;

use App\Jobs\SendWhatsAppTemplate;
use App\Models\Customer;
use App\Models\User;

class WhatsAppDesk
{
    public function __construct(private WhatsAppBusinessClient $whatsapp) {}

    /**
     * Queues an approved template when live and the customer has agreed.
     * Returns off or no_consent without calling Meta. wa.me stays the caller's fallback.
     *
     * @param  list<string>  $parameters
     */
    public function queue(Customer $customer, string $kind, array $parameters, ?User $actor): string
    {
        if (! $customer->whatsapp_consent || ! filled($customer->whatsapp_e164)) {
            return 'no_consent';
        }

        if (! in_array($kind, ['renewal', 'ticket', 'payment'], true) || ! $this->whatsapp->ready()) {
            return 'off';
        }

        $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';
        SendWhatsAppTemplate::dispatch(
            $customer->id,
            $kind,
            array_values(array_map(fn (mixed $value): string => (string) $value, $parameters)),
            $locale,
            $actor?->id,
        );

        return 'queued';
    }
}
