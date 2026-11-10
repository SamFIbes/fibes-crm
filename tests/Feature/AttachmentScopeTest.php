<?php

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
    Storage::fake('local');
});

function scopedCustomer(User $owner, string $suffix): Customer
{
    return Customer::query()->create([
        'account_no' => 'FB-AS-'.$suffix,
        'type' => 'individual',
        'status' => 'active',
        'name_en' => 'Sample '.$suffix,
        'name_ar' => 'عينة '.$suffix,
        'owner_id' => $owner->id,
    ]);
}

function scopedFile(?string $type, ?int $id, string $name): Attachment
{
    Storage::disk('local')->put('attachments/'.$name, 'synthetic');

    return Attachment::query()->create([
        'disk' => 'local',
        'path' => 'attachments/'.$name,
        'original_name' => $name,
        'mime' => 'text/plain',
        'size' => 9,
        'sha256' => hash('sha256', 'synthetic'),
        'scan_status' => 'clean',
        'scan_result' => 'clean',
        'uploaded_by' => null,
        'attachable_type' => $type,
        'attachable_id' => $id,
    ]);
}

it('lists only files on records the viewer can see, plus files with no record', function () {
    $agent = User::factory()->create(['role' => 'sales_agent', 'must_change_password' => false]);
    $colleague = User::factory()->create(['role' => 'sales_agent']);
    $mine = scopedFile(Customer::class, scopedCustomer($agent, '1')->id, 'mine.txt');
    scopedFile(Customer::class, scopedCustomer($colleague, '2')->id, 'theirs.txt');
    $loose = scopedFile(null, null, 'loose.txt');
    scopedFile('App\\Models\\Nothing', 1, 'unknown.txt');

    $this->actingAs($agent)->get('/files')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Files/Index')
        ->where('files', fn ($files) => collect($files)->pluck('id')->sort()->values()->all() === collect([$mine->id, $loose->id])->sort()->values()->all()));
});

it('refuses a signed download link for a file on a record the viewer cannot see', function () {
    $agent = User::factory()->create(['role' => 'sales_agent', 'must_change_password' => false]);
    $colleague = User::factory()->create(['role' => 'sales_agent']);
    $mine = scopedFile(Customer::class, scopedCustomer($agent, '1')->id, 'mine.txt');
    $theirs = scopedFile(Customer::class, scopedCustomer($colleague, '2')->id, 'theirs.txt');

    $link = fn (Attachment $file) => URL::temporarySignedRoute('files.download', now()->addMinutes(10), ['attachment' => $file->id]);

    $this->actingAs($agent)->get($link($mine))->assertOk();
    $this->actingAs($agent)->get($link($theirs))->assertNotFound();
});

it('will not delete a file the viewer cannot see', function () {
    $manager = User::factory()->create(['role' => 'ops_manager', 'must_change_password' => false]);
    $unknown = scopedFile('App\\Models\\Nothing', 1, 'unknown.txt');
    $loose = scopedFile(null, null, 'loose.txt');

    $this->actingAs($manager)->delete('/files/'.$unknown->id)->assertNotFound();
    expect(Attachment::query()->whereKey($unknown->id)->exists())->toBeTrue();
    Storage::disk('local')->assertExists('attachments/unknown.txt');

    $this->actingAs($manager)->delete('/files/'.$loose->id)->assertRedirect();
    expect(Attachment::query()->whereKey($loose->id)->exists())->toBeFalse();
});
