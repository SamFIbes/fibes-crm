<?php

use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Integrations\ProviderTopology;
use Database\Seeders\RoleAndPermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    config([
        'fibes.webhooks.web_form_secret' => '',
        'fibes.webhooks.meta_verify_token' => '',
        'fibes.webhooks.meta_app_secret' => '',
        'fibes.webhooks.turnstile_secret' => '',
        'fibes.whish.enabled' => false,
        'fibes.radius.enabled' => false,
        'fibes.coverage.fiberdesk' => false,
        'fibes.coverage.tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'fibes.documents.driver' => 'html',
        'fibes.documents.chromium' => '',
        'fibes.documents.sms' => 'log',
        'fibes.attachments.driver' => 'null',
        'fibes.telephony.secret' => '',
        'fibes.observability.dsn' => '',
        'mail.default' => 'array',
    ]);
});

it('reads connected services and marks stand-ins as sample', function () {
    $rows = app(ProviderTopology::class)->providers();
    $byKey = collect($rows)->keyBy('key');

    expect($byKey->keys()->all())->toBe([
        'web_form', 'meta', 'turnstile', 'whish', 'omt', 'bank', 'card', 'cheque', 'cash',
        'whatsapp', 'email', 'sms', 'telephony', 'krypton', 'fiberdesk', 'pdf', 'filescan',
        'maps', 'webhooks', 'sentry',
    ])
        ->and($byKey['krypton']['status'])->toBe('error')
        ->and($byKey['krypton']['sample'])->toBeFalse()
        ->and($byKey['omt']['status'])->toBe('active')
        ->and($byKey['omt']['sample'])->toBeFalse()
        ->and($byKey['web_form']['status'])->toBe('recent')
        ->and($byKey['web_form']['sample'])->toBeTrue()
        ->and($byKey['filescan']['status'])->toBe('active');

    config(['fibes.attachments.driver' => null]);
    $builtIn = collect(app(ProviderTopology::class)->providers())->firstWhere('key', 'filescan');
    expect($builtIn['status'])->toBe('active')->and($builtIn['sample'])->toBeFalse();

    expect($rows)->each(function ($row) {
        $row->status->toBeIn(['active', 'recent', 'error']);
        $row->sample->toBeBool();
    });

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach ($rows as $row) {
            expect(__('ui.topology.p_'.$row['key']))->not->toBe('ui.topology.p_'.$row['key']);
        }
    }
});

it('promotes a connection when its real switch is on', function () {
    config([
        'fibes.webhooks.web_form_secret' => 'form-secret',
        'fibes.webhooks.meta_verify_token' => 'meta-token',
        'fibes.radius.enabled' => true,
        'fibes.whish.enabled' => true,
        'mail.default' => 'smtp',
    ]);

    $byKey = collect(app(ProviderTopology::class)->providers())->keyBy('key');

    expect($byKey['web_form']['status'])->toBe('active')
        ->and($byKey['web_form']['sample'])->toBeFalse()
        ->and($byKey['meta']['status'])->toBe('error')
        ->and($byKey['krypton']['status'])->toBe('active')
        ->and($byKey['whish']['status'])->toBe('active')
        ->and($byKey['email']['status'])->toBe('active');
});

it('marks outbound webhooks from the latest delivery', function () {
    $hook = WebhookEndpoint::query()->create([
        'name' => 'Billing',
        'url' => 'https://example.test/hook',
        'secret' => 'test-secret',
        'events' => ['lead.created'],
    ]);
    WebhookDelivery::query()->create([
        'webhook_id' => $hook->id,
        'event' => 'lead.created',
        'payload' => ['ok' => true],
        'status' => 'delivered',
        'attempts' => 1,
    ]);

    $row = collect(app(ProviderTopology::class)->providers())->firstWhere('key', 'webhooks');
    expect($row['status'])->toBe('active')->and($row['sample'])->toBeFalse();

    WebhookDelivery::query()->create([
        'webhook_id' => $hook->id,
        'event' => 'lead.created',
        'payload' => ['ok' => false],
        'status' => 'dead',
        'attempts' => 2,
        'last_error' => 'timeout',
    ]);

    $row = collect(app(ProviderTopology::class)->providers())->firstWhere('key', 'webhooks');
    expect($row['status'])->toBe('error')->and($row['sample'])->toBeFalse();
});

it('shows the topology to someone who can see settings and hides it from a technician', function () {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->get('/topology')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Topology/Index')
        ->has('providers', 20));

    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.nav', fn ($nav) => in_array('topology', collect($nav)->all(), true)));

    $tech = User::factory()->create(['role' => 'field_technician']);

    $this->actingAs($tech)->get('/topology')->assertForbidden();
    $this->actingAs($tech)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.nav', fn ($nav) => ! in_array('topology', collect($nav)->all(), true)));
});
