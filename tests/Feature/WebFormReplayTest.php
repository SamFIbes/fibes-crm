<?php

use App\Models\Lead;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    config([
        'fibes.webhooks.web_form_secret' => 'synthetic-form-secret',
        'fibes.webhooks.turnstile_secret' => '',
        'fibes.webhooks.web_form_require_timestamp' => false,
    ]);
});

function postWebForm(string $body, array $headers = [])
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', '/api/v1/hooks/web-form', [], [], [], $server, $body);
}

function formBody(array $overrides = []): string
{
    return json_encode(['name' => 'Synthetic Lead', 'phone' => '03 123 456', ...$overrides], JSON_THROW_ON_ERROR);
}

function bodySignature(string $body): string
{
    return hash_hmac('sha256', $body, 'synthetic-form-secret');
}

it('does not create a second lead when the same signed request is replayed', function () {
    $body = formBody();

    $first = postWebForm($body, ['X-Fibes-Signature' => bodySignature($body)])->assertCreated();
    postWebForm($body, ['X-Fibes-Signature' => bodySignature($body)])
        ->assertOk()->assertJson(['id' => $first->json('id')]);

    expect(Lead::query()->count())->toBe(1);
});

it('answers a retry with the same key before Turnstile, whose token is single-use', function () {
    config(['fibes.webhooks.turnstile_secret' => 'synthetic-turnstile']);
    Http::fakeSequence('challenges.cloudflare.com/*')
        ->push(['success' => true])
        ->push(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);
    $body = formBody(['turnstile_token' => 'one-time']);
    $headers = ['X-Fibes-Signature' => bodySignature($body), 'Idempotency-Key' => 'retry-1'];

    $first = postWebForm($body, $headers)->assertCreated();
    postWebForm($body, $headers)->assertOk()->assertJson(['id' => $first->json('id')]);

    expect(Lead::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

it('refuses a second copy while the first is still being processed', function () {
    $body = formBody();
    Cache::put('lead-web:busy-1:claim', 1, 60);

    postWebForm($body, ['X-Fibes-Signature' => bodySignature($body), 'Idempotency-Key' => 'busy-1'])
        ->assertStatus(409)->assertHeader('Retry-After', '2');

    expect(Lead::query()->count())->toBe(0);
});

it('treats a Turnstile outage as a failed captcha and lets the sender retry', function () {
    config(['fibes.webhooks.turnstile_secret' => 'synthetic-turnstile']);
    Http::fake(fn () => throw new ConnectionException('synthetic outage'));
    $body = formBody(['turnstile_token' => 't']);

    postWebForm($body, ['X-Fibes-Signature' => bodySignature($body), 'Idempotency-Key' => 'down-1'])->assertStatus(422);

    expect(Lead::query()->count())->toBe(0)
        ->and(Cache::has('lead-web:down-1:claim'))->toBeFalse()
        ->and(Cache::has('lead-web:down-1'))->toBeFalse();
});

it('accepts a fresh timestamped signature', function () {
    $body = formBody();
    $time = (string) now()->getTimestamp();

    postWebForm($body, [
        'X-Fibes-Timestamp' => $time,
        'X-Fibes-Signature' => hash_hmac('sha256', $time.'.'.$body, 'synthetic-form-secret'),
    ])->assertCreated();
});

it('refuses old, altered or mismatched timestamped requests', function () {
    $body = formBody();
    $old = (string) (now()->getTimestamp() - 301);
    $now = (string) now()->getTimestamp();

    postWebForm($body, ['X-Fibes-Timestamp' => $old, 'X-Fibes-Signature' => hash_hmac('sha256', $old.'.'.$body, 'synthetic-form-secret')])->assertUnauthorized();
    postWebForm($body, ['X-Fibes-Timestamp' => $now, 'X-Fibes-Signature' => hash_hmac('sha256', ((int) $now - 1).'.'.$body, 'synthetic-form-secret')])->assertUnauthorized();
    postWebForm($body, ['X-Fibes-Timestamp' => $now, 'X-Fibes-Signature' => bodySignature($body)])->assertUnauthorized();
    postWebForm($body, ['X-Fibes-Timestamp' => 'soon', 'X-Fibes-Signature' => bodySignature($body)])->assertUnauthorized();

    expect(Lead::query()->count())->toBe(0);
});

it('keeps body-only signatures working until a timestamp is required', function () {
    $body = formBody();

    postWebForm($body, ['X-Fibes-Signature' => bodySignature($body)])->assertCreated();

    config(['fibes.webhooks.web_form_require_timestamp' => true]);
    $other = formBody(['name' => 'Another Lead', 'phone' => '03 654 321']);
    postWebForm($other, ['X-Fibes-Signature' => bodySignature($other)])->assertUnauthorized();

    expect(Lead::query()->count())->toBe(1);
});
