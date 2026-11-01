<?php

use App\Mail\ReportMail;
use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\ReportExport;
use App\Models\Team;
use App\Models\User;
use App\Services\Reports\ReportDesk;
use App\Services\Reports\ReportExporter;
use App\Services\Settings\CommercialSettings;
use App\Services\Settings\SettingsStore;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('matches every report to a sql count', function () {
    $admin = User::factory()->superAdmin()->create();
    seedReportWorld($admin);
    $from = now()->subDay()->toDateString();
    $to = now()->addDay()->toDateString();
    $desk = app(ReportDesk::class);

    foreach (array_keys(ReportDesk::REPORTS) as $key) {
        expect($desk->value($key, $admin, $from, $to))->toBe(sqlReport($key, $from, $to));
    }
});

it('keeps an export inside the team and expires the link', function () {
    $manager = User::factory()->create(['role' => 'sales_manager']);
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $other = User::factory()->create(['role' => 'sales_agent']);
    $team = Team::query()->create(['key' => 'sales-a', 'name_en' => 'A', 'name_ar' => 'أ']);
    $manager->teams()->attach($team->id);
    $agent->teams()->attach($team->id);
    $mine = Customer::query()->create(['account_no' => 'FB-R-1', 'type' => 'individual', 'status' => 'prospect', 'name_en' => 'Mine Lead', 'name_ar' => 'لي']);
    $theirs = Customer::query()->create(['account_no' => 'FB-R-2', 'type' => 'individual', 'status' => 'prospect', 'name_en' => 'Other Lead', 'name_ar' => 'غير']);
    DB::table('leads')->insert([
        ['name' => 'Mine Lead', 'phone_e164' => '+96171111001', 'status' => 'new', 'owner_id' => $agent->id, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Other Lead', 'phone_e164' => '+96171111002', 'status' => 'new', 'owner_id' => $other->id, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->actingAs($agent)->post('/reports/leads_by_source/export', ['format' => 'csv'])->assertForbidden();
    $this->actingAs($manager)->post('/reports/leads_by_source/export', ['format' => 'csv'])->assertRedirect();
    $export = DB::table('report_exports')->first();
    $csv = Storage::disk('local')->get($export->path);
    expect($csv)->toContain('Mine Lead')->not->toContain('Other Lead')
        ->and(DB::table('audit_log')->where('event', 'report.exported')->exists())->toBeTrue();
    $link = app(ReportExporter::class)->link(ReportExport::query()->firstOrFail());
    $this->get($link)->assertOk();
    $this->travel(25)->hours();
    $this->get($link)->assertForbidden();
    expect($mine->id)->not->toBe($theirs->id);
});

it('queues a large export and sends the digest only at the saved hour', function () {
    $admin = User::factory()->superAdmin()->create(['email' => 'boss-report@fibes.test']);
    $supervisor = User::factory()->create(['role' => 'cc_supervisor', 'email' => 'sup-report@fibes.test']);
    $figures = app(CommercialSettings::class)->all();
    $figures['report_async_rows'] = 1;
    $figures['digest_roles'] = 'cc_supervisor';
    app(SettingsStore::class)->put('commercial', $figures, $admin, Request::create('/'));
    DB::table('leads')->insert([
        ['name' => 'Queued', 'phone_e164' => '+96171111009', 'status' => 'new', 'owner_id' => $admin->id, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->actingAs($admin)->post('/reports/leads_by_source/export', ['format' => 'pdf'])->assertRedirect();
    expect(DB::table('report_exports')->value('status'))->toBe('ready');

    Mail::fake();
    $this->travelTo(Carbon::parse('2026-11-02 07:00:00'));
    Artisan::call('reports:digest');
    Mail::assertNothingSent();
    $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
    Artisan::call('reports:digest');
    Mail::assertSent(ReportMail::class, 2);
    Artisan::call('reports:digest');
    Mail::assertSent(ReportMail::class, 2);
    expect($supervisor->email)->not->toBe('');
});

it('sends each role to their own home', function (string $role, string $home) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('home', $home)
        ->has('tiles'));
})->with([
    ['sales_agent', 'sales'],
    ['sales_manager', 'sales'],
    ['cc_supervisor', 'supervisor'],
    ['cc_agent', 'agent'],
    ['ops_manager', 'operations'],
    ['noc_engineer', 'noc'],
    ['finance', 'finance'],
    ['warehouse', 'warehouse'],
    ['field_technician', 'operations'],
    ['management', 'management'],
]);

function sqlReport(string $key, string $from, string $to): string
{
    $one = function (string $sql, array $bind = []) use ($from, $to) {
        return DB::selectOne($sql, array_merge([$from, $to], $bind));
    };
    $count = fn (string $sql, array $bind = []) => (string) ($one($sql, $bind)->c ?? 0);
    $money = fn (string $sql) => bcadd((string) ($one($sql)->s ?? 0), '0', 2);
    $rate = function (int $part, int $whole): string {
        return $whole < 1 ? '0.00' : number_format(($part / $whole) * 100, 2, '.', '');
    };

    return match ($key) {
        'leads_by_source' => $count('select count(*) c from leads where date(created_at) >= ? and date(created_at) <= ?'),
        'lead_sla' => $count('select count(*) c from leads where sla_breached_at is not null and date(created_at) >= ? and date(created_at) <= ?'),
        'funnel' => $count('select count(*) c from opportunities where date(created_at) >= ? and date(created_at) <= ?'),
        'win_rate' => $rate(
            (int) $count('select count(*) c from opportunities where won_at is not null and date(won_at) >= ? and date(won_at) <= ?'),
            (int) $count('select count(*) c from opportunities where won_at is not null and date(won_at) >= ? and date(won_at) <= ?')
                + (int) $count('select count(*) c from opportunities where lost_at is not null and date(lost_at) >= ? and date(lost_at) <= ?'),
        ),
        'pipeline_value' => $money('select sum(mrc) s from opportunities where won_at is null and lost_at is null and date(created_at) >= ? and date(created_at) <= ?'),
        'sales_cycle' => (string) (int) ($one("select round(avg((strftime('%s', won_at) - strftime('%s', created_at)) / 86400.0)) c from opportunities where won_at is not null and date(won_at) >= ? and date(won_at) <= ?")->c ?? 0),
        'loss_reasons' => $count('select count(*) c from opportunities where lost_at is not null and date(lost_at) >= ? and date(lost_at) <= ?'),
        'leaderboard' => $count('select count(*) c from opportunities where won_at is not null and date(won_at) >= ? and date(won_at) <= ?'),
        'quote_turnaround' => $count('select count(*) c from quotes where date(created_at) >= ? and date(created_at) <= ?'),
        'discounts' => $money('select sum(discount_total) s from quotes where date(created_at) >= ? and date(created_at) <= ?'),
        'campaign_roi' => $count('select count(*) c from leads where campaign_id is not null and date(created_at) >= ? and date(created_at) <= ?'),
        'referrals' => $count('select count(*) c from referral_credits where date(created_at) >= ? and date(created_at) <= ?'),
        'orders_backlog' => $count("select count(*) c from orders where status not in ('completed', 'cancelled') and date(created_at) >= ? and date(created_at) <= ?"),
        'install_lead_time' => (string) (int) ($one("select round(avg((strftime('%s', updated_at) - strftime('%s', created_at)) / 86400.0)) c from orders where status = 'activated' and date(created_at) >= ? and date(created_at) <= ?")->c ?? 0),
        'first_time_right' => $count("select count(*) c from field_jobs where status = 'done' and failure_reason is null and date(created_at) >= ? and date(created_at) <= ?"),
        'failed_jobs' => $count("select count(*) c from field_jobs where status = 'failed' and date(created_at) >= ? and date(created_at) <= ?"),
        'tech_productivity' => $count('select count(*) c from field_jobs where date(created_at) >= ? and date(created_at) <= ?'),
        'materials_per_install' => $count('select count(*) c from stock_movements where date(created_at) >= ? and date(created_at) <= ?'),
        'survey_rate' => $count('select count(*) c from survey_results where feasible = 1 and date(created_at) >= ? and date(created_at) <= ?'),
        'waitlist' => $count("select count(*) c from leads where status = 'waitlisted' and date(created_at) >= ? and date(created_at) <= ?"),
        'tickets_open' => $count("select count(*) c from tickets where status not in ('resolved', 'closed') and date(created_at) >= ? and date(created_at) <= ?"),
        'sla_met' => $rate(
            (int) $count("select count(*) c from tickets where status in ('resolved', 'closed') and resolve_breached = 0 and date(created_at) >= ? and date(created_at) <= ?"),
            (int) $count("select count(*) c from tickets where status in ('resolved', 'closed') and date(created_at) >= ? and date(created_at) <= ?"),
        ),
        'mtta' => (string) (int) ($one("select round(avg((strftime('%s', first_response_at) - strftime('%s', created_at)) / 60.0)) c from tickets where first_response_at is not null and date(created_at) >= ? and date(created_at) <= ?")->c ?? 0),
        'mttr' => (string) (int) ($one("select round(avg((strftime('%s', updated_at) - strftime('%s', created_at)) / 60.0)) c from tickets where status in ('resolved', 'closed') and date(created_at) >= ? and date(created_at) <= ?")->c ?? 0),
        'reopen' => $count("select count(*) c from tickets where status = 'reopened' and date(created_at) >= ? and date(created_at) <= ?"),
        'fcr' => $rate(
            (int) $count("select count(*) c from tickets where status in ('resolved', 'closed') and escalation_reason is null and escalation_tier_id is null and date(created_at) >= ? and date(created_at) <= ?"),
            (int) $count('select count(*) c from tickets where date(created_at) >= ? and date(created_at) <= ?'),
        ),
        'escalation_rate' => $rate(
            (int) $count('select count(*) c from ticket_escalations where date(created_at) >= ? and date(created_at) <= ?'),
            (int) $count('select count(*) c from tickets where date(created_at) >= ? and date(created_at) <= ?'),
        ),
        'incidents' => $count('select count(*) c from incidents where date(created_at) >= ? and date(created_at) <= ?'),
        'repeat_customers' => (string) DB::selectOne('select count(*) c from (select customer_id from tickets where date(created_at) >= ? and date(created_at) <= ? group by customer_id having count(*) >= 2) repeats', [$from, $to])->c,
        'csat' => number_format((float) (DB::selectOne('select avg(score) s from csat_responses where score is not null and date(created_at) >= ? and date(created_at) <= ?', [$from, $to])->s ?? 0), 2, '.', ''),
        'calls' => $count('select count(*) c from interactions where date(created_at) >= ? and date(created_at) <= ?'),
        'aht' => (string) (int) round((float) (DB::selectOne('select avg(duration_seconds) s from interactions where date(created_at) >= ? and date(created_at) <= ?', [$from, $to])->s ?? 0)),
        'dispositions' => $count('select count(*) c from interactions where wrap_up_code_id is not null and date(created_at) >= ? and date(created_at) <= ?'),
        'callbacks' => $count('select count(*) c from interactions where callback_at is not null and date(created_at) >= ? and date(created_at) <= ?'),
        'agent_status' => $count('select count(*) c from agent_status_log where date(started_at) >= ? and date(started_at) <= ?'),
        'qa_scores' => number_format((float) (DB::selectOne('select avg(total_pct) s from qa_evaluations where date(created_at) >= ? and date(created_at) <= ?', [$from, $to])->s ?? 0), 2, '.', ''),
        'call_conversion' => $rate(
            (int) $count("select count(*) c from leads where status = 'qualified' and date(created_at) >= ? and date(created_at) <= ?"),
            (int) $count('select count(*) c from leads where date(created_at) >= ? and date(created_at) <= ?'),
        ),
        'mrc' => $money("select sum(monthly_usd) s from customer_services where status = 'active' and date(created_at) >= ? and date(created_at) <= ?"),
        'invoiced' => $money("select sum(total) s from invoices where type = 'invoice' and status != 'voided' and date(created_at) >= ? and date(created_at) <= ?"),
        'collected' => $money("select sum(amount) s from payments where status = 'confirmed' and date(received_at) >= ? and date(received_at) <= ?"),
        'aging' => $count("select count(*) c from invoices where type = 'invoice' and status not in ('paid', 'voided', 'draft') and date(created_at) >= ? and date(created_at) <= ?"),
        'dso' => (function () use ($money) {
            $invoiced = (float) $money("select sum(total) s from invoices where type = 'invoice' and status != 'voided' and date(created_at) >= ? and date(created_at) <= ?");
            $collected = (float) $money("select sum(amount) s from payments where status = 'confirmed' and date(received_at) >= ? and date(received_at) <= ?");

            return $invoiced <= 0 ? '0' : (string) (int) round((($invoiced - $collected) / $invoiced) * 30);
        })(),
        'collections_by_method' => $count("select count(*) c from payments where status = 'confirmed' and date(received_at) >= ? and date(received_at) <= ?"),
        'cash_variance' => $money('select sum(variance) s from cash_sessions where variance is not null and date(created_at) >= ? and date(created_at) <= ?'),
        'refunds' => $count("select count(*) c from payments where status = 'reversed' and date(created_at) >= ? and date(created_at) <= ?"),
        'lbp_collections' => $money("select sum(amount) s from payments where status = 'confirmed' and currency = 'LBP' and date(received_at) >= ? and date(received_at) <= ?"),
        'renewals' => $count('select count(*) c from subscriptions where date(next_invoice_at) >= ? and date(next_invoice_at) <= ?'),
        'suspended_unpaid' => $count("select count(*) c from customer_services where status = 'suspended' and date(created_at) >= ? and date(created_at) <= ?"),
        'active_base' => $count("select count(*) c from customers where status = 'active' and date(created_at) >= ? and date(created_at) <= ?"),
        'net_adds' => $count('select count(*) c from customers where date(created_at) >= ? and date(created_at) <= ?'),
        'churn' => $count("select count(*) c from customer_services where status = 'terminated' and date(created_at) >= ? and date(created_at) <= ? and date(terminated_at) >= ? and date(terminated_at) <= ?", [$from, $to]),
        'tenure' => $count("select count(*) c from customer_services where status = 'active' and date(created_at) >= ? and date(created_at) <= ?"),
        'health' => (string) DB::selectOne("select count(*) c from customers where status = 'active'")->c,
        'stock_on_hand' => $count('select count(*) c from stock_items where date(created_at) >= ? and date(created_at) <= ?'),
        'van_stock' => $count("select count(*) c from stock_locations where kind = 'van' and date(created_at) >= ? and date(created_at) <= ?"),
        'movements' => $count('select count(*) c from stock_movements where date(created_at) >= ? and date(created_at) <= ?'),
        'variances' => $count('select count(*) c from stock_counts where date(created_at) >= ? and date(created_at) <= ?'),
        'rma' => $count("select count(*) c from stock_movements where kind = 'return' and date(created_at) >= ? and date(created_at) <= ?"),
        'serials' => $count('select count(*) c from stock_serials where customer_service_id is not null and date(created_at) >= ? and date(created_at) <= ?'),
        'open_pos' => $count("select count(*) c from purchase_orders where status not in ('received', 'cancelled') and date(created_at) >= ? and date(created_at) <= ?"),
        'logins' => $count("select count(*) c from audit_log where event = 'auth.login.succeeded' and date(occurred_at) >= ? and date(occurred_at) <= ?"),
        'failed_logins' => $count("select count(*) c from audit_log where event = 'auth.login.failed' and date(occurred_at) >= ? and date(occurred_at) <= ?"),
        'permission_changes' => $count("select count(*) c from audit_log where event like '%role%' and date(occurred_at) >= ? and date(occurred_at) <= ?"),
        'exports' => $count("select count(*) c from audit_log where event = 'report.exported' and date(occurred_at) >= ? and date(occurred_at) <= ?"),
        'approvals_by_approver' => $count('select count(*) c from approval_decisions where date(decided_at) >= ? and date(decided_at) <= ?'),
        default => '0',
    };
}

function seedReportWorld(User $admin): void
{
    $customer = Customer::query()->create([
        'account_no' => 'FB-RPT-1', 'type' => 'individual', 'status' => 'active', 'name_en' => 'Report Nour', 'name_ar' => 'نور', 'owner_id' => $admin->id,
    ]);
    $pipeline = Pipeline::query()->where('key', 'retail')->firstOrFail();
    $stage = PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('key', 'negotiation')->firstOrFail();
    $won = Opportunity::query()->create([
        'customer_id' => $customer->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'owner_id' => $admin->id, 'mrc' => '40.00', 'currency' => 'USD', 'service_type' => 'ftth', 'won_at' => now()->subDay(),
    ]);
    $won->created_at = now()->subDays(3);
    $won->save();
    Opportunity::query()->create([
        'customer_id' => $customer->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'owner_id' => $admin->id, 'mrc' => '15.00', 'currency' => 'USD', 'service_type' => 'ftth', 'lost_at' => now(),
    ]);
    $campaign = DB::table('campaigns')->insertGetId(['name' => 'Spring', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('leads')->insert([
        'name' => 'Ada', 'phone_e164' => '+96170000001', 'status' => 'qualified', 'owner_id' => $admin->id, 'campaign_id' => $campaign, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('leads')->insert([
        'name' => 'Bea', 'phone_e164' => '+96170000002', 'status' => 'waitlisted', 'owner_id' => $admin->id, 'sla_breached_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('referral_credits')->insert([
        'lead_id' => DB::table('leads')->value('id'), 'customer_id' => $customer->id, 'amount' => '5.00', 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('quotes')->insert([
        'ulid' => (string) Str::ulid(), 'status' => 'sent', 'exchange_rate' => '89500.0000', 'discount_total' => '3.00', 'owner_id' => $admin->id, 'public_token' => Str::random(40), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $category = DB::table('ticket_categories')->insertGetId(['key' => 'rpt', 'name_en' => 'R', 'name_ar' => 'ر', 'created_at' => now(), 'updated_at' => now()]);
    $ticket = DB::table('tickets')->insertGetId([
        'number' => 'T-RPT-1', 'customer_id' => $customer->id, 'category_id' => $category, 'priority' => 'P3', 'channel' => 'phone', 'status' => 'resolved', 'subject' => 'Slow', 'owner_id' => $admin->id,
        'response_minutes' => 30, 'resolve_minutes' => 120, 'response_due_at' => now(), 'resolve_due_at' => now(), 'resolve_breached' => 0,
        'first_response_at' => now()->addMinutes(12), 'created_at' => now()->subMinutes(40), 'updated_at' => now(),
    ]);
    DB::table('tickets')->insert([
        'number' => 'T-RPT-2', 'customer_id' => $customer->id, 'category_id' => $category, 'priority' => 'P3', 'channel' => 'phone', 'status' => 'open', 'subject' => 'Down', 'owner_id' => $admin->id,
        'response_minutes' => 30, 'resolve_minutes' => 120, 'response_due_at' => now(), 'resolve_due_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('csat_responses')->insert(['ticket_id' => $ticket, 'token' => 'rpt-token', 'score' => 4, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('interactions')->insert(['direction' => 'inbound', 'channel' => 'phone', 'user_id' => $admin->id, 'duration_seconds' => 90, 'callback_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('agent_status_log')->insert(['user_id' => $admin->id, 'status' => 'available', 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $form = DB::table('qa_forms')->where('is_current', true)->first();
    DB::table('qa_evaluations')->insert([
        'ulid' => (string) Str::ulid(), 'subject_type' => 'ticket', 'subject_id' => $ticket, 'agent_id' => $admin->id, 'evaluator_id' => $admin->id,
        'form_id' => $form->id, 'form_version' => $form->version, 'scores' => '{}', 'total_pct' => '80.00', 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now(),
    ]);
    CustomerService::query()->create(['customer_id' => $customer->id, 'service_type' => 'ftth', 'plan_name' => 'Home', 'status' => 'active', 'monthly_usd' => '35.00']);
    CustomerService::query()->create(['customer_id' => $customer->id, 'service_type' => 'ftth', 'plan_name' => 'Home', 'status' => 'terminated', 'monthly_usd' => '35.00', 'terminated_at' => now()]);
    DB::table('orders')->insert([
        'ulid' => (string) Str::ulid(), 'customer_id' => $customer->id, 'type' => 'new', 'status' => 'activated', 'currency' => 'USD', 'exchange_rate' => '89500.0000', 'total' => '35.00', 'owner_id' => $admin->id,
        'requested_on' => now()->toDateString(), 'created_at' => now()->subDay(), 'updated_at' => now(),
    ]);
    DB::table('field_jobs')->insert(['number' => 'JB-R-1', 'type' => 'install', 'status' => 'done', 'customer_id' => $customer->id, 'technician_id' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('field_jobs')->insert(['number' => 'JB-R-2', 'type' => 'install', 'status' => 'failed', 'customer_id' => $customer->id, 'technician_id' => $admin->id, 'failure_reason' => 'access', 'created_at' => now(), 'updated_at' => now()]);
    $job = DB::table('field_jobs')->where('number', 'JB-R-1')->value('id');
    DB::table('survey_results')->insert(['job_id' => $job, 'feasible' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('invoices')->insert([
        'ulid' => (string) Str::ulid(), 'customer_id' => $customer->id, 'type' => 'invoice', 'status' => 'issued', 'total' => '35.00', 'exchange_rate' => '89500.0000', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('payments')->insert([
        'ulid' => (string) Str::ulid(), 'number' => 'RC-R-1', 'customer_id' => $customer->id, 'amount' => '10.00', 'tender_amount' => '10.00', 'currency' => 'USD', 'exchange_rate' => '89500.0000', 'method' => 'cash', 'status' => 'confirmed', 'received_by' => $admin->id, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('subscriptions')->insert([
        'ulid' => (string) Str::ulid(), 'customer_id' => $customer->id, 'price' => '35.00', 'status' => 'active', 'next_invoice_at' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $location = DB::table('stock_locations')->insertGetId(['kind' => 'van', 'name' => 'Van', 'user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
    $item = DB::table('stock_items')->insertGetId(['sku' => 'RPT-1', 'name_en' => 'ONT', 'name_ar' => 'أونت', 'unit' => 'piece', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('stock_movements')->insert(['item_id' => $item, 'to_location_id' => $location, 'quantity' => 1, 'unit_cost_usd' => 1, 'kind' => 'return', 'actor_id' => $admin->id, 'created_at' => now()]);
    DB::table('stock_counts')->insert(['location_id' => $location, 'user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
    $service = CustomerService::query()->where('status', 'active')->value('id');
    DB::table('stock_serials')->insert(['item_id' => $item, 'serial' => 'SER-1', 'status' => 'installed', 'customer_service_id' => $service, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('purchase_orders')->insert(['ulid' => (string) Str::ulid(), 'status' => 'draft', 'total' => '20.00', 'owner_id' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('audit_log')->insert([
        ['ulid' => (string) Str::ulid(), 'occurred_at' => now(), 'actor_id' => $admin->id, 'event' => 'auth.login.succeeded'],
        ['ulid' => (string) Str::ulid(), 'occurred_at' => now(), 'actor_id' => $admin->id, 'event' => 'auth.login.failed'],
        ['ulid' => (string) Str::ulid(), 'occurred_at' => now(), 'actor_id' => $admin->id, 'event' => 'users.role'],
    ]);
    DB::table('approval_decisions')->insert(['approval_id' => DB::table('approvals')->insertGetId(['type' => 'note', 'requester_id' => $admin->id, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]), 'step' => 0, 'approver_id' => $admin->id, 'decision' => 'approved', 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}
