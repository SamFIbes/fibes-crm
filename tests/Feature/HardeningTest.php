<?php

use App\Logging\RedactPii;
use App\Models\Customer;
use App\Models\User;
use App\Services\Files\ClamAvScanner;
use App\Services\Files\NullFileScanner;
use App\Services\Observability\ErrorReporter;
use App\Support\Text\ArabicFold;
use Database\Seeders\LoadSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Level;
use Monolog\LogRecord;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('refuses the load seed in production and writes a small proportional set otherwise', function () {
    $this->app['env'] = 'production';
    expect(fn () => (new LoadSeeder)->run())->toThrow(RuntimeException::class);
    $this->app['env'] = 'testing';
    config(['fibes.load.customers' => 4]);
    (new LoadSeeder)->run();

    expect(DB::table('customers')->where('account_no', 'like', 'LD-%')->count())->toBe(4)
        ->and(DB::table('customer_phones')->count())->toBe(4)
        ->and(DB::table('customer_services')->count())->toBe(2)
        ->and(DB::table('tickets')->count())->toBe(1)
        ->and(DB::table('field_jobs')->count())->toBe(1)
        ->and(DB::table('invoices')->count())->toBe(1);
});

it('blocks the eicar test file, scans a clean file, and keeps it behind a signed link', function () {
    Storage::fake('local');
    $admin = User::factory()->superAdmin()->create();
    $this->actingAs($admin)->post('/files', [
        'file' => UploadedFile::fake()->createWithContent('eicar.txt', NullFileScanner::EICAR),
    ])->assertSessionHasErrors('file');
    expect(DB::table('attachments')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('event', 'attachment.rejected')->exists())->toBeTrue()
        ->and(Storage::disk('local')->allFiles('quarantine'))->toBe([]);

    $this->actingAs($admin)->post('/files', [
        'file' => UploadedFile::fake()->createWithContent('plan.txt', 'hello'),
    ])->assertRedirect();
    $row = DB::table('attachments')->first();
    expect($row->scan_status)->toBe('clean')
        ->and($row->scan_result)->toBe('clean')
        ->and($row->scanned_at)->not->toBeNull();
});

it('folds arabic search and leaves customer details out of the log', function () {
    expect(ArabicFold::variants('أحمد'))->toContain('احمد');
    $admin = User::factory()->superAdmin()->create();
    $customer = Customer::query()->create([
        'account_no' => 'FB-AR-1',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Ahmad',
        'name_ar' => 'أحمد',
    ]);
    DB::table('customer_phones')->insert([
        'customer_id' => $customer->id,
        'phone_e164' => '+96171000999',
        'is_primary' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->actingAs($admin)->get('/customers?q='.urlencode('احمد'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('customers.0.account_no', 'FB-AR-1'));

    $seen = '';
    Log::listen(function ($event) use (&$seen) {
        if ($event->message === 'customer.created') {
            $seen .= json_encode($event->context);
        }
    });
    $this->actingAs($admin)->post('/customers', [
        'type' => 'individual',
        'status' => 'lead',
        'name_en' => 'Secret Person',
        'name_ar' => 'سري',
        'email' => 'secret-person@example.test',
        'phones' => ['71 000 998'],
    ])->assertRedirect();
    expect($seen)->toContain('customer_id')
        ->and($seen)->not->toContain('secret-person@example.test')
        ->and($seen)->not->toContain('71000998')
        ->and($seen)->not->toContain('Secret Person');

    $record = new LogRecord(now()->toDateTimeImmutable(), 'test', Level::Info, 'mail secret-person@example.test phone +96171000999', ['email' => 'secret-person@example.test']);
    $clean = (new RedactPii)($record);
    expect($clean->message)->not->toContain('secret-person@example.test')
        ->and($clean->message)->not->toContain('+96171000999')
        ->and($clean->context)->not->toHaveKey('email');
});

it('answers health, stamps a request id, and can post an error without the message secrets', function () {
    $this->get('/health')->assertOk()->assertJson(['status' => 'ok', 'database' => 'ok'])->assertHeader('X-Request-Id');

    Http::fake(['*' => Http::response('ok', 200)]);
    config(['fibes.observability.dsn' => 'https://publickey@errors.example/12']);
    app(ErrorReporter::class)->report(new RuntimeException('secret-person@example.test +96171000999'), 'req-12345678');
    Http::assertSent(function ($request) {
        $body = $request->body();

        return str_contains($body, 'RuntimeException')
            && ! str_contains($body, 'secret-person@example.test')
            && ! str_contains($body, '+96171000999')
            && str_contains($body, 'req-12345678');
    });
});

it('asks a signed-in technician to confirm a password before a signature', function () {
    $tech = User::factory()->create(['role' => 'field_technician']);
    $customer = Customer::query()->create([
        'account_no' => 'FB-RE-1',
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Reauth',
        'name_ar' => 'تأكيد',
    ]);
    $job = DB::table('field_jobs')->insertGetId([
        'number' => 'LD-J-REAUTH',
        'type' => 'repair',
        'status' => 'scheduled',
        'customer_id' => $customer->id,
        'technician_id' => $tech->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->actingAs($tech)->withSession([
        'auth.started_at' => now()->subMinutes(20)->getTimestamp(),
        'auth.seen_at' => now()->getTimestamp(),
    ])->post('/tech/jobs/'.$job.'/signature', [])->assertSessionHasErrors('reauth');

    $this->actingAs($tech)->post('/tech/confirm', ['password' => 'password'])->assertRedirect();
    expect(session('auth.confirmed_at'))->toBeNumeric();
});

it('skips the live clamav socket when nothing is listening', function () {
    $scanner = new ClamAvScanner;
    config(['fibes.attachments.clamav_host' => '127.0.0.1', 'fibes.attachments.clamav_port' => 1]);
    $path = tempnam(sys_get_temp_dir(), 'fibes');
    file_put_contents($path, 'hello');

    expect($scanner->scan($path)['status'])->toBe('error');
})->group('clamav');
