<?php

use App\Events\OpportunityWon;
use App\Models\Approval;
use App\Models\CoverageCheck;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LossReason;
use App\Models\Opportunity;
use App\Models\OpportunityStageHistory;
use App\Models\Pipeline;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Opportunities\OpportunityDesk;
use App\Services\Opportunities\OpportunityFlow;
use App\Services\Opportunities\OpportunityMoney;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('checks every pipeline stage pair', function () {
    $flow = new OpportunityFlow;

    foreach (OpportunityFlow::EDGES as $pipeline => $edges) {
        $stages = array_keys($edges);

        foreach ($stages as $from) {
            foreach ($stages as $to) {
                if ($from === $to) {
                    continue;
                }

                $allowed = in_array($to, $edges[$from], true);
                expect($flow->allows($pipeline, $from, $to))->toBe($allowed);
            }
        }
    }

    expect($flow->allows('retail', 'feasibility', 'proposal', true))->toBeTrue()
        ->and($flow->allows('retail', 'feasibility', 'proposal'))->toBeFalse();
});

it('rounds a dollar amount to the nearest thousand lebanese pounds', function () {
    $money = new OpportunityMoney;

    expect($money->lbp('35.00', '89500.0000'))->toBe('3133000')
        ->and($money->lbp('10.00', '89500'))->toBe('895000');
});

it('converts a lead into one prospect and one deal, and links a customer that already exists', function () {
    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin)->post('/customers', [
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Rami Khalil',
        'name_ar' => 'رامي خليل',
        'phones' => ['03 123 456'],
    ])->assertRedirect();

    $this->actingAs($admin)->post('/leads', [
        'name' => 'Rami Khalil',
        'phone' => '03 123 456',
        'channel' => 'call',
        'service_type_interest' => 'ftth',
        'latitude' => '33.89',
        'longitude' => '35.50',
        'intent' => 'upsell',
    ])->assertRedirect();

    $lead = Lead::query()->firstOrFail();
    $lead->status = 'qualified';
    $lead->save();

    $this->actingAs($admin)->post('/leads/'.$lead->id.'/transition', ['to' => 'converted'])->assertRedirect();

    expect(Customer::query()->count())->toBe(1)
        ->and(Opportunity::query()->count())->toBe(1)
        ->and($lead->fresh()->converted_customer_id)->toBe(Customer::query()->value('id'));

    $deal = Opportunity::query()->firstOrFail();
    expect($deal->pipeline->key)->toBe('retail')
        ->and($deal->stage->key)->toBe('feasibility')
        ->and($deal->number)->toBe('OP-000001');
});

it('refuses an illegal stage, records history, and wins only with a manager reason', function () {
    Event::fake([OpportunityWon::class]);
    $manager = User::factory()->create(['role' => 'sales_manager']);
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $deal = openDeal($agent);

    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', ['stage' => 'negotiation'])
        ->assertSessionHasErrors('stage');

    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', ['stage' => 'site_survey'])->assertRedirect();
    $history = OpportunityStageHistory::query()->firstOrFail();
    expect($history->seconds)->toBeGreaterThanOrEqual(0)
        ->and($deal->fresh()->stage->key)->toBe('site_survey');
    expect(fn () => $history->update(['seconds' => 9]))->toThrow(RuntimeException::class);
    expect(fn () => DB::table('opportunity_stage_history')->where('id', $history->id)->delete())->toThrow(QueryException::class);

    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', [
        'stage' => 'proposal',
    ])->assertSessionHasErrors('coverage_note');

    CoverageCheck::query()->create([
        'subject_type' => 'opportunity',
        'subject_id' => $deal->id,
        'latitude' => 33.89,
        'longitude' => 35.50,
        'result' => 'covered',
        'checked_at' => now(),
    ]);
    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', [
        'stage' => 'proposal',
    ])->assertRedirect();
    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', ['stage' => 'negotiation'])->assertRedirect();
    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', [
        'stage' => 'won',
        'override_reason' => 'I say so',
    ])->assertSessionHasErrors('override_reason');

    $this->actingAs($manager)->post('/opportunities/'.$deal->id.'/stage', ['stage' => 'won'])
        ->assertSessionHasErrors('override_reason');
    $this->actingAs($manager)->post('/opportunities/'.$deal->id.'/stage', [
        'stage' => 'won',
        'override_reason' => 'Sign at install, approved.',
    ])->assertSessionHasErrors('override_reason');
    expect($deal->fresh()->stage->key)->toBe('negotiation');
    $approval = Approval::query()->where('type', 'install_exception')->where('subject_id', $deal->id)->firstOrFail();
    $management = User::factory()->create(['role' => 'management']);
    $this->actingAs($management)->post('/approvals/'.$approval->id.'/approve', [
        'comment' => 'Sign at install is fine.',
    ])->assertRedirect();

    expect($deal->fresh()->stage->key)->toBe('won');
    Event::assertDispatched(OpportunityWon::class);
    expect(DB::table('audit_log')->where('event', 'opportunity.moved')->exists())->toBeTrue();
});

