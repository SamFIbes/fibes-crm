<?php

use App\Models\AgentStatusLog;
use App\Models\CsatResponse;
use App\Models\Customer;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\QaEvaluation;
use App\Models\QaEvent;
use App\Models\QaForm;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Qa\EvaluationMachine;
use App\Services\Qa\QaDesk;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('walks every allowed and forbidden evaluation step', function () {
    $machine = new EvaluationMachine;

    foreach (EvaluationMachine::ALLOWED as $from => $targets) {
        foreach (array_keys(EvaluationMachine::ALLOWED) as $to) {
            if (in_array($to, $targets, true)) {
                expect($machine->allows($from, $to))->toBeTrue();
            } else {
                expect(fn () => $machine->assert($from, $to))->toThrow(ValidationException::class);
            }
        }
    }
});

it('zeros a critical fail, keeps the old form version, and samples five calls', function () {
    $supervisor = User::factory()->create(['role' => 'cc_supervisor']);
    $agent = User::factory()->create(['role' => 'cc_agent']);
    $call = Interaction::query()->create([
        'direction' => 'inbound',
        'channel' => 'phone',
        'user_id' => $agent->id,
        'duration_seconds' => 60,
    ]);

    $this->actingAs($supervisor)->post('/qa/score', [
        'subject_type' => 'interaction',
        'subject_id' => $call->id,
        'agent_id' => $agent->id,
        'scores' => ['greeting' => 100, 'compliance' => 0],
        'notes' => 'Missed the required line.',
    ])->assertRedirect();
    $evaluation = QaEvaluation::query()->firstOrFail();
    expect($evaluation->critical_fail)->toBeTrue()
        ->and((string) $evaluation->total_pct)->toBe('0.00')
        ->and($evaluation->form_version)->toBe(1);

    $this->actingAs($supervisor)->post('/qa/forms', [
        'criteria' => [
            ['key' => 'greeting', 'label_en' => 'Greeting', 'label_ar' => 'الترحيب', 'weight' => 40, 'critical' => false],
            ['key' => 'compliance', 'label_en' => 'Compliance', 'label_ar' => 'الالتزام', 'weight' => 60, 'critical' => true],
        ],
    ])->assertRedirect();
    expect(QaForm::query()->where('key', 'call')->where('is_current', true)->value('version'))->toBe(2)
        ->and($evaluation->fresh()->form_version)->toBe(1);

    foreach (range(1, 7) as $index) {
        Interaction::query()->create([
            'direction' => 'inbound',
            'channel' => 'phone',
            'user_id' => $agent->id,
            'duration_seconds' => 30,
            'created_at' => now(),
        ]);
    }
    $this->actingAs($supervisor)->post('/qa/sample')->assertRedirect();
    $ids = DB::table('qa_samples')->where('agent_id', $agent->id)->orderBy('interaction_id')->pluck('interaction_id')->all();
    $expected = Interaction::query()->where('user_id', $agent->id)->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->orderBy('id')->limit(5)->pluck('id')->all();
    expect($ids)->toBe($expected);
    $this->actingAs($supervisor)->post('/qa/sample')->assertRedirect();
    expect(DB::table('qa_samples')->where('agent_id', $agent->id)->count())->toBe(5);

    $event = QaEvent::query()->firstOrFail();
    expect(fn () => DB::table('qa_events')->where('id', $event->id)->delete())->toThrow(QueryException::class);
});

it('lets an agent see only their own score and a supervisor see the team', function () {
    $supervisor = User::factory()->create(['role' => 'cc_supervisor']);
    $agent = User::factory()->create(['role' => 'cc_agent']);
    $other = User::factory()->create(['role' => 'cc_agent']);
    $sales = User::factory()->create(['role' => 'sales_manager']);
    $mine = Interaction::query()->create(['direction' => 'inbound', 'channel' => 'phone', 'user_id' => $agent->id, 'duration_seconds' => 40]);
    $theirs = Interaction::query()->create(['direction' => 'inbound', 'channel' => 'phone', 'user_id' => $other->id, 'duration_seconds' => 40]);
    $lead = Lead::query()->create(['name' => 'Ada', 'phone_e164' => '+96171111001', 'status' => 'new', 'owner_id' => $sales->id]);
    $salesCall = Interaction::query()->create(['direction' => 'inbound', 'channel' => 'phone', 'user_id' => $sales->id, 'lead_id' => $lead->id, 'duration_seconds' => 40]);

    app(QaDesk::class)->score('interaction', $mine->id, $agent->id, ['greeting' => 100, 'compliance' => 100], null, $supervisor, Request::create('/'));
    $hidden = app(QaDesk::class)->score('interaction', $theirs->id, $other->id, ['greeting' => 50, 'compliance' => 100], null, $supervisor, Request::create('/'));

    $this->actingAs($agent)->get('/qa')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Qa/Index')
        ->has('evaluations', 1)
        ->where('evaluations.0.agent_id', $agent->id));
    $this->actingAs($agent)->post('/qa/'.$hidden->id.'/acknowledge')->assertNotFound();
    $own = QaEvaluation::query()->where('agent_id', $agent->id)->firstOrFail();
    $this->actingAs($agent)->post('/qa/'.$own->id.'/acknowledge')->assertRedirect();
    expect($own->fresh()->status)->toBe('acknowledged');

    $this->actingAs($supervisor)->get('/qa')->assertOk()->assertInertia(fn ($page) => $page->has('evaluations', 2));
    $this->actingAs($sales)->post('/qa/score', [
        'subject_type' => 'ticket',
        'subject_id' => 1,
        'agent_id' => $agent->id,
        'scores' => ['greeting' => 100, 'compliance' => 100],
    ])->assertSessionHasErrors('subject');
    $this->actingAs($sales)->post('/qa/score', [
        'subject_type' => 'interaction',
        'subject_id' => $salesCall->id,
        'agent_id' => $sales->id,
        'scores' => ['greeting' => 80, 'compliance' => 100],
    ])->assertRedirect();
    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/qa')->assertForbidden();
});

