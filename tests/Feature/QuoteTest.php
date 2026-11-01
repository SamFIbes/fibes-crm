<?php

use App\Mail\QuoteMail;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\DocumentTemplate;
use App\Models\ExchangeRate;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteEvent;
use App\Models\User;
use App\Services\Quotes\QuoteMachine;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('walks every allowed and forbidden quote step', function () {
    $machine = new QuoteMachine;

    foreach (QuoteMachine::ALLOWED as $from => $targets) {
        foreach (['draft', 'pending_approval', 'approved', 'sent', 'accepted', 'rejected', 'expired'] as $to) {
            if (in_array($to, $targets, true)) {
                expect($machine->allows($from, $to))->toBeTrue();
            } else {
                expect(fn () => $machine->assert($from, $to))->toThrow(ValidationException::class);
            }
        }
    }
});

it('holds a 12 percent discount for approval and prints the approver afterwards', function () {
    $agent = User::factory()->create(['role' => 'sales_agent', 'name' => 'Lina Agent']);
    $manager = User::factory()->create(['role' => 'sales_manager', 'name' => 'Rami Manager']);
    $plan = Product::query()->where('sku', 'SMN-8-16')->firstOrFail();

    $this->actingAs($agent)->post('/quotes', quotePayload($plan->id, ['discount_pct' => 12, 'submit' => true]))
        ->assertRedirect();

    $quote = Quote::query()->firstOrFail();
    expect($quote->status)->toBe('pending_approval')
        ->and($quote->number)->toStartWith('QT-');
    $this->actingAs($agent)->get('/quotes/'.$quote->id.'/print')->assertStatus(422);

    $approval = $quote->fresh()->approval_id;
    expect($approval)->not->toBeNull();
    $this->actingAs($manager)->post('/approvals/'.$approval.'/approve', [
        'comment' => '12 percent is fine for this home plan.',
    ])->assertRedirect();

    $quote->refresh();
    expect($quote->status)->toBe('approved')
        ->and($quote->approver_name)->toBe('Rami Manager');
    $this->actingAs($agent)->get('/quotes/'.$quote->id.'/print')
        ->assertOk()
        ->assertSee('Approved by Rami Manager')
        ->assertSee('dir="ltr"', false);
    expect(DB::table('audit_log')->where('event', 'quote.approved')->exists())->toBeTrue();
    expect(DB::table('audit_log')->where('event', 'quote.priced')->exists())->toBeTrue();
});

it('lets a super admin discount without a cap and sends an Arabic quote right to left', function () {
    $admin = User::factory()->superAdmin()->create();
    $plan = Product::query()->where('sku', 'SMN-8-16')->firstOrFail();

    $this->actingAs($admin)->post('/quotes', quotePayload($plan->id, [
        'discount_pct' => 12,
        'language' => 'ar',
        'submit' => true,
        'customer_name' => 'نور حداد',
    ]))->assertRedirect();

    $quote = Quote::query()->firstOrFail();
    expect($quote->status)->toBe('approved');
    $this->actingAs($admin)->get('/quotes/'.$quote->id.'/print')
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee('عرض السعر', false)
        ->assertSee('الأسعار والضريبة وشروط العقد في هذه الصفحة ما زالت بحاجة إلى تأكيد', false);
});

it('stores the day rate on an LBP quote and rounds to the nearest thousand', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $plan = Product::query()->where('sku', 'SMN-8-16')->firstOrFail();
    ExchangeRate::query()->create([
        'effective_on' => now()->toDateString(),
        'usd_lbp' => '90000.0000',
        'confirmed' => false,
        'note' => 'To confirm',
        'set_by' => $agent->id,
    ]);

    $this->actingAs($agent)->post('/quotes', quotePayload($plan->id, [
        'currency' => 'LBP',
        'apply_vat' => 0,
        'submit' => true,
    ]))->assertRedirect();

    $quote = Quote::query()->firstOrFail();
    expect($quote->currency)->toBe('LBP')
        ->and((string) $quote->exchange_rate)->toBe('90000.0000')
        ->and((string) $quote->total)->toBe('3150000.00')
        ->and($quote->status)->toBe('approved');
});

