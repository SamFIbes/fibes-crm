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
        Schema::create('integration_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('integration', 40)->index();
            $table->string('action', 80);
            $table->string('status', 20);
            $table->string('message', 500)->nullable();
            $table->json('context')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['integration', 'status']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable()->unique();
            $table->string('title');
            $table->decimal('amount_usd', 14, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('network_node_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('field_jobs')->nullOnDelete();
            $table->string('erp_name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable()->unique();
            $table->string('status', 20)->default('draft');
            $table->string('purpose', 40)->default('Purchase');
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('warehouse')->nullable();
            $table->date('required_by')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('network_node_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('field_jobs')->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->string('erp_name')->nullable();
            $table->text('remarks')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('stock_items', function (Blueprint $table) {
            $table->string('erp_item_code')->nullable()->after('sku');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('network_node_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
        });

        // Adding columns on SQLite rebuilds the table and drops the append-only triggers.
        $this->guardStockMovements();
        $this->grant();
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_id');
            $table->dropConstrainedForeignId('network_node_id');
            $table->dropConstrainedForeignId('customer_id');
        });
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn('erp_item_code');
        });
        Schema::dropIfExists('stock_requests');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('integration_attempts');
    }

    private function guardStockMovements(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION fibes_stock_movements_immutable()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'stock_movements is append-only';
                END;
                $$;

                DROP TRIGGER IF EXISTS stock_movements_no_update ON stock_movements;
                DROP TRIGGER IF EXISTS stock_movements_no_delete ON stock_movements;

                CREATE TRIGGER stock_movements_no_update
                BEFORE UPDATE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION fibes_stock_movements_immutable();

                CREATE TRIGGER stock_movements_no_delete
                BEFORE DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION fibes_stock_movements_immutable();
            SQL);
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS stock_movements_no_update;
                DROP TRIGGER IF EXISTS stock_movements_no_delete;

                CREATE TRIGGER stock_movements_no_update
                BEFORE UPDATE ON stock_movements
                BEGIN
                    SELECT RAISE(ABORT, 'stock_movements is append-only');
                END;

                CREATE TRIGGER stock_movements_no_delete
                BEFORE DELETE ON stock_movements
                BEGIN
                    SELECT RAISE(ABORT, 'stock_movements is append-only');
                END;
            SQL);
        }
    }

    private function grant(): void
    {
        if (! Schema::hasTable('roles') || Role::query()->doesntExist()) {
            return;
        }

        foreach (['integrations.view', 'integrations.manage'] as $key) {
            $meta = Catalog::permissions()[$key] ?? null;

            if ($meta === null) {
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
