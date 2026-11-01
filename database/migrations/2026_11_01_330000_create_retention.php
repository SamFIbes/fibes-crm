<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_cases', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 30)->index();
            $table->string('reason', 40)->nullable();
            $table->string('outcome')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('opened_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('retention_offers', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('retention_case_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedSmallInteger('months')->default(1);
            $table->decimal('percent', 8, 2)->default(0);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('catalog_price', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('status', 20);
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('retention_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retention_case_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage', 30)->nullable();
            $table->string('to_stage', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('retention_fires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['customer_id', 'action']);
        });

        $this->guard('retention_events');

        DB::table('approval_policies')->where('key', 'retention_offer')->update([
            'steps' => json_encode([
                ['roles' => ['cc_supervisor'], 'mode' => 'any_of', 'when' => ['months_up_to' => 1, 'percent_up_to' => 5], 'timeout_hours' => 4],
                ['roles' => ['management'], 'mode' => 'any_of', 'when' => ['months_above' => 1], 'timeout_hours' => 4],
                ['roles' => ['management'], 'mode' => 'any_of', 'when' => ['percent_above' => 5], 'timeout_hours' => 4],
            ]),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS retention_events_no_update ON retention_events');
            DB::unprepared('DROP TRIGGER IF EXISTS retention_events_no_delete ON retention_events');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS retention_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS retention_events_no_delete');
        }

        Schema::dropIfExists('retention_fires');
        Schema::dropIfExists('retention_events');
        Schema::dropIfExists('retention_offers');
        Schema::dropIfExists('retention_cases');
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
                BEGIN
                    SELECT RAISE(ABORT, '{$table} is append-only');
                END;
                CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
                BEGIN
                    SELECT RAISE(ABORT, '{$table} is append-only');
                END;
            SQL);
        }
    }
};
