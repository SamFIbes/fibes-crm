<?php

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WorkTask;
use App\Services\Automations\AutomationDesk;
use App\Services\Automations\DeliveryMachine;
use App\Services\Automations\WebhookDesk;
use App\Services\Settings\CommercialSettings;
use App\Services\Settings\SettingsStore;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('walks every allowed and forbidden delivery step', function () {
    $machine = new DeliveryMachine;

    foreach (DeliveryMachine::ALLOWED as $from => $targets) {
        foreach (array_keys(DeliveryMachine::ALLOWED) as $to) {
            if (in_array($to, $targets, true)) {
                expect($machine->allows($from, $to))->toBeTrue();
            } else {
                expect(fn () => $machine->assert($from, $to))->toThrow(ValidationException::class);
            }
        }
    }
});

it('previews the last week without changing anything and stops a loop', function () {
    $admin = User::factory()->superAdmin()->create();
    DB::table('leads')->insert([
        'name' => 'Dry', 'phone_e164' => '+96170000021', 'status' => 'new', 'owner_id' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $before = snapshot();
    $rule = Automation::query()->where('key', 'lead_sla_breach')->where('is_current', true)->firstOrFail();
    $preview = app(AutomationDesk::class)->dryRun($rule);
    expect($preview['matches'])->toBeGreaterThan(0)
        ->and(snapshot())->toBe($before);

    $figures = app(CommercialSettings::class)->all();
    $figures['automations_loop_depth'] = 2;
    app(SettingsStore::class)->put('commercial', $figures, $admin, Request::create('/'));
    $loop = Automation::query()->create([
        'key' => 'loop', 'version' => 1, 'name_en' => 'Loop', 'name_ar' => 'حلقة', 'trigger' => 'lead.created',
        'conditions' => ['op' => 'and', 'rules' => []], 'actions' => [['type' => 'dispatch', 'trigger' => 'lead.created']],
        'enabled' => true, 'is_current' => true, 'rate_per_hour' => 30, 'created_by' => $admin->id,
    ]);
    app(AutomationDesk::class)->emit('lead.created', ['subject_type' => 'lead', 'subject_id' => 9, 'lead_id' => 9]);
    expect(AutomationRun::query()->where('status', 'blocked')->exists())->toBeTrue()
        ->and(AutomationRun::query()->where('depth', '>', 2)->exists())->toBeFalse()
        ->and($loop->fresh()->is_current)->toBeTrue();
});

it('pauses every action, keeps versions, and only messages customers who agreed', function () {
    $admin = User::factory()->superAdmin()->create();
    $figures = app(CommercialSettings::class)->all();
    $figures['automations_paused'] = true;
    app(SettingsStore::class)->put('commercial', $figures, $admin, Request::create('/'));
    $tasks = WorkTask::query()->count();
    app(AutomationDesk::class)->emit('ticket.resolved', ['subject_type' => 'ticket', 'subject_id' => 4, 'ticket_id' => 4, 'customer_id' => null]);
    expect(WorkTask::query()->count())->toBe($tasks)
        ->and(AutomationRun::query()->where('status', 'paused')->exists())->toBeTrue();

    $figures['automations_paused'] = false;
    app(SettingsStore::class)->put('commercial', $figures, $admin, Request::create('/'));
    $customer = Customer::query()->create(['account_no' => 'FB-AU-1', 'type' => 'individual', 'status' => 'active', 'name_en' => 'Nour', 'name_ar' => 'نور', 'whatsapp_consent' => true, 'whatsapp_e164' => '+96171111227']);
    app(AutomationDesk::class)->emit('ticket.resolved', ['subject_type' => 'ticket', 'subject_id' => 4, 'customer_id' => $customer->id]);
    expect(WorkTask::query()->where('title', 'Follow-up call')->exists())->toBeTrue();

    $quiet = Customer::query()->create(['account_no' => 'FB-AU-2', 'type' => 'individual', 'status' => 'active', 'name_en' => 'Quiet', 'name_ar' => 'صامت', 'whatsapp_consent' => false]);
    Automation::query()->create([
        'key' => 'wa', 'version' => 1, 'name_en' => 'WhatsApp', 'name_ar' => 'واتساب', 'trigger' => 'customer.health',
        'conditions' => ['op' => 'and', 'rules' => []], 'actions' => [['type' => 'send_whatsapp']],
        'enabled' => true, 'is_current' => true, 'rate_per_hour' => 30,
    ]);
    app(AutomationDesk::class)->emit('customer.health', ['subject_type' => 'customer', 'subject_id' => $quiet->id, 'customer_id' => $quiet->id], 0, true);
    expect(AutomationRun::query()->where('message', 'skipped_consent')->exists())->toBeTrue();
    app(AutomationDesk::class)->emit('customer.health', ['subject_type' => 'customer', 'subject_id' => $customer->id, 'customer_id' => $customer->id], 0, true);
    expect(AutomationRun::query()->where('message', 'like', '%wa.me/96171111227%')->exists())->toBeTrue();

    $current = Automation::query()->where('key', 'ticket_followup')->where('is_current', true)->firstOrFail();
    $this->actingAs($admin)->post('/automations/'.$current->id, ['enabled' => false, 'name_en' => 'Follow-up', 'name_ar' => 'متابعة'])->assertRedirect();
    expect(Automation::query()->where('key', 'ticket_followup')->where('is_current', true)->value('version'))->toBe(2)
        ->and($current->fresh()->version)->toBe(1)
        ->and(DB::table('audit_log')->where('event', 'automation.revised')->exists())->toBeTrue();
    expect(fn () => AutomationRun::query()->first()?->delete())->toThrow(RuntimeException::class);
    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/automations')->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 'auditor']))->post('/automations', [
        'name_en' => 'No', 'name_ar' => 'لا', 'trigger' => 'lead.created', 'action' => 'notify',
    ])->assertForbidden();
});

