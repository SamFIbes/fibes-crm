<?php

namespace App\Services\Integrations;

use App\Jobs\PushKryptonRadiusAction;
use App\Jobs\PushKryptonRadiusUser;
use App\Models\CustomerService;
use App\Models\User;
use App\Models\WorkTask;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Assumed Krypton / Smart Networks REST API. Paths live in config('fibes.radius.paths')
 * so they can change when the vendor guide arrives. Nothing is called unless the
 * flag, API key, base URL, and desk switch are all on.
 *
 * Auth: Authorization: Bearer {FIBES_RADIUS_API_KEY}. The key is never written to
 * the attempt log. Suspend and reactivate are queued. A session read is on demand.
 */
class HttpKryptonRadiusClient implements KryptonRadiusClient
{
    public function __construct(private IntegrationSettings $settings) {}

    public function ready(): bool
    {
        $runtime = $this->settings->runtime('krypton');

        return $runtime['live'] && $runtime['url'] !== '';
    }

    public function testConnection(): array
    {
        if (! $this->ready()) {
            return ['ok' => false, 'message' => 'not_configured'];
        }

        try {
            $body = $this->send('GET', $this->plainPath('health'));
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $this->settings->redact($exception->getMessage())];
        }

        $ok = ($body['ok'] ?? false) === true || ($body['status'] ?? null) === 'ok';

        return ['ok' => $ok, 'message' => $ok ? 'ok' : 'unexpected'];
    }

    public function suspend(string $username, ?int $customerId = null, ?int $actorId = null): void
    {
        $this->enqueue('suspend', $username, $customerId, $actorId);
    }

    public function reactivate(string $username, ?int $customerId = null, ?int $actorId = null): void
    {
        $this->enqueue('reactivate', $username, $customerId, $actorId);
    }

    public function queueUpsert(CustomerService $service): void
    {
        if (! $this->ready() || ! filled($service->radius_username)) {
            return;
        }

        PushKryptonRadiusUser::dispatch($service->id);
    }

    public function session(string $username): array
    {
        if (! $this->ready()) {
            throw new RuntimeException('Krypton Radius is not configured.');
        }

        $body = $this->send('GET', $this->userPath('session', $username));

        return [
            'username' => $this->username($username),
            'online' => (bool) ($body['online'] ?? false),
            'status' => is_string($body['status'] ?? null) && $body['status'] !== '' ? $body['status'] : 'unknown',
            'framed_ip' => is_string($body['framed_ip'] ?? null) && $body['framed_ip'] !== '' ? $body['framed_ip'] : null,
            'session_started_at' => is_string($body['session_started_at'] ?? null) && $body['session_started_at'] !== '' ? $body['session_started_at'] : null,
        ];
    }

    public function perform(string $action, string $username): void
    {
        if (! $this->ready()) {
            throw new RuntimeException('Krypton Radius is not configured.');
        }

        if (! in_array($action, ['suspend', 'reactivate'], true)) {
            throw new RuntimeException('Unknown Radius action.');
        }

        $this->send('POST', $this->userPath($action, $username));
    }

    public function performUpsert(CustomerService $service): void
    {
        if (! $this->ready()) {
            throw new RuntimeException('Krypton Radius is not configured.');
        }

        $username = $this->username((string) $service->radius_username);
        $service->loadMissing('plan');
        $profile = $service->plan?->radius_profile_name ?: $service->plan_name;
        $body = [
            'username' => $username,
            'profile' => $profile,
            'static_ip' => $service->static_ip,
            'mac' => $service->mac,
            'nas' => $service->nas,
            'vlan' => $service->vlan,
            'customer_id' => $service->customer_id,
        ];
        $password = (string) $service->radius_password;

        if ($password !== '') {
            $body['password'] = $password;
        }

        try {
            $this->send('PUT', $this->userPath('user', $username), $body);
        } catch (Throwable $exception) {
            $message = $password !== ''
                ? str_replace($password, '[redacted]', $exception->getMessage())
                : $exception->getMessage();

            throw new RuntimeException($this->settings->redact($message), 0, $exception);
        }
    }

    public function recordFallback(?int $customerId, string $action, ?int $actorId): void
    {
        if ($customerId === null || ! in_array($action, ['suspend', 'reactivate'], true)) {
            return;
        }

        $title = __('ui.collections.krypton_'.$action);
        $recent = WorkTask::query()
            ->where('customer_id', $customerId)
            ->where('kind', 'noc_krypton')
            ->where('title', $title)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();

        if ($recent) {
            return;
        }

        $actor = $actorId ? User::query()->find($actorId) : null;
        WorkTask::query()->create([
            'kind' => 'noc_krypton',
            'title' => $title,
            'notes' => __('ui.collections.krypton_steps'),
            'status' => 'open',
            'priority' => 'high',
            'customer_id' => $customerId,
            'owner_id' => $actor instanceof User ? $actor->id : null,
            'due_at' => now()->addHour(),
        ]);
    }

    private function enqueue(string $action, string $username, ?int $customerId, ?int $actorId): void
    {
        if (! $this->ready()) {
            Log::info('radius.'.$action, ['username' => $username, 'live' => (bool) config('fibes.radius.enabled')]);

            return;
        }

        try {
            PushKryptonRadiusAction::dispatch($action, $username, $customerId, $actorId);
        } catch (Throwable) {
            $this->recordFallback($customerId, $action, $actorId);
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body = null): array
    {
        $pending = $this->http();
        $response = match ($method) {
            'POST' => $pending->asJson()->post($path, $body ?? []),
            'PUT' => $pending->asJson()->put($path, $body ?? []),
            default => $pending->get($path),
        };

        if ($response->failed()) {
            throw new RuntimeException('Krypton HTTP '.$response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->timeout(15)
            ->acceptJson()
            ->withToken((string) config('fibes.radius.api_key'));
    }

    private function baseUrl(): string
    {
        return rtrim($this->settings->runtime('krypton')['url'], '/');
    }

    private function plainPath(string $name): string
    {
        $path = (string) config('fibes.radius.paths.'.$name);

        if ($path === '' || ! str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new RuntimeException('Invalid Krypton path.');
        }

        return $path;
    }

    private function userPath(string $name, string $username): string
    {
        $template = $this->plainPath($name);

        if (! str_contains($template, '{username}')) {
            throw new RuntimeException('Invalid Krypton path.');
        }

        return str_replace('{username}', rawurlencode($this->username($username)), $template);
    }

    private function username(string $username): string
    {
        $username = trim($username);

        if (! preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $username)) {
            throw new RuntimeException('Invalid Radius username.');
        }

        return $username;
    }
}