it('keeps the old version when an approved quote is edited', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $plan = Product::query()->where('sku', 'SMN-8-16')->firstOrFail();
    $this->actingAs($agent)->post('/quotes', quotePayload($plan->id, ['submit' => true, 'apply_vat' => 0]))->assertRedirect();
    $original = Quote::query()->firstOrFail();
    expect($original->status)->toBe('approved')->and((string) $original->total)->toBe('35.00');

    $this->actingAs($agent)->post('/quotes/'.$original->id.'/revise')->assertRedirect();
    $copy = Quote::query()->where('version', 2)->firstOrFail();
    expect($copy->number)->toBe($original->number)
        ->and($copy->status)->toBe('draft')
        ->and($original->fresh()->status)->toBe('approved')
        ->and((string) $original->fresh()->total)->toBe('35.00');

    $this->actingAs($agent)->put('/quotes/'.$copy->id, quotePayload($plan->id, [
        'qty' => 2,
        'apply_vat' => 0,
    ]))->assertRedirect();

    expect((string) $copy->fresh()->lines()->first()->qty)->toBe('2.00')
        ->and((string) $original->fresh()->lines()->first()->qty)->toBe('1.00');
    $this->actingAs($agent)->put('/quotes/'.$original->id, quotePayload($plan->id))->assertSessionHasErrors('status');
});

it('rejects a tampered or expired public link and keeps the signed file when the template changes', function () {
    Mail::fake();
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $plan = Product::query()->where('sku', 'SMN-8-16')->firstOrFail();
    $this->actingAs($agent)->post('/quotes', quotePayload($plan->id, [
        'submit' => true,
        'apply_vat' => 0,
        'customer_email' => 'nour@example.com',
        'customer_name' => 'Nour Haddad',
    ]))->assertRedirect();
    $quote = Quote::query()->firstOrFail();

    $this->actingAs($agent)->post('/quotes/'.$quote->id.'/send', ['channel' => 'email'])->assertRedirect();
    Mail::assertSent(QuoteMail::class, fn (QuoteMail $mail) => $mail->hasTo('nour@example.com'));
    $link = session('link');
    expect($link)->toBeString()->and($link)->toContain('signature=');
    $this->get($link)->assertOk();
    $this->get(preg_replace('/signature=[^&]+/', 'signature=tampered', (string) $link))->assertForbidden();
    $this->get(URL::temporarySignedRoute('quotes.public', now()->subMinute(), ['quote' => $quote->id]))->assertForbidden();

    $quote->valid_until = now()->subDay()->toDateString();
    $quote->save();
    $this->post(URL::temporarySignedRoute('quotes.public.accept', now()->addHour(), ['quote' => $quote->id]))
        ->assertSessionHasErrors('quote');
    expect($quote->fresh()->status)->toBe('expired');

    $quote->valid_until = now()->addDays(7)->toDateString();
    $quote->status = 'sent';
    $quote->save();
    $this->actingAs($agent)->post('/quotes/'.$quote->id.'/accept')->assertRedirect();
    $this->actingAs($agent)->post('/quotes/'.$quote->id.'/contract', ['term_months' => 12])->assertRedirect();
    $contract = Contract::query()->firstOrFail();

    $sign = URL::temporarySignedRoute('contracts.public', now()->addHour(), ['contract' => $contract->id]);
    $this->get($sign)->assertOk();
    $this->get($sign.'x')->assertForbidden();
    $this->get(URL::temporarySignedRoute('contracts.public', now()->subMinute(), ['contract' => $contract->id]))->assertForbidden();

    $otp = URL::temporarySignedRoute('contracts.public.otp', now()->addHour(), ['contract' => $contract->id]);
    $this->post($otp)->assertRedirect()->assertDontSee(Cache::get('fibes-sms:'.$contract->id));
    $code = Cache::get('fibes-sms:'.$contract->id);
    expect($code)->toBeString()->and(session('status'))->not->toBe($code);

    $this->post(URL::temporarySignedRoute('contracts.public.sign', now()->addHour(), ['contract' => $contract->id]), [
        'signer_name' => 'Nour Haddad',
        'consent' => 1,
        'otp' => $code,
        'signature' => tinyPng(),
    ])->assertRedirect();

    $contract->refresh();
    expect($contract->status)->toBe('signed')
        ->and($contract->sha256)->toBe(hash('sha256', (string) $contract->snapshot_html))
        ->and($contract->signer_name)->toBe('Nour Haddad');

    DocumentTemplate::query()->whereKey($contract->template_id)->update(['body' => 'CHANGED TEMPLATE {{customer.name}}']);
    $printed = $this->actingAs($agent)->get('/contracts/'.$contract->id.'/print');
    $printed->assertOk()->assertDontSee('CHANGED TEMPLATE');
    expect($contract->fresh()->sha256)->toBe($contract->sha256)
        ->and(hash('sha256', (string) $printed->getContent()))->toBe($contract->sha256);
    expect(fn () => $contract->update(['signer_name' => 'Someone else']))->toThrow(RuntimeException::class);

    $event = QuoteEvent::query()->firstOrFail();
    expect(fn () => $event->update(['to_status' => 'draft']))->toThrow(RuntimeException::class);
    expect(fn () => DB::table('quote_events')->where('id', $event->id)->delete())->toThrow(QueryException::class);
});

