<?php

use App\Models\Area;
use App\Models\District;
use App\Models\ExchangeRate;
use App\Models\Governorate;
use App\Models\User;
use App\Notifications\WorkspaceNotification;
use App\Support\Phone\PhoneNumbers;
use Database\Seeders\FoundationSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(FoundationSeeder::class);
});

it('normalizes Lebanese numbers to E.164 using libphonenumber metadata', function () {
    $util = PhoneNumberUtil::getInstance();
    $phones = new PhoneNumbers;
    $mobile = $util->getExampleNumberForType('LB', PhoneNumberType::MOBILE);
    $landline = $util->getExampleNumberForType('LB', PhoneNumberType::FIXED_LINE);
    $e164 = $util->format($mobile, PhoneNumberFormat::E164);
    $national = $util->format($mobile, PhoneNumberFormat::NATIONAL);

    expect($phones->normalize($national))->toBe($e164)
        ->and($phones->normalize('00'.ltrim($e164, '+')))->toBe($e164)
        ->and($phones->normalize($e164))->toBe($e164)
        ->and($phones->display($e164))->toStartWith('+961')
        ->and($phones->normalize($util->format($landline, PhoneNumberFormat::NATIONAL)))->toBe($util->format($landline, PhoneNumberFormat::E164));

    expect(fn () => $phones->normalize('02 123'))->toThrow(InvalidArgumentException::class);
});

it('seeds nine governorates and twenty six districts', function () {
    expect(Governorate::query()->count())->toBe(9)
        ->and(District::query()->count())->toBe(26)
        ->and(Area::query()->where('name_en', 'Sin el Fil')->exists())->toBeTrue();
});

it('lets an operations manager add an area and refuses a warehouse user', function () {
    $ops = User::factory()->create(['role' => 'ops_manager']);
    $warehouse = User::factory()->create(['role' => 'warehouse']);
    $district = District::query()->where('name_en', 'Matn')->firstOrFail();

    $this->actingAs($warehouse)->get('/geography')->assertForbidden();
    $this->actingAs($ops)->post('/geography/areas', [
        'district_id' => $district->id,
        'name_en' => 'Dbayeh',
        'name_ar' => 'الضبية',
    ])->assertRedirect();

    expect(Area::query()->where('name_en', 'Dbayeh')->exists())->toBeTrue();
    expect(DB::table('audit_log')->where('event', 'geography.area.created')->exists())->toBeTrue();
});

it('stores editable service targets and rejects a bad phone', function () {
    Mail::fake();
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs(User::factory()->create(['role' => 'field_technician']))->get('/settings')->assertForbidden();

    $this->actingAs($admin)->put('/settings', settingsPayload(['company' => ['phone' => '02 000']]))
        ->assertSessionHasErrors('company.phone');

    $this->actingAs($admin)->put('/settings', settingsPayload([
        'company' => ['phone' => '03 123 456', 'name' => 'FIBES ISP'],
        'sla' => ['support_hours' => '24/7', 'priorities' => ['P1' => ['resolve_minutes' => 1440]]],
    ]))->assertRedirect();

    $sla = DB::table('settings')->where('key', 'sla')->value('value');
    $company = DB::table('settings')->where('key', 'company')->value('value');
    expect(json_decode($sla, true)['support_hours'])->toBe('24/7')
        ->and(json_decode($sla, true)['priorities']['P1']['resolve_minutes'])->toBe(1440)
        ->and(json_decode($company, true)['phone_e164'])->toStartWith('+961')
        ->and($admin->fresh()->notifications)->toHaveCount(1);
    expect(DB::table('audit_log')->where('event', 'settings.updated')->exists())->toBeTrue();
});

it('keeps exchange rates append-only and finance-controlled', function () {
    $finance = User::factory()->create(['role' => 'finance']);
    $agent = User::factory()->create(['role' => 'sales_agent']);

    $this->actingAs($agent)->post('/rates', [
        'effective_on' => '2026-11-01',
        'usd_lbp' => '90000',
    ])->assertForbidden();

    $this->actingAs($finance)->post('/rates', [
        'effective_on' => '2026-11-01',
        'usd_lbp' => '90000.5000',
        'note' => 'desk rate',
    ])->assertRedirect();

    $rate = ExchangeRate::query()->whereDate('effective_on', '2026-11-01')->firstOrFail();
    expect($rate->confirmed)->toBeTrue()
        ->and((string) $rate->usd_lbp)->toBe('90000.5000');
    expect(fn () => $rate->update(['note' => 'changed']))->toThrow(RuntimeException::class);
    expect(fn () => DB::table('exchange_rates')->where('id', $rate->id)->delete())->toThrow(QueryException::class);
    expect(DB::table('audit_log')->where('event', 'exchange_rate.recorded')->exists())->toBeTrue();
});

it('stores a private file behind a signed link and refuses the wrong type', function () {
    Storage::fake('local');
    config(['fibes.attachments.max_kilobytes' => 100]);
    $admin = User::factory()->superAdmin()->create();
    $agent = User::factory()->create(['role' => 'sales_agent']);

    $this->actingAs($agent)->post('/files', [
        'file' => UploadedFile::fake()->create('note.exe', 10, 'application/octet-stream'),
    ])->assertSessionHasErrors('file');

    $this->actingAs($admin)->post('/files', [
        'file' => UploadedFile::fake()->create('plan.pdf', 20, 'application/pdf'),
    ])->assertRedirect();

    $id = DB::table('attachments')->value('id');
    $this->actingAs($admin)->get('/files/'.$id.'/download')->assertForbidden();

    $url = URL::temporarySignedRoute('files.download', now()->addMinutes(5), ['attachment' => $id]);
    $this->actingAs($admin)->get($url)->assertOk();
    expect(DB::table('audit_log')->where('event', 'attachment.stored')->exists())->toBeTrue();
    expect((new WorkspaceNotification('Hello', 'Body'))->tries)->toBe(3);
});

function settingsPayload(array $overrides = []): array
{
    $sla = config('fibes.sla');
    $payload = [
        'company' => [
            'name' => 'FIBES',
            'name_ar' => 'فايبس',
            'email' => 'hello@fibes.test',
            'phone' => '',
            'address_en' => 'Beirut',
            'address_ar' => 'بيروت',
        ],
        'sla' => [
            'clock' => 'calendar',
            'support_hours' => $sla['support_hours'],
            'lead_first_response_minutes' => $sla['lead_first_response_minutes'],
            'priorities' => $sla['priorities'],
        ],
    ];

    $payload['company'] = array_replace($payload['company'], $overrides['company'] ?? []);
    if (isset($overrides['sla'])) {
        $payload['sla'] = array_replace_recursive($payload['sla'], $overrides['sla']);
    }

    return $payload;
}
