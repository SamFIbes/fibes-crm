<?php

use App\Models\Customer;
use App\Models\Lead;
use App\Models\OnboardingCard;
use App\Models\OnboardingStage;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    config()->set('fibes.onboarding.stale_days', 5);
    config()->set('fibes.onboarding.backfill_days', 30);
    config()->set('fibes.onboarding.column_page', 20);
});

it('requires board viewing permission', function (): void {
    $technician = User::factory()->create(['role' => 'field_technician']);
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($technician)->get('/onboarding')->assertForbidden();
    $this->actingAs($admin)->get('/onboarding')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Onboarding/Index'));
});

it('syncs eligible leads without importing new or disqualified leads', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $handed = onboardingLead(['name' => 'Rami', 'status' => 'new', 'handed_to_sales_at' => now(), 'owner_id' => $admin->id]);
    $qualified = onboardingLead(['name' => 'Nour', 'status' => 'qualified', 'owner_id' => $admin->id]);
    $new = onboardingLead(['name' => 'Maya', 'status' => 'new', 'handed_to_sales_at' => null]);
    $disqualified = onboardingLead(['name' => 'Sami', 'status' => 'disqualified', 'handed_to_sales_at' => now()]);

    $this->actingAs($admin)->postJson('/onboarding/sync')->assertOk();

    $this->assertDatabaseHas('onboarding_cards', ['lead_id' => $handed->id, 'salesperson_name' => $admin->name]);
    $this->assertDatabaseHas('onboarding_cards', ['lead_id' => $qualified->id]);
    $this->assertDatabaseMissing('onboarding_cards', ['lead_id' => $new->id]);
    $this->assertDatabaseMissing('onboarding_cards', ['lead_id' => $disqualified->id]);
});

it('creates lead cards idempotently and links converted customers', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $lead = onboardingLead(['owner_id' => $admin->id]);
    $customer = onboardingCustomer(['owner_id' => $admin->id]);

    $this->actingAs($admin)->post("/leads/{$lead->id}/onboarding-card")->assertRedirect();
    $this->actingAs($admin)->post("/leads/{$lead->id}/onboarding-card")->assertRedirect();
    $lead->update(['status' => 'converted', 'converted_customer_id' => $customer->id]);
    $this->actingAs($admin)->post("/customers/{$customer->id}/onboarding-card")->assertRedirect();

    expect(OnboardingCard::query()->count())->toBe(1);
    $this->assertDatabaseHas('onboarding_cards', ['lead_id' => $lead->id, 'customer_id' => $customer->id]);
});

it('moves cards and records events and audits', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $card = onboardingCard(['assigned_to' => $admin->id, 'last_activity_at' => now()->subDays(3), 'stage_entered_at' => now()->subDays(3)]);

    $this->actingAs($admin)->postJson("/onboarding/cards/{$card->id}/move", ['stage' => 'contacted'])
        ->assertOk()->assertJsonPath('card.stage_key', 'contacted');
    $this->assertDatabaseHas('onboarding_events', ['card_id' => $card->id, 'kind' => 'moved', 'from_stage' => 'new_lead', 'to_stage' => 'contacted']);
    $this->assertDatabaseHas('audit_log', ['event' => 'onboarding.moved', 'actor_id' => $admin->id]);
    expect($card->fresh()->last_activity_at->greaterThan(now()->subMinute()))->toBeTrue();
    expect($card->fresh()->stage_entered_at->greaterThan(now()->subMinute()))->toBeTrue();

    $this->actingAs($admin)->postJson("/onboarding/cards/{$card->id}/move", ['stage' => 'missing'])->assertUnprocessable();
    $technician = User::factory()->create(['role' => 'field_technician']);
    $this->actingAs($technician)->postJson("/onboarding/cards/{$card->id}/move", ['stage' => 'active'])->assertForbidden();
});

