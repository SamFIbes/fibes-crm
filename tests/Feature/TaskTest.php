<?php

use App\Models\Customer;
use App\Models\Holiday;
use App\Models\Interaction;
use App\Models\Job;
use App\Models\Lead;
use App\Models\TaskRule;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkTask;
use App\Notifications\WorkspaceNotification;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

function taskCustomer(): Customer
{
    $admin = User::query()->where('role', 'super_admin')->first()
        ?? User::factory()->superAdmin()->create();

    test()->actingAs($admin)->post('/customers', [
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Rami Khalil',
        'name_ar' => 'رامي خليل',
        'phones' => ['71 555 010'],
    ])->assertRedirect();

    return Customer::query()->firstOrFail();
}

it('hides tasks from a role that was not granted them', function () {
    $warehouse = User::factory()->create(['role' => 'warehouse']);

    $this->actingAs($warehouse)->get('/tasks')->assertForbidden();
    $this->actingAs($warehouse)->post('/tasks', ['title' => 'Nope'])->assertForbidden();
});

it('links a task to a customer, a job, and a lead, and records the change', function () {
    $admin = User::factory()->superAdmin()->create();
    $customer = taskCustomer();
    $lead = Lead::query()->create([
        'name' => 'Nour',
        'phone_e164' => '+9613000001',
        'status' => 'new',
        'owner_id' => $admin->id,
    ]);
    $job = Job::query()->create([
        'number' => 'JB-900010',
        'type' => 'repair',
        'status' => 'scheduled',
        'customer_id' => $customer->id,
        'owner_id' => $admin->id,
        'window_start' => now()->setTime(9, 0),
        'window_end' => now()->setTime(11, 0),
    ]);

    $this->actingAs($admin)->post('/tasks', [
        'title' => 'Call Rami',
        'customer_id' => $customer->id,
        'job_id' => $job->id,
        'lead_id' => $lead->id,
        'priority' => 'high',
        'due_at' => now()->addDay()->format('Y-m-d H:i'),
        'remind' => true,
    ])->assertRedirect();

    $task = WorkTask::query()->where('title', 'Call Rami')->firstOrFail();
    expect($task->customer_id)->toBe($customer->id)
        ->and($task->job_id)->toBe($job->id)
        ->and($task->lead_id)->toBe($lead->id)
        ->and($task->assignee_id)->toBe($admin->id)
        ->and($task->remind_at)->not->toBeNull()
        ->and($task->priority)->toBe('high');
    expect(DB::table('audit_log')->where('event', 'task.created')->exists())->toBeTrue();

    $this->actingAs($admin)->patch('/tasks/'.$task->id, [
        'due_at' => now()->addDays(2)->format('Y-m-d H:i'),
    ])->assertRedirect();
    expect(DB::table('audit_log')->where('event', 'task.rescheduled')->exists())->toBeTrue();

    $this->actingAs($admin)->post('/tasks/'.$task->id.'/done')->assertRedirect();
    expect($task->fresh()->status)->toBe('done');
    expect(DB::table('audit_log')->where('event', 'task.completed')->exists())->toBeTrue();
});

it('keeps an own-scope agent on their own tasks', function () {
    $owner = User::factory()->create(['role' => 'cc_agent', 'name' => 'Maya']);
    $other = User::factory()->create(['role' => 'cc_agent', 'name' => 'Rana']);

    $this->actingAs($owner)->post('/tasks', ['title' => 'Maya only'])->assertRedirect();
    $task = WorkTask::query()->where('title', 'Maya only')->firstOrFail();

    $this->actingAs($other)->get('/tasks?scope=team')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.scope', 'mine')
        ->where('tasks', []));
    $this->actingAs($other)->patch('/tasks/'.$task->id, [
        'due_at' => now()->addDay()->format('Y-m-d H:i'),
    ])->assertForbidden();
});

it('shows teammates to each other and the whole company only on everyone', function () {
    $one = User::factory()->create(['role' => 'support_agent', 'name' => 'Hadi']);
    $two = User::factory()->create(['role' => 'support_agent', 'name' => 'Lina']);
    $outsider = User::factory()->create(['role' => 'support_agent', 'name' => 'Karim']);
    $admin = User::factory()->superAdmin()->create();
    $desk = Team::query()->create(['key' => 'desk', 'name_en' => 'Desk', 'name_ar' => 'المكتب']);
    $other = Team::query()->create(['key' => 'field', 'name_en' => 'Field', 'name_ar' => 'الميدان']);
    $one->teams()->attach($desk);
    $two->teams()->attach($desk);
    $outsider->teams()->attach($other);

    $this->actingAs($one)->post('/tasks', ['title' => 'Desk follow-up'])->assertRedirect();

    $titles = function (User $viewer, string $scope): array {
        $titles = [];
        test()->actingAs($viewer)->get('/tasks?scope='.$scope)->assertOk()->assertInertia(function (Assert $page) use (&$titles) {
            $titles = collect($page->toArray()['props']['tasks'])->pluck('title')->all();

            return $page;
        });

        return $titles;
    };

    expect($titles($two, 'team'))->toContain('Desk follow-up')
        ->and($titles($outsider, 'team'))->not->toContain('Desk follow-up')
        ->and($titles($admin, 'team'))->not->toContain('Desk follow-up')
        ->and($titles($admin, 'all'))->toContain('Desk follow-up');
});