it('signs a webhook, retries, and gives up', function () {
    $secret = 'test-webhook-secret';
    $hook = WebhookEndpoint::query()->create([
        'name' => 'n8n', 'url' => 'https://hooks.example.test/fibes', 'secret' => $secret, 'events' => ['payment.received'], 'enabled' => true,
    ]);
    $admin = User::factory()->superAdmin()->create();
    $figures = app(CommercialSettings::class)->all();
    $figures['webhook_max_attempts'] = 2;
    app(SettingsStore::class)->put('commercial', $figures, $admin, Request::create('/'));
    Http::fake(['*' => Http::response('no', 500)]);
    app(AutomationDesk::class)->emit('payment.received', ['subject_type' => 'payment', 'subject_id' => 3, 'label' => 'RC-1']);
    $delivery = WebhookDelivery::query()->firstOrFail();
    expect($delivery->status)->toBe('retrying');
    app(WebhookDesk::class)->attempt($delivery->fresh());
    expect($delivery->fresh()->status)->toBe('dead')
        ->and($delivery->fresh()->last_error)->toBe('HTTP 500');
    Http::assertSent(function ($request) use ($secret) {
        $timestamp = $request->header('X-Fibes-Timestamp')[0];
        $signature = $request->header('X-Fibes-Signature')[0];

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->body(), $secret), $signature)
            && app(WebhookDesk::class)->fresh($timestamp)
            && ! str_contains($request->body(), $secret);
    });
    expect(app(WebhookDesk::class)->fresh('1'))->toBeFalse()
        ->and(DB::table('audit_log')->where('event', 'webhook.created')->where('metadata', 'like', '%'.$secret.'%')->exists())->toBeFalse()
        ->and($hook->fresh()->secret)->toBe($secret);
});

it('shows an api key once and stores only the hash', function () {
    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin)->post('/automations/keys', ['name' => 'n8n', 'days' => 30])->assertRedirect();
    $plain = session('api_key');
    expect($plain)->toStartWith('fibes_')
        ->and(DB::table('api_keys')->value('token_hash'))->toBe(hash('sha256', $plain))
        ->and(DB::table('api_keys')->value('token_hash'))->not->toBe($plain);
    $this->getJson('/api/v1/automations/ping')->assertUnauthorized();
    $this->withToken($plain)->getJson('/api/v1/automations/ping')->assertOk();
    expect(Automation::query()->where('enabled', true)->where('is_current', true)->count())->toBe(7);
});

function snapshot(): array
{
    return [
        'tasks' => DB::table('tasks')->count(),
        'deliveries' => DB::table('webhook_deliveries')->count(),
        'customers' => DB::table('customers')->count(),
        'runs' => DB::table('automation_runs')->count(),
    ];
}