it('counts stale and due cards once and excludes terminal cards', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $stale = onboardingCard(['assigned_to' => $admin->id, 'last_activity_at' => now()->subDays(6)]);
    $due = onboardingCard(['last_activity_at' => now()]);
    $future = onboardingCard(['last_activity_at' => now()]);
    $terminal = onboardingCard(['stage_key' => 'active', 'assigned_to' => $admin->id, 'last_activity_at' => now()->subDays(10)]);

    $stale->followup()->create(['due_on' => now()->toDateString(), 'set_at' => now()]);
    $due->followup()->create(['due_on' => now()->toDateString(), 'set_at' => now()]);
    $future->followup()->create(['due_on' => now()->addDay()->toDateString(), 'set_at' => now()]);
    $terminal->followup()->create(['due_on' => now()->toDateString(), 'set_at' => now()]);

    $this->actingAs($admin)->getJson('/onboarding/attention')
        ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('mine', 1);
    $this->actingAs($admin)->getJson('/onboarding/board/columns/active')
        ->assertOk()->assertJsonPath('items.0.stale', false)->assertJsonPath('items.0.followup_due', false);
    $this->actingAs($admin)->getJson('/onboarding/board/columns/new_lead?stale=1')
        ->assertOk()->assertJsonCount(2, 'items');
});

it('sets and snoozes followups without changing activity', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $card = onboardingCard(['last_activity_at' => now()->subDays(6)]);
    $activity = $card->last_activity_at->format('Y-m-d H:i:s');

    $this->actingAs($admin)->putJson("/onboarding/cards/{$card->id}/followup", [
        'due_on' => now()->toDateString(), 'note' => 'Call Rami',
    ])->assertOk();

    foreach ([1, 3] as $days) {
        $this->actingAs($admin)->putJson("/onboarding/cards/{$card->id}/followup", ['snooze_days' => $days])->assertOk();
        expect($card->fresh()->followup?->due_on?->format('Y-m-d'))->toBe(now()->addDays($days)->toDateString())
            ->and($card->fresh()->followup?->note)->toBe('Call Rami');
    }

    $this->actingAs($admin)->putJson("/onboarding/cards/{$card->id}/followup", ['snooze_days' => 2])->assertUnprocessable();
    expect($card->fresh()->last_activity_at->format('Y-m-d H:i:s'))->toBe($activity);
});

it('records notes and assignments in history', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $card = onboardingCard();

    $this->actingAs($admin)->postJson("/onboarding/cards/{$card->id}/notes", ['body' => 'Called Nour'])->assertOk();
    $this->actingAs($admin)->postJson("/onboarding/cards/{$card->id}/assign", ['assigned_to' => $agent->id])->assertOk();
    $this->assertDatabaseHas('onboarding_events', ['card_id' => $card->id, 'kind' => 'assigned']);
    $this->assertDatabaseHas('onboarding_cards', ['id' => $card->id, 'assigned_to' => $agent->id]);
    $this->actingAs($admin)->getJson("/onboarding/cards/{$card->id}")
        ->assertOk()->assertJsonFragment(['kind' => 'note', 'detail' => 'Called Nour']);
});

it('filters columns and meta counts consistently', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $other = User::factory()->create(['role' => 'sales_agent']);
    onboardingCard(['assigned_to' => $admin->id, 'last_activity_at' => now()->subDays(6)]);
    onboardingCard(['assigned_to' => $other->id, 'last_activity_at' => now()->subDays(6)]);
    onboardingCard(['assigned_to' => $admin->id, 'last_activity_at' => now()]);

    $this->actingAs($admin)->getJson('/onboarding/board/columns/new_lead?stale=1&assignee=me')
        ->assertOk()->assertJsonCount(1, 'items');
    $response = $this->actingAs($admin)->getJson('/onboarding/board/meta?stale=1&assignee=me')->assertOk();
    $stage = collect($response->json('stages'))->firstWhere('key', 'new_lead');
    expect($stage['count'])->toBe(1);
});

