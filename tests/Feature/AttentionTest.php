<?php

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Attention\AttentionDesk;
use Database\Seeders\PreviewSampleSeeder;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('counts a real breached ticket and hides that queue from warehouse', function () {
    $admin = User::factory()->superAdmin()->create();
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $ticket = attentionTicket($admin, now()->subHour(), null);

    $queues = collect(app(AttentionDesk::class)->for($admin)['queues']);
    $breached = $queues->firstWhere('key', 'tickets_breached');

    expect($breached)->not->toBeNull()
        ->and($breached['count'])->toBe(1)
        ->and($breached['items'][0]['number'])->toBe($ticket->number)
        ->and(collect(app(AttentionDesk::class)->for($warehouse)['queues'])->pluck('key'))->not->toContain('tickets_breached');
});

it('orders the support queue with the breached clock first', function () {
    $admin = User::factory()->superAdmin()->create();
    attentionTicket($admin, now()->addHour(), $admin->id, 'T-OK');
    attentionTicket($admin, now()->subHour(), null, 'T-LATE');

    $this->actingAs($admin)->get('/tickets')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Tickets/Index')
        ->where('filters.queue', 'attention')
        ->where('tickets.0.number', 'T-LATE')
        ->where('tickets.0.sla_configured', true)
        ->has('tickets.0.sla.response'));
});

it('shares the attention queues on the dashboard', function () {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('attention.queues'));
});

it('does not cache customer pages in the service worker', function () {
    $script = file_get_contents(public_path('sw.js'));
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

    expect($script)->toContain('/build/')
        ->and($script)->not->toContain("'/tech'")
        ->and($manifest['start_url'])->toBe('/dashboard')
        ->and($manifest['display'])->toBe('standalone')
        ->and(collect($manifest['icons'])->pluck('sizes')->all())->toContain('192x192', '512x512');
});

it('refuses the preview sample seeder in production', function () {
    $this->app['env'] = 'production';

    expect(fn () => (new PreviewSampleSeeder)->run())->toThrow(RuntimeException::class);
});

function attentionTicket(User $owner, $due, ?int $assignee, string $number = 'T-ATTN'): Ticket
{
    $customer = Customer::query()->create([
        'account_no' => 'FB-'.$number,
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Attention',
        'name_ar' => 'انتباه',
        'owner_id' => $owner->id,
    ]);
    $category = TicketCategory::query()->firstOrCreate(
        ['key' => 'attn'],
        ['name_en' => 'Attention', 'name_ar' => 'انتباه', 'is_active' => true, 'sort' => 1],
    );

    return Ticket::query()->create([
        'number' => $number,
        'customer_id' => $customer->id,
        'category_id' => $category->id,
        'priority' => 'P2',
        'channel' => 'phone',
        'status' => 'open',
        'subject' => 'Line down',
        'assignee_id' => $assignee,
        'owner_id' => $owner->id,
        'response_minutes' => 15,
        'resolve_minutes' => 240,
        'response_due_at' => $due,
        'resolve_due_at' => $due->copy()->addHours(4),
        'response_breached' => $due->isPast(),
    ]);
}
