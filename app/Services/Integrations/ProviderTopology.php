<?php

namespace App\Services\Integrations;

use App\Models\WebhookDelivery;

/**
 * Hub-and-spoke reading of the services FIBES CRM already talks to.
 *
 * There is no providers table. Each row reads a setting, a flag, or the
 * webhook delivery log. `sample` is true when that reading is only a
 * stand-in: the connection is known, but nothing has checked it live.
 * Replace the matching method and set `sample` to false when a probe exists.
 */
class ProviderTopology
{
    /**
     * @return list<array{key: string, group: string, status: string, sample: bool}>
     */
    public function providers(): array
    {
        return [
            $this->secretRow('web_form', 'leads', (string) config('fibes.webhooks.web_form_secret')),
            $this->meta(),
            $this->secretRow('turnstile', 'leads', (string) config('fibes.webhooks.turnstile_secret')),
            $this->whish(),
            $this->ledger('omt'),
            $this->ledger('bank'),
            $this->ledger('card'),
            $this->ledger('cheque'),
            $this->ledger('cash'),
            // wa.me links only. There is no WhatsApp Business API switch yet.
            $this->row('whatsapp', 'messaging', 'recent', true),
            $this->email(),
            $this->sms(),
            $this->secretRow('telephony', 'messaging', (string) config('fibes.telephony.secret')),
            $this->krypton(),
            $this->fiberdesk(),
            $this->pdf(),
            $this->fileScan(),
            $this->maps(),
            $this->webhooks(),
            $this->secretRow('sentry', 'platform', (string) config('fibes.observability.dsn')),
        ];
    }

    /**
     * @return array{key: string, group: string, status: string, sample: bool}
     */
    private function meta(): array
    {
        $token = trim((string) config('fibes.webhooks.meta_verify_token'));
        $secret = trim((string) config('fibes.webhooks.meta_app_secret'));

        if ($token !== '' && $secret !== '') {
            return $this->row('meta', 'leads', 'active', false);
        }

        if ($token !== '' || $secret !== '') {
            return $this->row('meta', 'leads', 'error', false);
        }

        return $this->row('meta', 'leads', 'recent', true);
    }

    /**
     * Whish payments can be typed by hand. The live API is a separate switch.
     *
     * @return array{key: string, group: string, status: string, sample: bool}
     */
    private function whish(): array
    {
        if (config('fibes.whish.enabled')) {
            return $this->row('whish', 'payments', 'active', false);
        }

        return $this->row('whish', 'payments', 'recent', true);
    }

    /**
     * @return array{key: string, group: string, status: string, sample: bool}
     */
    private function email(): array
    {
        $mailer = (string) config('mail.default');

        if (in_array($mailer, ['log', 'array', ''], true)) {
            return $this->row('email', 'messaging', 'recent', true);
        }

        return $this->row('email', 'messaging', 'active', false);
    }

    /**
     * @return array{key: string, group: string, status: string, sample: bool}
     */
    private function sms(): array
    {
        $driver = (string) config('fibes.documents.sms');

        if (in_array($driver, ['log', ''], true)) {
            return $this->row('sms', 'messaging', 'recent', true);
        }

        return $this->row('sms', 'messaging', 'active', false);
    }

    /**
     * Krypton is the RADIUS path. The flag is the real signal, not a stand-in.
     *
     * @return array{key: string, group: string, status: string, sample: bool}
     */
    private function krypton(): array
    {
        if (config('fibes.radius.enabled')) {
            return $this->row('krypton', 'network', 'active', false);
        }

        return $this->row('krypton', 'network', 'error', false);
    }

    /**
     * @return array{key: string, group: string, status: string, sample: bool}
     */
    private function fiberdesk(): array
    {
        if (config('fibes.coverage.fiberdesk')) {
            return $this->row('fiberdesk', 'network', 'active', false);
        }

        return $this->row('fiberdesk', 'network', 'recent', true);
    }

    /**
     * @return array{key: string, group: string, status: string, sample: bool}
     */
    private function pdf(): array
    {
        $driver = (string) config('fibes.documents.driver');

        if ($driver === 'chromium' && trim((string) config('fibes.documents.chromium')) === '') {
            return $this->row('pdf', 'platform', 'error', false);
        }

        return $this->row('pdf', 'platform', 'active', false);
    }

    /**
     * @return array{key: string, group: string, status: string, sample: bool}
     */
    private function fileScan(): array
    {
        // env('FIBES_FILE_SCAN_DRIVER') turns the string "null" into PHP null.
        // That value is the built-in check, not a missing driver.
        $driver = config('fibes.attachments.driver');
        $name = $driver === null ? 'null' : strtolower(trim((string) $driver));

        if (in_array($name, ['', 'null', 'clamav'], true)) {
            return $this->row('filescan', 'platform', 'active', false);
        }

        return $this->row('filescan', 'platform', 'error', false);
    }

    /**
     * @return array{key: string, group: string, status: string, sample: bool}
     */
    private function maps(): array
    {
        $url = trim((string) config('fibes.coverage.tile_url'));

        return $this->row('maps', 'platform', $url === '' ? 'error' : 'active', false);
    }

    /**
     * @return array{key: string, group: string, status: string, sample: bool}
     */
    private function webhooks(): array
    {
        $latest = WebhookDelivery::query()->latest('id')->first();

        if (! $latest instanceof WebhookDelivery) {
            return $this->row('webhooks', 'platform', 'recent', true);
        }

        if ($latest->status === 'dead') {
            return $this->row('webhooks', 'platform', 'error', false);
        }

        if ($latest->status === 'delivered') {
            return $this->row('webhooks', 'platform', 'active', false);
        }

        return $this->row('webhooks', 'platform', 'recent', true);
    }

    /**
     * @return array{key: string, group: string, status: string, sample: bool}
     */
    private function secretRow(string $key, string $group, string $value): array
    {
        if (trim($value) !== '') {
            return $this->row($key, $group, 'active', false);
        }

        return $this->row($key, $group, 'recent', true);
    }

    /**
     * Cash, OMT, bank, card, and cheque are recorded on the ledger today.
     *
     * @return array{key: string, group: string, status: string, sample: bool}
     */
    private function ledger(string $key): array
    {
        return $this->row($key, 'payments', 'active', false);
    }

    /**
     * @return array{key: string, group: string, status: string, sample: bool}
     */
    private function row(string $key, string $group, string $status, bool $sample): array
    {
        return [
            'key' => $key,
            'group' => $group,
            'status' => $status,
            'sample' => $sample,
        ];
    }
}