it('paginates each column by descending id', function (): void {
    $admin = User::factory()->superAdmin()->create();

    for ($i = 0; $i < 21; $i++) {
        onboardingCard();
    }

    $first = $this->actingAs($admin)->getJson('/onboarding/board/columns/new_lead')
        ->assertOk()->assertJsonCount(20, 'items')->assertJsonPath('has_more', true);
    $cursor = $first->json('next_cursor');
    $second = $this->actingAs($admin)->getJson('/onboarding/board/columns/new_lead?after='.$cursor)
        ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('has_more', false);

    expect($second->json('items.0.id'))->toBeLessThan($cursor);
});

it('allows managers to update settings but not delete occupied stages', function (): void {
    $manager = User::factory()->create(['role' => 'sales_manager']);
    $technician = User::factory()->create(['role' => 'field_technician']);
    onboardingCard();

    $stages = OnboardingStage::query()->orderBy('position')->get([
        'key', 'name_en', 'name_ar', 'position', 'task_title_en', 'task_title_ar', 'is_terminal',
    ])->toArray();
    $stages[0]['name_en'] = 'Incoming';

    $this->actingAs($manager)->putJson('/onboarding/settings', ['stale_days' => 7, 'stages' => $stages])
        ->assertOk()->assertJsonPath('stale_days', 7);
    $this->assertDatabaseHas('onboarding_stages', ['key' => 'new_lead', 'name_en' => 'Incoming']);

    $withoutFirst = array_values(array_filter($stages, fn (array $stage) => $stage['key'] !== 'new_lead'));
    $this->actingAs($manager)->putJson('/onboarding/settings', ['stages' => $withoutFirst])->assertUnprocessable();
    $this->actingAs($technician)->getJson('/onboarding/settings')->assertForbidden();
});

it('redirects unauthenticated one-click requests to login', function (): void {
    $lead = onboardingLead();

    $this->post("/leads/{$lead->id}/onboarding-card")->assertRedirect();
});

it('limits own-scoped agents to visible cards', function (): void {
    $first = User::factory()->create(['role' => 'sales_agent']);
    $second = User::factory()->create(['role' => 'sales_agent']);
    $manager = User::factory()->create(['role' => 'sales_manager']);
    $firstCard = onboardingCard(['assigned_to' => $first->id, 'salesperson_id' => $first->id]);
    $secondCard = onboardingCard(['assigned_to' => $second->id, 'salesperson_id' => $second->id]);

    $this->actingAs($first)->getJson("/onboarding/cards/{$firstCard->id}")->assertOk();
    $this->actingAs($first)->getJson("/onboarding/cards/{$secondCard->id}")->assertNotFound();
    $this->actingAs($manager)->getJson('/onboarding/board/columns/new_lead')->assertJsonCount(2, 'items');
});

it('syncs eligible intake from the console', function (): void {
    $lead = onboardingLead(['status' => 'qualified']);

    $this->artisan('onboarding:sync')->assertExitCode(0);
    $this->assertDatabaseHas('onboarding_cards', ['lead_id' => $lead->id]);
});

function onboardingLead(array $attributes = []): Lead
{
    static $phones = 0;
    $phones++;

    return Lead::query()->create(array_merge([
        'name' => 'Rami',
        'phone_e164' => '+9613'.str_pad((string) $phones, 6, '0', STR_PAD_LEFT),
        'status' => 'new',
        'source' => 'manual',
        'queue' => 'sales',
    ], $attributes));
}

function onboardingCustomer(array $attributes = []): Customer
{
    return Customer::query()->create(array_merge([
        'account_no' => 'C-'.str_pad((string) (Customer::query()->count() + 1), 6, '0', STR_PAD_LEFT),
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Nour',
        'name_ar' => 'نور',
    ], $attributes));
}

function onboardingCard(array $attributes = []): OnboardingCard
{
    return OnboardingCard::query()->create(array_merge([
        'title' => 'Rami',
        'source_kind' => 'manual',
        'source_id' => null,
        'stage_key' => 'new_lead',
        'stage_entered_at' => now(),
        'last_activity_at' => now(),
    ], $attributes));
}
