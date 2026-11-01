<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(FoundationSeeder::class);
});

function openTicket(User $actor): Ticket
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

    $customer = Customer::query()->firstOrFail();
    test()->actingAs($actor)->post('/tickets', [
        'customer_id' => $customer->id,
        'category_id' => TicketCategory::query()->where('key', 'no_internet')->value('id'),
        'priority' => 'P1',
        'channel' => 'phone',
        'subject' => 'No light on the ONT',
        'body' => 'The ONT is dark.',
    ])->assertRedirect();

    return Ticket::query()->firstOrFail();
}

it('opens a ticket with the service targets from settings', function () {
    $start = Carbon::parse('2026-11-01 09:00:00');
    Carbon::setTestNow($start);
    $admin = User::factory()->superAdmin()->create();
    $ticket = openTicket($admin);

    expect($ticket->number)->toBe('TK-000001')
        ->and($ticket->response_minutes)->toBe(15)
        ->and($ticket->resolve_minutes)->toBe(1440)
        ->and($ticket->response_due_at->equalTo($start->copy()->addMinutes(15)))->toBeTrue();

    $this->actingAs($admin)->get('/customers/'.$ticket->customer_id)->assertOk()->assertInertia(fn ($page) => $page
        ->has('tickets', 1)
        ->where('tickets.0.number', 'TK-000001'));

    Carbon::setTestNow();
});

it('pauses the clock while waiting on the customer', function () {
    $start = Carbon::parse('2026-11-01 09:00:00');
    Carbon::setTestNow($start);
    $admin = User::factory()->superAdmin()->create();
    $ticket = openTicket($admin);
    $due = $ticket->response_due_at->copy();

    Carbon::setTestNow($start->copy()->addMinutes(5));
    $this->actingAs($admin)->patch('/tickets/'.$ticket->id.'/status', ['status' => 'pending_customer'])->assertRedirect();
    Carbon::setTestNow($start->copy()->addMinutes(35));
    $this->actingAs($admin)->patch('/tickets/'.$ticket->id.'/status', ['status' => 'open'])->assertRedirect();

    expect($ticket->fresh()->response_due_at->equalTo($due->copy()->addMinutes(30)))->toBeTrue();
    Carbon::setTestNow();
});

it('flags a warning at 80 percent and a breach after the limit', function () {
    $start = Carbon::parse('2026-11-01 09:00:00');
    Carbon::setTestNow($start);
    $admin = User::factory()->superAdmin()->create();
    $ticket = openTicket($admin);

    Carbon::setTestNow($start->copy()->addMinutes(12));
    $this->artisan('tickets:sla')->assertSuccessful();
    expect($ticket->fresh()->response_warned)->toBeTrue()->and($ticket->fresh()->response_breached)->toBeFalse();

    Carbon::setTestNow($start->copy()->addMinutes(16));
    $this->artisan('tickets:sla')->assertSuccessful();
    expect($ticket->fresh()->response_breached)->toBeTrue();
    expect($admin->fresh()->notifications()->count())->toBeGreaterThan(0);
    Carbon::setTestNow();
});

it('shows a technician only the tickets handed to them', function () {
    $start = Carbon::parse('2026-11-01 09:00:00');
    Carbon::setTestNow($start);
    $admin = User::factory()->superAdmin()->create();
    $tech = User::factory()->create(['role' => 'field_technician', 'name' => 'Hadi']);
    $ticket = openTicket($admin);

    $this->actingAs($admin)->post('/tickets/'.$ticket->id.'/escalate', [
        'assignee_id' => $tech->id,
        'reason' => 'Needs a site visit',
        'note' => 'ONT has no power light.',
    ])->assertRedirect();

    $this->actingAs($tech)->get('/tickets?mine=1')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Tickets/Index')
        ->has('tickets', 1));

    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/tickets')->assertForbidden();
    expect(DB::table('audit_log')->where('event', 'ticket.escalated')->exists())->toBeTrue();
    Carbon::setTestNow();
});
