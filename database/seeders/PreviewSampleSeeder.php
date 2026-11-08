<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerService;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\LedgerEntry;
use App\Models\NetworkNode;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local and preview sample rows so empty screens can be reviewed.
 * Not called from DatabaseSeeder. Refuses production. Does not email or text anyone.
 */
class PreviewSampleSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('PreviewSampleSeeder refuses to run in production.');
        }

        $admin = User::query()->where('role', 'super_admin')->first();

        if (! $admin instanceof User) {
            throw new RuntimeException('Seed the administrator before the preview sample.');
        }

        $admin->forceFill(['must_change_password' => false])->save();

        if (Customer::query()->where('account_no', 'PREVIEW-1001')->exists()) {
            return;
        }

        $area = Area::query()->first();
        $category = TicketCategory::query()->where('key', 'no_internet')->first()
            ?? TicketCategory::query()->first();

        if (! $category instanceof TicketCategory) {
            throw new RuntimeException('Ticket categories are missing. Run FoundationSeeder first.');
        }

        $customer = Customer::query()->create([
            'account_no' => 'PREVIEW-1001',
            'type' => 'individual',
            'status' => 'active',
            'name_en' => 'Ada Preview',
            'name_ar' => 'آدا للمعاينة',
            'email' => 'ada.preview@example.test',
            'area_id' => $area?->id,
            'building' => 'Preview block',
            'floor' => '2',
            'landmark' => 'Preview landmark',
            'owner_id' => $admin->id,
            'tags' => ['preview'],
            'notes' => 'Preview sample. Not a live subscriber.',
        ]);

        $service = CustomerService::query()->create([
            'customer_id' => $customer->id,
            'service_type' => 'ftth',
            'plan_name' => 'Preview 50',
            'speed' => '50 / 10 Mb',
            'status' => 'active',
            'monthly_usd' => '35.00',
            'install_date' => now()->subMonths(2)->toDateString(),
            'radius_username' => 'preview.ada',
            'ont_serial' => 'PREVIEW-ONT-1',
            'area_id' => $area?->id,
        ]);

        Ticket::query()->create([
            'number' => 'T-PREVIEW-1',
            'customer_id' => $customer->id,
            'customer_service_id' => $service->id,
            'category_id' => $category->id,
            'priority' => 'P2',
            'channel' => 'phone',
            'status' => 'open',
            'subject' => 'Preview line is down',
            'owner_id' => $admin->id,
            'response_minutes' => 15,
            'resolve_minutes' => 240,
            'response_due_at' => now()->subMinutes(30),
            'resolve_due_at' => now()->addHours(3),
            'response_breached' => true,
        ]);

        Ticket::query()->create([
            'number' => 'T-PREVIEW-2',
            'customer_id' => $customer->id,
            'customer_service_id' => $service->id,
            'category_id' => $category->id,
            'priority' => 'P4',
            'channel' => 'email',
            'status' => 'new',
            'subject' => 'Preview billing question',
            'assignee_id' => $admin->id,
            'owner_id' => $admin->id,
            'response_minutes' => 60,
            'resolve_minutes' => 1440,
            'response_due_at' => now()->addHour(),
            'resolve_due_at' => now()->addDay(),
        ]);

        $exchange = NetworkNode::query()->create([
            'kind' => 'exchange',
            'name' => 'Preview exchange',
            'area_id' => $area?->id,
            'notes' => 'Inventory only. Not a live monitor.',
        ]);
        $olt = NetworkNode::query()->create([
            'parent_id' => $exchange->id,
            'kind' => 'olt',
            'name' => 'Preview OLT',
            'area_id' => $area?->id,
        ]);
        NetworkNode::query()->create([
            'parent_id' => $olt->id,
            'kind' => 'pon',
            'name' => 'Preview PON 1',
            'area_id' => $area?->id,
        ]);

        $invoice = Invoice::query()->create([
            'ulid' => (string) Str::ulid(),
            'number' => 'INV-PREVIEW-0001',
            'customer_id' => $customer->id,
            'type' => 'invoice',
            'currency' => 'USD',
            'exchange_rate' => '89500.0000',
            'subtotal' => '35.00',
            'discount' => '0.00',
            'tax' => '3.85',
            'total' => '38.85',
            'lbp_total' => '3477075.00',
            'status' => 'issued',
            'issued_at' => now(),
            'due_at' => now()->addDays(7),
            'created_by' => $admin->id,
        ]);

        LedgerEntry::query()->create([
            'customer_id' => $customer->id,
            'entry_type' => 'invoice',
            'debit' => '38.85',
            'credit' => '0.00',
            'currency' => 'USD',
            'exchange_rate' => '89500.0000',
            'source_type' => 'invoice',
            'source_id' => $invoice->id,
            'balance_after' => '38.85',
            'actor_id' => $admin->id,
        ]);

        Job::query()->create([
            'number' => 'J-PREVIEW-1',
            'type' => 'installation',
            'status' => 'requested',
            'customer_id' => $customer->id,
            'customer_service_id' => $service->id,
            'owner_id' => $admin->id,
            'notes' => 'Preview job with no technician.',
        ]);
    }
}