it('requires a loss reason and creates one win-back task when the reason is price', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $deal = openDeal($agent);
    $price = LossReason::query()->where('key', 'price')->firstOrFail();

    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', ['stage' => 'lost'])
        ->assertSessionHasErrors('loss_reason_id');
    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', [
        'stage' => 'lost',
        'loss_reason_id' => $price->id,
    ])->assertRedirect();

    expect(WorkTask::query()->where('kind', 'win_back')->where('opportunity_id', $deal->id)->count())->toBe(1);
    expect($deal->fresh()->stage->key)->toBe('lost');

    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', [
        'stage' => 'lost',
        'loss_reason_id' => $price->id,
    ])->assertSessionHasErrors('stage');
    expect(WorkTask::query()->where('kind', 'win_back')->count())->toBe(1);
});

it('asks management before a reseller can reach agreement', function () {
    $admin = User::factory()->superAdmin()->create();
    $manager = User::factory()->create(['role' => 'sales_manager']);
    $this->actingAs($admin)->post('/leads', [
        'name' => 'Tower Net',
        'phone' => '71 444 555',
        'channel' => 'sales',
        'service_type_interest' => 'ptmp',
        'latitude' => '33.89',
        'longitude' => '35.50',
    ])->assertRedirect();
    $lead = Lead::query()->latest('id')->firstOrFail();
    $lead->status = 'qualified';
    $lead->save();
    $this->actingAs($admin)->post('/leads/'.$lead->id.'/transition', [
        'to' => 'converted',
        'customer_type' => 'reseller',
    ])->assertRedirect();
    $deal = Opportunity::query()->where('lead_id', $lead->id)->firstOrFail();
    $deal->owner_id = $manager->id;
    $deal->save();

    expect($deal->pipeline->key)->toBe('reseller');
    $this->actingAs($manager)->post('/opportunities/'.$deal->id.'/stage', ['stage' => 'due_diligence'])->assertRedirect();
    CoverageCheck::query()->create([
        'subject_type' => 'opportunity',
        'subject_id' => $deal->id,
        'latitude' => 33.89,
        'longitude' => 35.50,
        'result' => 'covered',
        'checked_at' => now(),
    ]);
    $this->actingAs($manager)->post('/opportunities/'.$deal->id.'/stage', [
        'stage' => 'proposal',
    ])->assertRedirect();
    $this->actingAs($manager)->post('/opportunities/'.$deal->id.'/stage', ['stage' => 'agreement'])
        ->assertSessionHasErrors('stage');
    $approval = Approval::query()->where('type', 'reseller_agreement')->firstOrFail();
    $this->actingAs($admin)->post('/approvals/'.$approval->id.'/approve', [
        'comment' => 'Agreement confirmed.',
    ])->assertRedirect();
    expect($deal->fresh()->stage->key)->toBe('agreement')
        ->and($deal->fresh()->management_confirmed)->toBeTrue();
});

it('matches the funnel counts to the database and hides the board from the warehouse', function () {
    $admin = User::factory()->superAdmin()->create();
    $agent = User::factory()->create(['role' => 'sales_agent']);
    openDeal($agent);
    openDeal($agent);
    $pipeline = Pipeline::query()->where('key', 'retail')->firstOrFail();
    $report = app(OpportunityDesk::class)->funnel($pipeline);
    $db = DB::table('opportunities')->where('pipeline_id', $pipeline->id)->count();

    expect(collect($report)->sum('count'))->toBe($db);

    $this->actingAs($admin)->get('/opportunities?pipeline=retail')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Opportunities/Index')
        ->where('funnel.0.count', 2));
    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/opportunities')->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 'finance']))->get('/opportunities')->assertOk()->assertInertia(fn ($page) => $page
        ->where('deals', []));
});

function openDeal(User $owner): Opportunity
{
    $admin = User::query()->where('role', 'super_admin')->first() ?? User::factory()->superAdmin()->create();
    $phone = '76'.str_pad((string) (Lead::query()->count() + 1), 6, '0', STR_PAD_LEFT);
    test()->actingAs($admin)->post('/leads', [
        'name' => 'Nour Haddad',
        'phone' => $phone,
        'channel' => 'call',
        'service_type_interest' => 'ftth',
        'latitude' => '33.89',
        'longitude' => '35.50',
    ])->assertRedirect();
    $lead = Lead::query()->where('phone_e164', 'like', '%'.substr($phone, -6))->firstOrFail();
    $lead->status = 'qualified';
    $lead->save();
    test()->actingAs($admin)->post('/leads/'.$lead->id.'/transition', [
        'to' => 'converted',
        'mrc' => '35.00',
    ])->assertRedirect();

    $deal = Opportunity::query()->where('lead_id', $lead->id)->firstOrFail();
    $deal->owner_id = $owner->id;
    $deal->save();

    return $deal;
}
