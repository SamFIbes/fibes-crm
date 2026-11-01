<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\Access\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_policies', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->json('conditions')->nullable();
            $table->json('steps');
            $table->unsignedInteger('timeout_hours')->default(4);
            $table->string('escalate_to', 40)->nullable();
            $table->decimal('comment_above', 8, 2)->nullable();
            $table->boolean('low_risk')->default(false);
            $table->string('pick', 20)->default('chain');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->nullable()->constrained('approval_policies')->nullOnDelete();
            $table->string('type', 40);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('subject_state', 20)->nullable();
            $table->unsignedInteger('current_step')->default(0);
            $table->json('steps')->nullable();
            $table->json('payload')->nullable();
            $table->string('summary')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('step');
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 20);
            $table->text('comment')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });

        $this->guard('approval_decisions');
        $this->seed();
        $this->grant();
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('approval_policies');
    }

    private function seed(): void
    {
        $now = now();
        $rows = [
            ['quote_discount', 'Quote discount', 'خصم عرض السعر', ['auto_percent' => ['sales_agent' => 5, 'cc_agent' => 0]], [
                ['roles' => ['sales_manager'], 'mode' => 'any_of', 'when' => ['percent_above' => 5], 'timeout_hours' => 4],
                ['roles' => ['management'], 'mode' => 'any_of', 'when' => ['percent_above' => 15], 'timeout_hours' => 4],
                ['roles' => ['super_admin'], 'mode' => 'any_of', 'when' => ['percent_above' => 30], 'timeout_hours' => 4],
            ], 4, 'management', '15.00', false, 'chain'],
            ['custom_price', 'Custom price', 'سعر خاص', null, [
                ['roles' => ['sales_manager'], 'mode' => 'any_of', 'timeout_hours' => 4],
                ['roles' => ['management'], 'mode' => 'any_of', 'when' => ['percent_above' => 30], 'timeout_hours' => 4],
            ], 4, 'management', null, false, 'chain'],
            ['free_install', 'Free installation or equipment', 'تركيب أو جهاز مجاني', null, [
                ['roles' => ['sales_manager'], 'mode' => 'any_of', 'timeout_hours' => 8],
                ['roles' => ['management'], 'mode' => 'any_of', 'when' => ['amount_above' => 100], 'timeout_hours' => 8],
            ], 8, 'management', null, false, 'chain'],
            ['refund', 'Refund', 'استرداد', null, [
                ['roles' => ['finance'], 'mode' => 'any_of', 'when' => ['amount_up_to' => 50], 'timeout_hours' => 24],
                ['roles' => ['management'], 'mode' => 'any_of', 'when' => ['amount_above' => 50, 'amount_up_to' => 500], 'timeout_hours' => 24],
                ['roles' => ['super_admin'], 'mode' => 'any_of', 'when' => ['amount_above' => 500], 'timeout_hours' => 24],
            ], 24, 'management', null, false, 'ladder'],
            ['credit_note', 'Credit note', 'إشعار دائن', ['auto_amount' => 20, 'auto_roles' => ['finance']], [
                ['roles' => ['finance'], 'mode' => 'any_of', 'when' => ['amount_above' => 20], 'timeout_hours' => 24],
                ['roles' => ['management'], 'mode' => 'any_of', 'when' => ['amount_above' => 200], 'timeout_hours' => 24],
            ], 24, 'management', null, false, 'chain'],
            ['write_off', 'Write-off', 'شطب', null, [
                ['roles' => ['finance'], 'mode' => 'any_of', 'timeout_hours' => 48],
                ['roles' => ['management'], 'mode' => 'any_of', 'timeout_hours' => 48],
            ], 48, 'super_admin', null, false, 'chain'],
            ['contract_change', 'Contract change', 'تعديل عقد', null, [
                ['roles' => ['sales_manager'], 'mode' => 'any_of', 'timeout_hours' => 24],
                ['roles' => ['management'], 'mode' => 'any_of', 'timeout_hours' => 24],
            ], 24, 'management', null, false, 'chain'],
            ['install_exception', 'Sign at install', 'توقيع عند التركيب', null, [
                ['roles' => ['management', 'super_admin'], 'mode' => 'any_of', 'timeout_hours' => 4],
            ], 4, 'super_admin', null, false, 'chain'],
            ['suspension_exemption', 'Payment extension', 'تمديد الدفع', ['auto_days' => 7, 'auto_roles' => ['cc_supervisor']], [
                ['roles' => ['finance'], 'mode' => 'any_of', 'when' => ['days_above' => 7, 'days_up_to' => 30], 'timeout_hours' => 8],
                ['roles' => ['management'], 'mode' => 'any_of', 'when' => ['days_above' => 30], 'timeout_hours' => 8],
            ], 8, 'management', null, false, 'ladder'],
            ['retention_offer', 'Retention offer', 'عرض الإبقاء', null, [
                ['roles' => ['cc_supervisor'], 'mode' => 'any_of', 'timeout_hours' => 4],
                ['roles' => ['management'], 'mode' => 'any_of', 'when' => ['months_above' => 1], 'timeout_hours' => 4],
            ], 4, 'management', null, false, 'chain'],
            ['radius_bulk', 'Bulk connection change', 'تغيير جماعي للاتصال', null, [
                ['roles' => ['noc_engineer'], 'mode' => 'any_of', 'timeout_hours' => 2],
                ['roles' => ['super_admin'], 'mode' => 'any_of', 'timeout_hours' => 2],
            ], 2, 'super_admin', null, false, 'chain'],
            ['stock_variance', 'Stock count difference', 'فرق جرد المخزون', ['auto_units' => 2], [
                ['roles' => ['warehouse'], 'mode' => 'any_of', 'when' => ['units_above' => 2], 'timeout_hours' => 24],
                ['roles' => ['finance'], 'mode' => 'any_of', 'when' => ['amount_above' => 100], 'timeout_hours' => 24],
            ], 24, 'finance', null, false, 'chain'],
            ['price_list', 'Price list change', 'تغيير قائمة الأسعار', null, [
                ['roles' => ['management'], 'mode' => 'any_of', 'timeout_hours' => 48],
                ['roles' => ['super_admin'], 'mode' => 'any_of', 'timeout_hours' => 48],
            ], 48, 'super_admin', null, false, 'chain'],
            ['plan_migration', 'Move services to a new price', 'نقل الخدمات إلى سعر جديد', null, [
                ['roles' => ['management'], 'mode' => 'any_of', 'timeout_hours' => 48],
                ['roles' => ['super_admin'], 'mode' => 'any_of', 'timeout_hours' => 48],
            ], 48, 'super_admin', null, false, 'chain'],
            ['purchase_order', 'Purchase', 'شراء', ['auto_amount' => 200, 'auto_roles' => ['ops_manager']], [
                ['roles' => ['ops_manager'], 'mode' => 'any_of', 'when' => ['amount_above' => 200], 'timeout_hours' => 24],
                ['roles' => ['management'], 'mode' => 'any_of', 'when' => ['amount_above' => 1000], 'timeout_hours' => 24],
            ], 24, 'management', null, false, 'chain'],
            ['role_change', 'Role change', 'تغيير صلاحية', null, [
                ['roles' => ['super_admin'], 'mode' => 'any_of'],
            ], 0, null, null, false, 'chain'],
            ['customer_deletion', 'Delete a customer record', 'حذف سجل عميل', null, [
                ['roles' => ['super_admin'], 'mode' => 'any_of'],
            ], 0, null, null, false, 'chain'],
            ['referral_reward', 'Referral reward', 'مكافأة الإحالة', null, [
                ['roles' => ['sales_manager', 'management'], 'mode' => 'any_of', 'timeout_hours' => 24],
            ], 24, 'management', null, false, 'chain'],
            ['reseller_agreement', 'Reseller agreement', 'اتفاق الموزع', null, [
                ['roles' => ['management', 'super_admin'], 'mode' => 'any_of', 'timeout_hours' => 24],
            ], 24, 'super_admin', null, false, 'chain'],
            ['promo_approval', 'Promotion that needs approval', 'عرض يحتاج موافقة', null, [
                ['roles' => ['sales_manager', 'management'], 'mode' => 'any_of', 'timeout_hours' => 8],
            ], 8, 'management', null, false, 'chain'],
        ];

        foreach ($rows as $row) {
            DB::table('approval_policies')->insert([
                'key' => $row[0],
                'name_en' => $row[1],
                'name_ar' => $row[2],
                'conditions' => $row[3] === null ? null : json_encode($row[3]),
                'steps' => json_encode($row[4]),
                'timeout_hours' => $row[5],
                'escalate_to' => $row[6],
                'comment_above' => $row[7],
                'low_risk' => $row[8],
                'pick' => $row[9],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function guard(string $table): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION fibes_{$table}_immutable()
                RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION '{$table} is append-only';
                END;
                $$;
                CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION fibes_{$table}_immutable();
                CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION fibes_{$table}_immutable();
            SQL);
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table}
                BEGIN SELECT RAISE(ABORT, '{$table} is append-only'); END;
                CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
                BEGIN SELECT RAISE(ABORT, '{$table} is append-only'); END;
            SQL);
        }
    }

    private function grant(): void
    {
        if (! Schema::hasTable('roles') || Role::query()->doesntExist()) {
            return;
        }

        foreach (['approvals.view', 'approvals.request', 'approvals.decide', 'approvals.manage'] as $key) {
            $meta = Catalog::permissions()[$key] ?? null;

            if (! is_array($meta)) {
                continue;
            }

            $permission = Permission::query()->updateOrCreate(['key' => $key], $meta);

            foreach (Catalog::grants()[$key] ?? [] as $roleKey => $scope) {
                $role = Role::query()->where('key', $roleKey)->first();

                if ($role) {
                    $role->permissions()->syncWithoutDetaching([$permission->id => ['scope' => $scope]]);
                }
            }
        }
    }
};
