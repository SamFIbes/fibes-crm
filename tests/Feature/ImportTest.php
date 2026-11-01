<?php

use App\Jobs\BuildCustomerExport;
use App\Mail\ReportMail;
use App\Models\Customer;
use App\Models\User;
use App\Services\Imports\BackupDesk;
use App\Services\Imports\ImportDesk;
use App\Services\Imports\ImportMachine;
use App\Services\Settings\CommercialSettings;
use App\Services\Settings\SettingsStore;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('walks every allowed and forbidden import step', function () {
    $machine = new ImportMachine;

    foreach (ImportMachine::ALLOWED as $from => $targets) {
        foreach (array_keys(ImportMachine::ALLOWED) as $to) {
            if (in_array($to, $targets, true)) {
                expect($machine->allows($from, $to))->toBeTrue();
            } else {
                expect(fn () => $machine->assert($from, $to))->toThrow(ValidationException::class);
            }
        }
    }
});

it('rejects a renamed column and keeps the dry run equal to the save', function () {
    config(['fibes.session.idle_minutes' => 10000, 'fibes.session.absolute_minutes' => 10000]);
    $admin = User::factory()->superAdmin()->create();
    $csv = "name,phone\nAda,71 000 111\nAda,71 000 111\nBea,71 000 222\n";
    $bad = ['name' => 'full_name', 'phone' => 'phone'];
    $this->actingAs($admin)->post('/imports/preview', ['type' => 'customers', 'csv' => $csv, 'mapping' => $bad])
        ->assertSessionHasErrors('mapping');
    expect(Customer::query()->count())->toBe(0)
        ->and(DB::table('import_batches')->count())->toBe(0);

    $mapping = ['name' => 'name', 'phone' => 'phone'];
    $preview = app(ImportDesk::class)->preview('customers', $csv, $mapping);
    $this->actingAs($admin)->post('/imports', ['type' => 'customers', 'csv' => $csv, 'mapping' => $mapping])->assertRedirect();
    $batch = DB::table('import_batches')->first();
    expect(json_decode((string) $batch->counts, true))->toMatchArray(['created' => $preview['created'], 'skipped' => $preview['skipped']])
        ->and(Customer::query()->whereNull('archived_at')->count())->toBe(2);

    $this->actingAs($admin)->post('/imports/'.$batch->id.'/undo')->assertRedirect();
    expect(DB::table('customers')->whereNotNull('archived_at')->count())->toBe(2)
        ->and(Customer::query()->whereNull('archived_at')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('event', 'import.undone')->exists())->toBeTrue();

    $again = app(ImportDesk::class)->apply('customers', "name,phone\nCara,71 000 333\n", $mapping, $admin, Request::create('/'));
    $this->travel(25)->hours();
    $this->actingAs($admin)->post('/imports/'.$again->id.'/undo')->assertSessionHasErrors('undo');
});