it('wins a deal from an accepted quote and a signed contract, and still asks for a manager without them', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $plan = Product::query()->where('sku', 'SMN-8-16')->firstOrFail();
    $pipeline = Pipeline::query()->where('key', 'retail')->firstOrFail();
    $stage = PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('key', 'negotiation')->firstOrFail();
    $customer = Customer::query()->create([
        'account_no' => 'FB-QUOTE-1',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Nour Haddad',
        'name_ar' => 'نور حداد',
    ]);
    $deal = Opportunity::query()->create([
        'customer_id' => $customer->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $stage->id,
        'owner_id' => $agent->id,
        'service_type' => 'ftth',
        'mrc' => '35.00',
        'currency' => 'USD',
        'stage_entered_at' => now(),
    ]);

    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', ['stage' => 'won'])
        ->assertSessionHasErrors('override_reason');
    expect($deal->fresh()->stage->key)->toBe('negotiation');

    $this->actingAs($agent)->post('/quotes', quotePayload($plan->id, [
        'submit' => true,
        'apply_vat' => 0,
        'opportunity_id' => $deal->id,
        'customer_name' => 'Nour Haddad',
    ]))->assertRedirect();
    $quote = Quote::query()->firstOrFail();
    $this->actingAs($agent)->post('/quotes/'.$quote->id.'/send', ['channel' => 'link'])->assertRedirect();
    $this->actingAs($agent)->post('/quotes/'.$quote->id.'/accept')->assertRedirect();
    $this->actingAs($agent)->post('/quotes/'.$quote->id.'/contract')->assertRedirect();
    $contract = Contract::query()->firstOrFail();
    $this->actingAs($agent)->post('/contracts/'.$contract->id.'/otp')->assertRedirect();
    $this->actingAs($agent)->post('/contracts/'.$contract->id.'/sign', [
        'signer_name' => 'Nour Haddad',
        'consent' => 1,
        'otp' => Cache::get('fibes-sms:'.$contract->id),
        'signature' => tinyPng(),
    ])->assertRedirect();

    $this->actingAs($agent)->post('/opportunities/'.$deal->id.'/stage', ['stage' => 'won'])->assertRedirect();
    expect($deal->fresh()->stage->key)->toBe('won');
});

it('hides another agent quote and blocks a warehouse user', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $other = User::factory()->create(['role' => 'sales_agent']);
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $plan = Product::query()->where('sku', 'SMN-8-16')->firstOrFail();
    $this->actingAs($agent)->post('/quotes', quotePayload($plan->id))->assertRedirect();
    $quote = Quote::query()->firstOrFail();

    $this->actingAs($other)->get('/quotes/'.$quote->id.'/print')->assertForbidden();
    $this->actingAs($warehouse)->get('/quotes')->assertForbidden();
    $this->actingAs($agent)->get('/quotes')->assertOk();
});

it('asks for a custom price approval and a free installation approval', function () {
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $plan = Product::query()->where('sku', 'SMN-8-16')->firstOrFail();
    $install = Product::query()->where('sku', 'INSTALL')->firstOrFail();

    $this->actingAs($agent)->post('/quotes', quotePayload($plan->id, [
        'unit_price' => '20.00',
        'submit' => true,
        'apply_vat' => 0,
    ]))->assertRedirect();
    expect(Quote::query()->first()->status)->toBe('pending_approval');
    expect(DB::table('approvals')->where('type', 'custom_price')->where('status', 'pending')->exists())->toBeTrue();

    $this->actingAs($agent)->post('/quotes', quotePayload($install->id, [
        'discount_pct' => 100,
        'submit' => true,
        'apply_vat' => 0,
    ]))->assertRedirect();
    expect(DB::table('approvals')->where('type', 'free_install')->exists())->toBeTrue();
});

function quotePayload(int $productId, array $extra = []): array
{
    return array_merge([
        'product_id' => $productId,
        'qty' => 1,
        'discount_pct' => 0,
        'currency' => 'USD',
        'language' => 'en',
        'apply_vat' => 0,
        'customer_name' => 'Nour Haddad',
    ], $extra);
}

function tinyPng(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
}
