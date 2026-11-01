<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\EscalationTier;
use App\Models\Incident;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(FoundationSeeder::class);
});

function escalationTicket(User $actor): Ticket
{
    $admin = User::factory()->superAdmin()->create();
    test()->actingAs($admin)->post('/customers', [
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Rami Khalil',
        'name_ar' => 'رامي خليل',
        'phones' => ['03 123 456'],
        'area_id' => Area::query()->where('name_en', 'Achrafieh')->value('id'),
    ])->assertRedirect();

    test()->actingAs($actor)->post('/tickets', [
        'customer_id' => Customer::query()->firstOrFail()->id,
        'category_id' => TicketCategory::query()->where('key', 'no_internet')->value('id'),
        'priority' => 'P1',
        'channel' => 'phone',
        'subject' => 'No light on the ONT',
        'body' => 'The ONT is dark.',
    ])->assertRedirect();

    return Ticket::query()->firstOrFail();
}

it('moves a ticket up and back down the ladder and keeps the history', function () {
    $admin = User::factory()->superAdmin()->create();
    $noc = User::factory()->create(['role' => 'noc_engineer', 'name' => 'Nour']);
    $tier = EscalationTier::query()->where('level', 2)->firstOrFail();
    $this->actingAs($admin)->put('/settings/tiers/'.$tier->id, [
        'name_en' => $tier->name_en,
        'name_ar' => $tier->name_ar,
        'team_id' => $tier->team_id,
        'members' => [['user_id' => $noc->id, 'on_duty' => true]],
    ])->assertRedirect();

    $ticket = escalationTicket($admin);
    expect($ticket->fresh()->tier?->level)->toBe(1);

    $this->actingAs($admin)->post('/tickets/'.$ticket->id.'/escalate', [
        'direction' => 'escalate',
        'reason' => 'Needs the network team',
        'note' => 'LOS on the ONT.',
    ])->assertRedirect();

    $ticket->refresh();
    expect($ticket->escalation_tier_id)->toBe($tier->id)
        ->and($ticket->assignee_id)->toBe($noc->id)
        ->and($ticket->status)->toBe('escalated')
        ->and($noc->fresh()->notifications()->count())->toBeGreaterThan(0);

    $this->actingAs($admin)->get('/tickets/'.$ticket->id)->assertOk()->assertInertia(fn ($page) => $page
        ->where('ticket.timeline', fn ($timeline) => collect($timeline)->contains(fn ($item) => ($item['kind'] ?? '') === 'escalation')));

    $this->actingAs($admin)->post('/tickets/'.$ticket->id.'/escalate', [
        'direction' => 'deescalate',
        'reason' => 'Back to the call center',
        'note' => 'The light is back.',
    ])->assertRedirect();

    expect($ticket->fresh()->tier?->level)->toBe(1)
        ->and(DB::table('audit_log')->where('event', 'ticket.deescalated')->exists())->toBeTrue()
        ->and(DB::table('ticket_escalations')->count())->toBe(2);
});

it('auto escalates only when the configured rule says so', function () {
    $start = Carbon::parse('2026-11-01 09:00:00');
    Carbon::setTestNow($start);
    $admin = User::factory()->superAdmin()->create();
    $ticket = escalationTicket($admin);

    Carbon::setTestNow($start->copy()->addMinutes(12));
    $this->artisan('tickets:sla')->assertSuccessful();
    expect($ticket->fresh()->tier?->level)->toBe(1)->and($ticket->fresh()->response_warned)->toBeTrue();

    Carbon::setTestNow($start->copy()->addMinutes(16));
    $this->artisan('tickets:sla')->assertSuccessful();
    expect($ticket->fresh()->tier?->level)->toBe(2)
        ->and($ticket->fresh()->response_breached)->toBeTrue()
        ->and(DB::table('ticket_escalations')->where('automatic', true)->count())->toBe(1);

    $this->artisan('tickets:sla')->assertSuccessful();
    expect(DB::table('ticket_escalations')->where('automatic', true)->count())->toBe(1);
    Carbon::setTestNow();
});

it('links matching tickets to an outage and closes them with a customer note', function () {
    $admin = User::factory()->superAdmin()->create();
    $agent = User::factory()->create(['role' => 'cc_agent']);
    $ticket = escalationTicket($admin);
    $areaId = Area::query()->where('name_en', 'Achrafieh')->value('id');

    $this->actingAs($agent)->post('/incidents', ['title' => 'Fiber cut'])->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/incidents')->assertForbidden();

    $this->actingAs($admin)->post('/incidents', [
        'title' => 'Fiber cut in Achrafieh',
        'area_id' => $areaId,
        'network_element' => 'FAT-12',
    ])->assertRedirect();

    $incident = Incident::query()->firstOrFail();
    expect($incident->number)->toBe('INC-000001');

    $this->actingAs($admin)->post('/incidents/'.$incident->id.'/link-matching')->assertRedirect();
    expect($ticket->fresh()->incident_id)->toBe($incident->id);

    $this->actingAs($agent)->get('/customers/'.$ticket->customer_id)->assertOk()->assertInertia(fn ($page) => $page
        ->where('incidents.0.number', 'INC-000001'));

    $this->actingAs($admin)->post('/incidents/'.$incident->id.'/resolve', [
        'mode' => 'resolve',
        'note' => 'The fiber is spliced. Service is back.',
    ])->assertRedirect();

    expect($incident->fresh()->status)->toBe('resolved')
        ->and($ticket->fresh()->status)->toBe('resolved')
        ->and(TicketMessage::query()->where('ticket_id', $ticket->id)->where('visibility', 'public')->where('body', 'like', '%spliced%')->exists())->toBeTrue()
        ->and(DB::table('audit_log')->where('event', 'incident.resolved')->exists())->toBeTrue();
});
