<?php

use App\Models\Area;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(FoundationSeeder::class);
});

function openDeskTicket(User $actor, array $overrides = []): Ticket
{
    $creator = $actor->role === 'super_admin' ? $actor : User::factory()->superAdmin()->create();
    $nameEn = $overrides['name_en'] ?? 'Rami Khalil';
    $nameAr = $overrides['name_ar'] ?? 'رامي خليل';
    $subject = $overrides['subject'] ?? 'No light on the ONT';
    $body = $overrides['body'] ?? 'The ONT is dark.';
    $category = $overrides['category'] ?? 'no_internet';
    $phone = $overrides['phone'] ?? '03 123 456';

    test()->actingAs($creator)->post('/customers', [
        'type' => 'individual',
        'status' => 'active',
        'name_en' => $nameEn,
        'name_ar' => $nameAr,
        'phones' => [$phone],
        'area_id' => Area::query()->where('name_en', 'Achrafieh')->value('id'),
    ])->assertRedirect();

    $customer = Customer::query()->where('name_en', $nameEn)->firstOrFail();
    test()->actingAs($actor)->post('/tickets', [
        'customer_id' => $customer->id,
        'category_id' => TicketCategory::query()->where('key', $category)->value('id'),
        'priority' => $overrides['priority'] ?? 'P1',
        'channel' => $overrides['channel'] ?? 'phone',
        'subject' => $subject,
        'body' => $body,
    ])->assertRedirect();

    return Ticket::query()->where('subject', $subject)->latest('id')->firstOrFail();
}

it('shows the support desk inbox, reply queue and two suggested replies', function () {
    $admin = User::factory()->superAdmin()->create();
    $ticket = openDeskTicket($admin);

    $this->actingAs($admin)->get('/tickets/desk')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Tickets/Desk')
        ->has('inbox', 1)
        ->where('inbox.0.number', $ticket->number)
        ->where('inbox.0.awaiting_reply', true)
        ->has('queue', 1)
        ->where('queue.0.id', $ticket->id)
        ->has('queue.0.suggestions', 2)
        ->where('selected.id', $ticket->id)
        ->where('customer.account_no', $ticket->customer->account_no)
        ->where('customer.name_en', 'Rami Khalil')
        ->has('insights.volume', 14)
        ->where('auth.nav', fn ($nav) => in_array('support_desk', collect($nav)->all(), true))
        ->where('queue.0.suggestions', function ($suggestions) use ($ticket) {
            $rows = collect($suggestions)->values()->all();

            return count($rows) === 2
                && str_contains((string) $rows[0]['body'], $ticket->number)
                && str_contains((string) $rows[0]['body'], 'Rami Khalil')
                && $rows[0]['body'] !== $rows[1]['body'];
        }));
});

it('leaves the queue after a public reply and records first-response time', function () {
    $start = Carbon::parse('2026-11-12 09:00:00', 'Asia/Beirut');
    Carbon::setTestNow($start);
    $admin = User::factory()->superAdmin()->create();
    $ticket = openDeskTicket($admin);

    Carbon::setTestNow($start->copy()->addMinutes(12));
    $this->actingAs($admin)->post('/tickets/'.$ticket->id.'/messages', [
        'body' => 'We are checking the line now.',
        'visibility' => 'public',
    ])->assertRedirect();

    $this->actingAs($admin)->get('/tickets/desk')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('queue', 0)
        ->where('insights.totals.avg_first_response_minutes', 12)
        ->where('insights.response_times.13.responded', 1)
        ->where('insights.response_times.13.avg_minutes', 12)
        ->where('insights.volume.13.count', 1));

    Carbon::setTestNow();
});

it('keeps a ticket on the queue after an internal note', function () {
    $admin = User::factory()->superAdmin()->create();
    $ticket = openDeskTicket($admin);

    $this->actingAs($admin)->post('/tickets/'.$ticket->id.'/messages', [
        'body' => 'Internal: asked NOC to look.',
        'visibility' => 'internal',
    ])->assertRedirect();

    $this->actingAs($admin)->get('/tickets/desk')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('queue', 1)
        ->where('queue.0.id', $ticket->id));
});

