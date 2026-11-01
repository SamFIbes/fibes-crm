<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('name');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('unit', 20);
            $table->boolean('serialized')->default(false);
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->decimal('unit_cost_usd', 14, 2)->default(0);
            $table->boolean('low_stock_alerted')->default(false);
            $table->timestamps();
        });

        Schema::create('stock_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->string('serial');
            $table->string('mac')->nullable();
            $table->string('status', 30)->index();
            $table->foreignId('location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
            $table->foreignId('customer_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('field_jobs')->nullOnDelete();
            $table->timestamps();
            $table->unique(['item_id', 'serial']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('stock_serials')->nullOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost_usd', 14, 2);
            $table->string('kind', 20);
            $table->string('reason')->nullable();
            $table->foreignId('job_id')->nullable()->constrained('field_jobs')->nullOnDelete();
            $table->foreignId('customer_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('field_jobs')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('stock_items')->restrictOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('stock_serials')->nullOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->cascadeOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('status', 20)->default('held');
            $table->timestamps();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('stock_locations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->decimal('expected_qty', 14, 3);
            $table->decimal('counted_qty', 14, 3);
            $table->timestamps();
        });

        $this->guard('stock_movements');
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_update ON stock_movements');
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_delete ON stock_movements');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_delete');
        }

        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_serials');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('stock_locations');
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
};