it('sends a reminder once and flags an overdue task once', function () {
    $admin = User::factory()->superAdmin()->create();
    Notification::fake();

    $soon = WorkTask::query()->create([
        'kind' => 'task',
        'title' => 'Remind me',
        'status' => 'open',
        'priority' => 'normal',
        'assignee_id' => $admin->id,
        'due_at' => now()->addHour(),
        'remind_at' => now()->subMinute(),
    ]);
    $late = WorkTask::query()->create([
        'kind' => 'task',
        'title' => 'Already late',
        'status' => 'open',
        'priority' => 'urgent',
        'assignee_id' => $admin->id,
        'due_at' => now()->subHour(),
    ]);

    $this->artisan('tasks:remind')->assertSuccessful();
    Notification::assertSentTo($admin, WorkspaceNotification::class);
    expect($soon->fresh()->reminded)->toBeTrue()
        ->and($late->fresh()->overdue_notified)->toBeTrue();

    Notification::fake();
    $this->artisan('tasks:remind')->assertSuccessful();
    Notification::assertNothingSent();
});

it('creates one copy of a daily task and does not duplicate it', function () {
    $admin = User::factory()->superAdmin()->create();
    $this->travelTo('2026-11-02 12:00:00');

    $this->actingAs($admin)->post('/task-rules', [
        'title' => 'Open the office',
        'frequency' => 'daily',
        'time_of_day' => '09:00',
    ])->assertRedirect();

    $rule = TaskRule::query()->firstOrFail();
    expect($rule->next_run_at?->timezone((string) config('app.timezone'))->format('Y-m-d H:i'))->toBe('2026-11-03 09:00');
    expect(DB::table('audit_log')->where('event', 'task_rule.created')->exists())->toBeTrue();

    $rule->next_run_at = now()->subMinute();
    $rule->save();

    $this->artisan('tasks:generate')->assertSuccessful();
    $this->artisan('tasks:generate')->assertSuccessful();

    expect(WorkTask::query()->where('rule_id', $rule->id)->count())->toBe(1)
        ->and($rule->fresh()->next_run_at?->greaterThan(now()))->toBeTrue();
    expect(DB::table('audit_log')->where('event', 'task.created')->where('metadata->source', 'recurring')->exists())->toBeTrue();
});

it('puts tasks, field jobs, callbacks, and holidays on the calendar and checks permission before a drag', function () {
    $admin = User::factory()->superAdmin()->create();
    $tech = User::factory()->create(['role' => 'field_technician']);
    $customer = taskCustomer();
    $job = Job::query()->create([
        'number' => 'JB-900011',
        'type' => 'repair',
        'status' => 'scheduled',
        'customer_id' => $customer->id,
        'technician_id' => $tech->id,
        'owner_id' => $admin->id,
        'window_start' => now()->setTime(9, 0),
        'window_end' => now()->setTime(11, 0),
    ]);
    $call = Interaction::query()->create([
        'direction' => 'inbound',
        'channel' => 'phone',
        'user_id' => $admin->id,
        'customer_id' => $customer->id,
        'callback_at' => now()->setTime(15, 0),
    ]);
    Holiday::query()->create([
        'observed_on' => now()->toDateString(),
        'name_en' => 'Independence Day',
        'name_ar' => 'عيد الاستقلال',
    ]);
    $this->actingAs($admin)->post('/tasks', [
        'title' => 'On the calendar',
        'due_at' => now()->format('Y-m-d H:i'),
    ])->assertRedirect();

    $this->actingAs($admin)->get('/tasks?view=day&scope=all&date='.now()->toDateString())
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $page->component('Tasks/Index')->where('holidays.0.name_en', 'Independence Day');
            $events = collect($page->toArray()['props']['events'])->pluck('event');
            expect($events->all())->toContain('task')->toContain('job')->toContain('callback');

            return $page;
        });

    $day = now()->addDays(3)->toDateString();
    $this->actingAs($tech)->post('/calendar/move', ['id' => 'job-'.$job->id, 'day' => $day])->assertForbidden();
    $this->actingAs($admin)->post('/calendar/move', ['id' => 'job-'.$job->id, 'day' => $day])->assertRedirect();
    expect($job->fresh()->window_start?->timezone((string) config('app.timezone'))->toDateString())->toBe($day);

    $this->actingAs($admin)->post('/calendar/move', ['id' => 'call-'.$call->id, 'day' => $day])->assertRedirect();
    expect($call->fresh()->callback_at?->timezone((string) config('app.timezone'))->toDateString())->toBe($day);
    expect(DB::table('audit_log')->where('event', 'call.callback_moved')->exists())->toBeTrue();
});