it('matches each call center figure to a sql count and links the tickets', function () {
    $agent = User::factory()->create(['role' => 'cc_agent']);
    $supervisor = User::factory()->create(['role' => 'cc_supervisor']);
    $customer = Customer::query()->create([
        'account_no' => 'FB-QA-1',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'QA',
        'name_ar' => 'جودة',
    ]);
    $today = now()->toDateString();
    Interaction::query()->create(['direction' => 'inbound', 'channel' => 'phone', 'user_id' => $agent->id, 'customer_id' => $customer->id, 'duration_seconds' => 60, 'callback_at' => now()->addHour()]);
    Interaction::query()->create(['direction' => 'inbound', 'channel' => 'phone', 'user_id' => $agent->id, 'customer_id' => $customer->id, 'duration_seconds' => 120, 'created_at' => now()->subMinutes(30)]);
    AgentStatusLog::query()->create([
        'user_id' => $agent->id,
        'status' => 'acw',
        'started_at' => now()->subSeconds(120),
        'ended_at' => now(),
    ]);
    Lead::query()->create(['name' => 'One', 'phone_e164' => '+96171111011', 'status' => 'qualified', 'owner_id' => $agent->id]);
    Lead::query()->create(['name' => 'Two', 'phone_e164' => '+96171111012', 'status' => 'new', 'owner_id' => $agent->id]);
    $category = TicketCategory::query()->create(['key' => 'qa', 'name_en' => 'QA', 'name_ar' => 'جودة']);
    $resolved = Ticket::query()->create([
        'number' => 'T-QA-1',
        'customer_id' => $customer->id,
        'category_id' => $category->id,
        'priority' => 'P3',
        'channel' => 'phone',
        'status' => 'resolved',
        'subject' => 'Slow',
        'owner_id' => $agent->id,
        'response_minutes' => 60,
        'resolve_minutes' => 120,
        'response_due_at' => now()->addHour(),
        'resolve_due_at' => now()->addHours(2),
        'resolve_breached' => false,
    ]);
    Ticket::query()->create([
        'number' => 'T-QA-2',
        'customer_id' => $customer->id,
        'category_id' => $category->id,
        'priority' => 'P3',
        'channel' => 'phone',
        'status' => 'open',
        'subject' => 'Down',
        'owner_id' => $agent->id,
        'escalation_reason' => 'Needs network',
        'response_minutes' => 60,
        'resolve_minutes' => 120,
        'response_due_at' => now()->addHour(),
        'resolve_due_at' => now()->addHours(2),
    ]);
    CsatResponse::query()->create(['ticket_id' => $resolved->id, 'token' => 'qa-token', 'score' => 4]);
    app(QaDesk::class)->score('ticket', $resolved->id, $agent->id, ['greeting' => 100, 'compliance' => 100], null, $supervisor, Request::create('/'));

    $kpis = app(QaDesk::class)->kpis($agent->id, $today, $today);
    $calls = DB::selectOne('select count(*) as c, round(avg(duration_seconds)) as aht from interactions where user_id = ? and date(created_at) >= ? and date(created_at) <= ?', [$agent->id, $today, $today]);
    $tickets = DB::selectOne('select count(*) as c from tickets where owner_id = ? and date(created_at) >= ? and date(created_at) <= ?', [$agent->id, $today, $today]);
    $l1 = DB::selectOne("select count(*) as c from tickets where owner_id = ? and status = 'resolved' and escalation_reason is null and escalation_tier_id is null and date(created_at) >= ? and date(created_at) <= ?", [$agent->id, $today, $today]);
    $leads = DB::selectOne('select count(*) as c from leads where owner_id = ? and date(created_at) >= ? and date(created_at) <= ?', [$agent->id, $today, $today]);
    $qualified = DB::selectOne("select count(*) as c from leads where owner_id = ? and status = 'qualified' and date(created_at) >= ? and date(created_at) <= ?", [$agent->id, $today, $today]);

    expect($kpis['calls']['value'])->toBe((string) $calls->c)
        ->and($kpis['aht']['value'])->toBe((string) (int) $calls->aht)
        ->and($kpis['acw']['value'])->toBe('120')
        ->and($kpis['tickets']['value'])->toBe((string) $tickets->c)
        ->and($kpis['resolved_l1']['value'])->toBe((string) $l1->c)
        ->and($kpis['fcr']['value'])->toBe('50.00')
        ->and($kpis['sla']['value'])->toBe('100.00')
        ->and($kpis['leads']['value'])->toBe((string) $leads->c)
        ->and($kpis['qualified']['value'])->toBe((string) $qualified->c)
        ->and($kpis['conversion']['value'])->toBe('50.00')
        ->and($kpis['qa']['value'])->toBe('100.00')
        ->and($kpis['csat']['value'])->toBe('4.00')
        ->and($kpis['abandoned']['value'])->toBeNull()
        ->and($kpis['tickets']['hrefs'])->toContain('/tickets/'.$resolved->id);

    $again = app(QaDesk::class)->score('ticket', $resolved->id, $agent->id, ['greeting' => 50, 'compliance' => 100], null, $supervisor, Request::create('/'));
    expect(app(QaDesk::class)->calibration('ticket', $resolved->id)['variance'])->toBe('25.00')
        ->and((string) $again->total_pct)->toBe('75.00');
});
