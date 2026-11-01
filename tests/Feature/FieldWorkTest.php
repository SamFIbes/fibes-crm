<?php

use App\Models\Area;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Job;
use App\Models\JobCheck;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Models\WorkTask;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(FoundationSeeder::class);
    Storage::fake('local');
});

function fieldCustomer(User $actor): Customer
{
    test()->actingAs($actor)->post('/customers', [
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Rami Khalil',
        'name_ar' => 'رامي خليل',
        'phones' => ['03 123 456'],
        'area_id' => Area::query()->where('name_en', 'Achrafieh')->value('id'),
        'latitude' => '33.8886300',
        'longitude' => '35.4954800',
    ])->assertRedirect();

    return Customer::query()->firstOrFail();
}

it('opens a repair job when a ticket is sent to the field', function () {
    $admin = User::factory()->superAdmin()->create();
    $tech = User::factory()->create(['role' => 'field_technician', 'name' => 'Hadi']);
    fieldCustomer($admin);
    $this->actingAs($admin)->post('/tickets', [
        'customer_id' => Customer::query()->value('id'),
        'category_id' => TicketCategory::query()->where('key', 'no_internet')->value('id'),
        'priority' => 'P1',
        'channel' => 'phone',
        'subject' => 'No light',
        'body' => 'Dark ONT.',
    ])->assertRedirect();
    $ticket = Ticket::query()->firstOrFail();

    $this->actingAs($admin)->post('/tickets/'.$ticket->id.'/escalate', [
        'assignee_id' => $tech->id,
        'reason' => 'Site visit',
        'note' => 'No power light.',
    ])->assertRedirect();

    $job = Job::query()->firstOrFail();
    expect($job->type)->toBe('repair')
        ->and($job->ticket_id)->toBe($ticket->id)
        ->and($job->number)->toBe('JB-000001')
        ->and($job->checks()->count())->toBeGreaterThan(0)
        ->and(DB::table('audit_log')->where('event', 'job.created')->exists())->toBeTrue();

    $this->actingAs($tech)->get('/dispatch')->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/tech')->assertForbidden();
});

it('warns about an overlap and lets only the assigned technician finish', function () {
    $ops = User::factory()->create(['role' => 'ops_manager']);
    $admin = User::factory()->superAdmin()->create();
    $hadi = User::factory()->create(['role' => 'field_technician', 'name' => 'Hadi']);
    $nabil = User::factory()->create(['role' => 'field_technician', 'name' => 'Nabil']);
    $customer = fieldCustomer($admin);
    $start = '2026-11-02 09:00';
    $end = '2026-11-02 11:00';

    $this->actingAs($ops)->post('/dispatch', [
        'type' => 'installation',
        'customer_id' => $customer->id,
        'technician_id' => $hadi->id,
        'required_skill' => 'fiber',
        'window_start' => $start,
        'window_end' => $end,
    ])->assertRedirect();
    $first = Job::query()->firstOrFail();

    $this->actingAs($ops)->post('/dispatch', [
        'type' => 'survey',
        'customer_id' => $customer->id,
        'window_start' => '2026-11-02 10:00',
        'window_end' => '2026-11-02 12:00',
    ])->assertRedirect();
    $second = Job::query()->where('id', '!=', $first->id)->firstOrFail();

    $this->actingAs($ops)->patch('/dispatch/jobs/'.$second->id, [
        'technician_id' => $hadi->id,
        'window_start' => '2026-11-02 10:00',
        'window_end' => '2026-11-02 12:00',
    ])->assertRedirect()->assertSessionHas('status');
    expect(session('status'))->toContain('overlap');

    $this->actingAs($nabil)->post('/tech/jobs/'.$first->id.'/status', ['status' => 'en_route'])->assertForbidden();
    $this->actingAs($hadi)->get('/tech')->assertOk()->assertInertia(fn ($page) => $page->component('Tech/Index')->has('jobs', 2));

    $this->actingAs($hadi)->post('/tech/jobs/'.$first->id.'/status', ['status' => 'done'])->assertSessionHasErrors('status');

    $first->checks()->where('required', true)->each(fn (JobCheck $check) => $check->update(['done' => true]));
    $file = UploadedFile::fake()->image('signature.png');
    $this->actingAs($hadi)->post('/tech/jobs/'.$first->id.'/signature', [
        'signed_by' => 'Rami Khalil',
        'file' => $file,
    ])->assertRedirect();

    $this->actingAs($hadi)->post('/tech/jobs/'.$first->id.'/status', [
        'status' => 'done',
    ], ['Idempotency-Key' => 'finish-1'])->assertRedirect();
    $this->actingAs($hadi)->post('/tech/jobs/'.$first->id.'/status', [
        'status' => 'failed',
        'failure_reason' => 'weather',
    ], ['Idempotency-Key' => 'finish-1'])->assertRedirect();

    expect($first->fresh()->status)->toBe('done')
        ->and(Attachment::query()->where('attachable_id', $first->id)->exists())->toBeTrue();
});

it('records a score and a follow-up call when a ticket is resolved', function () {
    Carbon::setTestNow('2026-11-02 09:00:00');
    $admin = User::factory()->superAdmin()->create();
    fieldCustomer($admin);
    $this->actingAs($admin)->post('/tickets', [
        'customer_id' => Customer::query()->value('id'),
        'category_id' => TicketCategory::query()->where('key', 'no_internet')->value('id'),
        'priority' => 'P3',
        'channel' => 'phone',
        'subject' => 'Slow',
        'body' => 'Evenings are slow.',
    ])->assertRedirect();
    $ticket = Ticket::query()->firstOrFail();
    expect($ticket->checks()->count())->toBe(1);

    $this->actingAs($admin)->patch('/tickets/'.$ticket->id.'/status', ['status' => 'resolved'])->assertRedirect();
    $token = $ticket->csat()->value('token');
    expect($token)->not->toBeNull()
        ->and(WorkTask::query()->where('kind', 'follow_up_call')->where('ticket_id', $ticket->id)->exists())->toBeTrue();

    $this->post('/csat/'.$token, ['score' => 5, 'comment' => 'Fast'])->assertRedirect();
    expect($ticket->csat()->value('score'))->toBe(5)
        ->and(DB::table('audit_log')->where('event', 'ticket.csat')->exists())->toBeTrue();

    $this->actingAs($admin)->post('/network/nodes', [
        'kind' => 'olt',
        'name' => 'Achrafieh OLT',
        'parent_id' => DB::table('network_nodes')->value('id'),
    ])->assertRedirect();
    expect(DB::table('network_nodes')->where('name', 'Achrafieh OLT')->exists())->toBeTrue();
    Carbon::setTestNow();
});