it('hides other people\'s tickets from a technician and forbids opening them', function () {
    $admin = User::factory()->superAdmin()->create();
    $tech = User::factory()->create(['role' => 'field_technician', 'name' => 'Hadi']);
    $ticket = openDeskTicket($admin);

    $this->actingAs($tech)->get('/tickets/desk')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Tickets/Desk')
        ->has('inbox', 0)
        ->has('queue', 0)
        ->where('selected', null));

    $this->actingAs($tech)->get('/tickets/desk?ticket='.$ticket->id)->assertForbidden();
});

it('forbids the desk for warehouse users and guests', function () {
    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/tickets/desk')->assertForbidden();
    auth()->logout();
    $this->flushSession();
    $this->get('/tickets/desk')->assertRedirect();
    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('auth.nav', fn ($nav) => ! in_array('support_desk', collect($nav)->all(), true)));
});

it('buckets volume for the last 14 days in Asia/Beirut and excludes older tickets', function () {
    $today = Carbon::parse('2026-11-12 15:00:00', 'Asia/Beirut');
    Carbon::setTestNow($today);
    $admin = User::factory()->superAdmin()->create();
    $old = openDeskTicket($admin, [
        'name_en' => 'Lana Nassar',
        'name_ar' => 'لانا نصار',
        'phone' => '03 222 333',
        'subject' => 'Old outage',
        'body' => 'This was last month.',
    ]);
    $old->created_at = $today->copy()->subDays(15);
    $old->updated_at = $old->created_at;
    $old->save();

    openDeskTicket($admin, [
        'name_en' => 'Rami Khalil',
        'name_ar' => 'رامي خليل',
        'phone' => '03 123 456',
        'subject' => 'No light on the ONT',
        'body' => 'The ONT is dark.',
    ]);

    $this->actingAs($admin)->get('/tickets/desk')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('insights.volume', 14)
        ->where('insights.period.from', '2026-10-30')
        ->where('insights.period.to', '2026-11-12')
        ->where('insights.period.timezone', 'Asia/Beirut')
        ->where('insights.totals.opened', 1)
        ->where('insights.volume.0.count', 0)
        ->where('insights.volume.13.count', 1)
        ->where('insights.volume.13.date', '2026-11-12'));

    Carbon::setTestNow();
});

it('filters the inbox by status and search', function () {
    $admin = User::factory()->superAdmin()->create();
    $open = openDeskTicket($admin);
    $this->actingAs($admin)->patch('/tickets/'.$open->id.'/status', ['status' => 'open'])->assertRedirect();
    $resolved = openDeskTicket($admin, [
        'name_en' => 'Lana Nassar',
        'name_ar' => 'لانا نصار',
        'phone' => '03 222 333',
        'subject' => 'Billing question about a fee',
        'body' => 'Please explain this charge.',
        'category' => 'billing',
    ]);
    $this->actingAs($admin)->patch('/tickets/'.$resolved->id.'/status', ['status' => 'resolved'])->assertRedirect();

    $this->actingAs($admin)->get('/tickets/desk?status=open')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('inbox', 1)
        ->where('inbox.0.id', $open->id));

    $this->actingAs($admin)->get('/tickets/desk?status=resolved')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('inbox', 1)
        ->where('inbox.0.id', $resolved->id));

    $this->actingAs($admin)->get('/tickets/desk?q='.$resolved->number)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('inbox', 1)
        ->where('inbox.0.id', $resolved->id));

    $this->actingAs($admin)->get('/tickets/desk?q=Billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('inbox', 1)
        ->where('inbox.0.subject', 'Billing question about a fee'));
});

it('uses Arabic suggested replies when the user locale is Arabic', function () {
    $admin = User::factory()->superAdmin()->create(['locale' => 'ar']);
    $ticket = openDeskTicket($admin);

    $this->actingAs($admin)->get('/tickets/desk')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('queue.0.suggestions.0.body', fn ($body) => is_string($body)
            && str_contains($body, $ticket->number)
            && str_contains($body, 'رامي خليل')
            && ! str_contains($body, 'Hello ')));
});
