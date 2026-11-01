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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('number')->nullable()->unique();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('status', 30)->index();
            $table->string('paused_status', 30)->nullable();
            $table->date('requested_on')->nullable();
            $table->date('sla_due')->nullable();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->text('hold_reason')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 18, 4)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('return_charge', 14, 2)->default(0);
            $table->boolean('deposit_paid')->default(false);
            $table->boolean('billing_confirmed')->default(false);
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
            $table->string('description');
            $table->decimal('qty', 14, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->boolean('is_recurring')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('number')->nullable()->unique();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->index();
            $table->string('erp_ref')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->decimal('total', 14, 2)->default(0);
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->decimal('qty', 14, 3);
            $table->decimal('received_qty', 14, 3)->default(0);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->date('expected_on')->nullable();
            $table->timestamps();
        });

        Schema::table('field_jobs', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('opportunity_id')->constrained()->nullOnDelete();
        });

        Schema::table('customer_services', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->string('termination_reason')->nullable();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('whatsapp_consent')->default(false);
        });

        DB::table('suppliers')->insert([
            'name' => 'Local supplier (to confirm)',
            'notes' => 'Placeholder until Sam names the suppliers.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['removal', 'Collect the equipment', 'استلم المعدات'],
            ['equipment_swap', 'Swap the equipment', 'بدّل الجهاز'],
        ] as $index => [$type, $en, $ar]) {
            DB::table('checklist_templates')->insert([
                'kind' => 'job',
                'job_type' => $type,
                'label_en' => $en,
                'label_ar' => $ar,
                'required' => true,
                'sort' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->guard('order_events');
        $this->grant();
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS order_events_no_update ON order_events');
            DB::unprepared('DROP TRIGGER IF EXISTS order_events_no_delete ON order_events');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS order_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS order_events_no_delete');
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('whatsapp_consent');
        });
        Schema::table('customer_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropColumn(['subscription_id', 'activated_at', 'suspended_at', 'suspension_reason', 'terminated_at', 'termination_reason']);
        });
        Schema::table('field_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }

    private function guard(string $table): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION fibes_{$table}_immutable()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION '{$table} is append-only';
                END;
                $$;

                CREATE TRIGGER {$table}_no_update
                BEFORE UPDATE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION fibes_{$table}_immutable();

                CREATE TRIGGER {$table}_no_delete
                BEFORE DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION fibes_{$table}_immutable();
            SQL);
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_no_update
                BEFORE UPDATE ON {$table}
                BEGIN
                    SELECT RAISE(ABORT, '{$table} is append-only');
                END;

                CREATE TRIGGER {$table}_no_delete
                BEFORE DELETE ON {$table}
                BEGIN
                    SELECT RAISE(ABORT, '{$table} is append-only');
                END;
            SQL);
        }
    }

    private function grant(): void
    {
        if (! Schema::hasTable('roles') || Role::query()->doesntExist()) {
            return;
        }

        foreach (['orders.view', 'orders.activate', 'purchasing.create', 'purchasing.receive'] as $key) {
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
