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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('kind', 20);
            $table->string('service_type', 30)->nullable();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('offering', 20)->nullable();
            $table->decimal('cost_usd', 14, 2)->nullable();
            $table->foreignId('stock_item_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('down_mbps')->nullable();
            $table->unsignedInteger('up_mbps')->nullable();
            $table->unsignedInteger('burst_mbps')->nullable();
            $table->unsignedInteger('fup_gb')->nullable();
            $table->string('billing_cycle', 20)->default('monthly');
            $table->string('radius_profile_name')->nullable();
            $table->boolean('needs_survey')->default(false);
            $table->unsignedInteger('min_term_months')->default(0);
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 14, 2);
            $table->char('currency', 3)->default('USD');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('customer_type', 30)->nullable();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'valid_from']);
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('type', 20);
            $table->decimal('value', 14, 2)->default(0);
            $table->json('applies_to')->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->boolean('stackable')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->unsignedInteger('min_term_months')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('promotion_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('used_at');
            $table->timestamps();
        });

        Schema::create('price_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 14, 2);
            $table->char('currency', 3)->default('USD');
            $table->date('valid_from');
            $table->string('customer_type', 30)->nullable();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('discount_limits', function (Blueprint $table) {
            $table->id();
            $table->string('role', 40)->unique();
            $table->decimal('line_percent', 8, 2)->default(0);
            $table->decimal('line_amount', 14, 2)->default(0);
            $table->decimal('total_percent', 8, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->timestamps();
        });

        Schema::table('customer_services', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('plan_name')->constrained('plans')->nullOnDelete();
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreign('promo_code_id')->references('id')->on('promotions')->nullOnDelete();
        });

        $this->guard('price_list_items');
        $this->guard('promotion_uses');
        $this->seed();
        $this->grant();
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropForeign(['promo_code_id']);
        });
        Schema::table('customer_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });
        Schema::dropIfExists('discount_limits');
        Schema::dropIfExists('price_change_requests');
        Schema::dropIfExists('promotion_uses');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('products');
    }

    private function seed(): void
    {
        $now = now();
        $plan = DB::table('products')->insertGetId([
            'sku' => 'SMN-8-16',
            'kind' => 'plan',
            'service_type' => 'ftth',
            'name_en' => 'SMN-8MB UP TO 16MB',
            'name_ar' => '٨ ميغا حتى ١٦ ميغا',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('plans')->insert([
            'product_id' => $plan,
            'down_mbps' => 8,
            'up_mbps' => 8,
            'burst_mbps' => 16,
            'billing_cycle' => 'monthly',
            'radius_profile_name' => 'SMN-8MB UP TO 16MB',
            'needs_survey' => true,
            'min_term_months' => 12,
            'is_public' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rows = [
            ['STATIC-IP', 'static_ip', null, 'Static IP', 'عنوان ثابت', null, '5.00'],
            ['INSTALL', 'installation', null, 'Installation', 'رسم التركيب', null, '50.00'],
            ['RELOCATE', 'one_time', null, 'Relocation', 'رسم النقل', null, '25.00'],
            ['RECONNECT', 'one_time', null, 'Reconnection', 'رسم إعادة التوصيل', null, '15.00'],
            ['ONT-SALE', 'equipment', 'ftth', 'Fiber terminal', 'جهاز الألياف', 'sale', '80.00'],
        ];

        $prices = [
            [$plan, '35.00', null],
            [$plan, '40.00', 'business'],
        ];

        foreach ($rows as $row) {
            $id = DB::table('products')->insertGetId([
                'sku' => $row[0],
                'kind' => $row[1],
                'service_type' => $row[2],
                'name_en' => $row[3],
                'name_ar' => $row[4],
                'offering' => $row[5],
                'cost_usd' => $row[0] === 'ONT-SALE' ? '40.00' : null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $prices[] = [$id, $row[6], null];
        }

        foreach ($prices as $price) {
            DB::table('price_list_items')->insert([
                'product_id' => $price[0],
                'price' => $price[1],
                'currency' => 'USD',
                'valid_from' => '2026-01-01',
                'customer_type' => $price[2],
                'note' => 'To confirm by Sam',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('promotions')->insert([
            'code' => 'WELCOME10',
            'name_en' => 'Welcome, 10 percent',
            'name_ar' => 'ترحيب، ١٠ بالمئة',
            'type' => 'percent',
            'value' => '10.00',
            'applies_to' => json_encode(['product_ids' => [], 'plan_ids' => [], 'area_ids' => [], 'customer_types' => []]),
            'valid_from' => '2026-01-01',
            'stackable' => false,
            'requires_approval' => false,
            'min_term_months' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([
            ['sales_agent', '5.00', '5.00', '5.00', '10.00'],
            ['sales_manager', '15.00', '25.00', '15.00', '50.00'],
            ['cc_agent', '0.00', '0.00', '0.00', '0.00'],
            ['cc_supervisor', '5.00', '5.00', '5.00', '10.00'],
        ] as $limit) {
            DB::table('discount_limits')->insert([
                'role' => $limit[0],
                'line_percent' => $limit[1],
                'line_amount' => $limit[2],
                'total_percent' => $limit[3],
                'total_amount' => $limit[4],
                'currency' => 'USD',
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

        foreach (['catalog.view', 'catalog.edit', 'catalog.prices.request', 'catalog.prices.edit', 'catalog.promotions.edit'] as $key) {
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