it('reads a synthetic krypton file and exports only the customers a person can see', function () {
    config(['fibes.session.idle_minutes' => 10000, 'fibes.session.absolute_minutes' => 10000]);
    $admin = User::factory()->superAdmin()->create();
    $agent = User::factory()->create(['role' => 'sales_agent']);
    $other = User::factory()->create(['role' => 'sales_agent']);
    $mine = Customer::query()->create(['account_no' => 'FB-EX-1', 'type' => 'individual', 'status' => 'active', 'name_en' => 'Mine', 'name_ar' => 'لي', 'owner_id' => $agent->id]);
    Customer::query()->create(['account_no' => 'FB-EX-2', 'type' => 'individual', 'status' => 'active', 'name_en' => 'Hidden', 'name_ar' => 'مخفي', 'owner_id' => $other->id]);
    DB::table('customer_phones')->insert(['customer_id' => $mine->id, 'phone_e164' => '+96171000333', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($admin)->post('/imports/krypton', ['csv' => "Name,Username\nAda,ada\n"])->assertSessionHasErrors('krypton');

    $figures = app(CommercialSettings::class)->all();
    $figures['krypton_import'] = true;
    app(SettingsStore::class)->put('commercial', $figures, $admin, Request::create('/'));
    $csv = "Name,Username,Status,Phone Number,Mobile Number\nAda One,ada,active,71 000 333,\nBea Two,bea,active,71 000 444,\nBad,bad,active,not-a-phone,\n";
    $preview = app(ImportDesk::class)->kryptonPreview($csv);
    expect($preview['new'])->toBe(1)->and($preview['changed'])->toBe(1)->and($preview['errors'])->toHaveCount(1)->and($preview['missing'])->toBe(0)->and($preview['worklist'])->toBe([]);
    $batch = app(ImportDesk::class)->kryptonApply($csv, $admin, Request::create('/'));
    expect(Customer::query()->find($mine->id)?->name_en)->toBe('Ada One');
    app(ImportDesk::class)->undo((int) $batch->id, $admin, Request::create('/'));
    expect(Customer::query()->find($mine->id)?->name_en)->toBe('Mine');
    $left = Customer::query()->create(['account_no' => 'FB-EX-3', 'type' => 'individual', 'status' => 'active', 'name_en' => 'Left Out', 'name_ar' => 'خارج', 'owner_id' => $admin->id]);
    DB::table('customer_phones')->insert(['customer_id' => $left->id, 'phone_e164' => '+96176000111', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
    $linked = app(ImportDesk::class)->kryptonPreview($csv);
    expect($linked['missing'])->toBe(1)->and($linked['worklist'][0]['phone'])->toBe('+96176000111')->and($linked['worklist'][0]['name'])->toBe('Left Out');
    $this->actingAs($agent)->post('/exports/customers')->assertRedirect();
    $job = DB::table('export_jobs')->first();
    $body = Storage::disk('local')->get($job->path);
    expect($body)->toContain('Mine')->not->toContain('Hidden')
        ->and(DB::table('audit_log')->where('event', 'export.created')->exists())->toBeTrue();
    $this->get('/exports/'.$job->token)->assertOk();
    $figures['report_async_rows'] = 1;
    app(SettingsStore::class)->put('commercial', $figures, $admin, Request::create('/'));
    Queue::fake();
    $this->actingAs($agent)->post('/exports/customers')->assertRedirect()->assertSessionHas('status');
    Queue::assertPushed(BuildCustomerExport::class);
    expect(DB::table('export_jobs')->orderByDesc('id')->value('status'))->toBe('pending');
    $this->travel(25)->hours();
    $this->get('/exports/'.$job->token)->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 'warehouse']))->get('/imports')->assertForbidden();
});

it('encrypts a backup and restores it only into a scratch database', function () {
    $directory = sys_get_temp_dir().'/fibes-backups-'.uniqid();
    $scratch = sys_get_temp_dir().'/fibes-scratch-'.uniqid().'.sqlite';
    config([
        'fibes.backup.key' => str_repeat('k', 32),
        'fibes.backup.directory' => $directory,
        'fibes.backup.scratch' => $scratch,
        'fibes.backup.destination' => '',
    ]);
    $file = app(BackupDesk::class)->run();
    $bytes = (string) file_get_contents($file);
    expect($bytes)->not->toContain('COUNT')->and($bytes)->not->toContain('CREATE TABLE');

    $admin = User::factory()->superAdmin()->create();
    Mail::fake();
    $counts = app(BackupDesk::class)->restoreTest();
    expect($counts)->toHaveKey('customers')
        ->and((int) (new PDO('sqlite:'.$scratch))->query('select total from backup_probe where name = "customers"')->fetchColumn())->toBe($counts['customers']);
    Mail::assertSent(ReportMail::class);
    expect(fn () => app(BackupDesk::class)->assertScratch($scratch, $scratch, 'local'))->toThrow(RuntimeException::class);
    expect(fn () => app(BackupDesk::class)->assertScratch($scratch, $directory, 'production'))->toThrow(RuntimeException::class);

    $output = Artisan::output();
    Artisan::call('backups:restore-test');
    expect(Artisan::output())->toContain('customers');
    expect($admin->id)->toBeGreaterThan(0);
    expect($output)->toBeString();
});
