<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\EscalationTier;
use App\Models\User;
use App\Models\WrapUpCode;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(FoundationSeeder::class);
});

it('finds a caller, logs the call, and saves an unknown number as a lead', function () {
    $admin = User::factory()->superAdmin()->create();
    $agent = User::factory()->create(['role' => 'cc_agent', 'name' => 'Maya']);

    $this->actingAs($admin)->post('/customers', [
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Rami Khalil',
        'name_ar' => 'رامي خليل',
        'phones' => ['03 123 456'],
        'area_id' => Area::query()->where('name_en', 'Achrafieh')->value('id'),
    ])->assertRedirect();

    $customer = Customer::query()->firstOrFail();
    $this->actingAs($agent)->get('/calls?phone=03 123 456')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Calls/Index')
        ->where('result.matched', true)
        ->where('result.customer.id', $customer->id)
        ->where('result.whatsapp_url', 'https://wa.me/9613123456'));

    $this->actingAs($agent)->post('/calls', [
        'direction' => 'inbound',
        'channel' => 'phone',
        'phone' => '03 123 456',
        'customer_id' => $customer->id,
    ])->assertSessionHasErrors('wrap_up_code_id');

    $code = WrapUpCode::query()->where('key', 'info')->firstOrFail();
    $this->actingAs($agent)->post('/calls', [
        'direction' => 'inbound',
        'channel' => 'whatsapp',
        'wrap_up_code_id' => $code->id,
        'duration_minutes' => 4,
        'notes' => 'Told them the outage is local.',
        'phone' => '03 123 456',
        'customer_id' => $customer->id,
    ])->assertRedirect();

    expect(DB::table('interactions')->where('user_id', $agent->id)->where('duration_seconds', 240)->exists())->toBeTrue()
        ->and(DB::table('audit_log')->where('event', 'call.logged')->exists())->toBeTrue();

    $this->actingAs($agent)->post('/calls/leads', [
        'name' => 'New shop',
        'phone' => '71 555 333',
        'notes' => 'Asked about fiber.',
    ])->assertRedirect();

    expect(DB::table('leads')->where('phone_e164', '+96171555333')->exists())->toBeTrue()
        ->and(DB::table('audit_log')->where('event', 'lead.created')->exists())->toBeTrue();

    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/calls')->assertForbidden();
    $this->actingAs($agent)->get('/calls/board')->assertForbidden();
});

it('accepts a signed screen-pop and shows the live board to a supervisor', function () {
    config(['fibes.telephony.secret' => 'telephony-test-secret']);
    $admin = User::factory()->superAdmin()->create();
    $agent = User::factory()->create(['role' => 'cc_agent', 'extension' => '101', 'name' => 'Maya']);
    $supervisor = User::factory()->create(['role' => 'cc_supervisor']);

    $this->actingAs($admin)->post('/customers', [
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Rami Khalil',
        'name_ar' => 'رامي خليل',
        'phones' => ['03 123 456'],
        'area_id' => Area::query()->where('name_en', 'Achrafieh')->value('id'),
    ])->assertRedirect();

    $payload = json_encode(['caller' => '03123456', 'extension' => '101', 'direction' => 'inbound'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'telephony-test-secret');
    $this->call('POST', '/api/telephony/incoming', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FIBES_TIMESTAMP' => $timestamp,
        'HTTP_X_FIBES_SIGNATURE' => 'nope',
    ], $payload)->assertUnauthorized();

    $this->call('POST', '/api/telephony/incoming', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_FIBES_TIMESTAMP' => $timestamp,
        'HTTP_X_FIBES_SIGNATURE' => $signature,
    ], $payload)->assertOk()->assertJsonPath('matched', true);

    $this->actingAs($agent)->get('/calls')->assertOk()->assertInertia(fn ($page) => $page
        ->where('result.matched', true)
        ->where('phone', '+9613123456'));

    config(['fibes.telephony.secret' => '']);
    $this->call('POST', '/api/telephony/incoming', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $payload)->assertStatus(503);

    $this->actingAs($agent)->post('/calls/duty', ['on_duty' => true])->assertRedirect();
    expect(EscalationTier::query()->where('level', 1)->firstOrFail()->members()->where('users.id', $agent->id)->wherePivot('on_duty', true)->exists())->toBeTrue();

    $this->actingAs($supervisor)->get('/calls/board')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Calls/Board')
        ->where('tiers.0.agents.0.name', 'Maya')
        ->where('tiers.0.agents.0.on_duty', true));
});
