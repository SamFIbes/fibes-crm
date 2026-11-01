<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dunning_policies', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('steps');
            $table->timestamps();
        });

        Schema::create('dunning_fires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->date('period_end');
            $table->string('action', 30);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['subscription_id', 'period_end', 'action']);
        });

        Schema::create('collection_cases', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount_due', 14, 2)->default(0);
            $table->string('stage', 30)->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('promise_to_pay_date')->nullable();
            $table->decimal('promise_amount', 14, 2)->nullable();
            $table->string('outcome')->nullable();
            $table->date('opened_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('collection_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_case_id')->constrained()->cascadeOnDelete();
            $table->string('from_stage', 30)->nullable();
            $table->string('to_stage', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('collection_exemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->date('until')->nullable();
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->guard('collection_events');
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS collection_events_no_update ON collection_events');
            DB::unprepared('DROP TRIGGER IF EXISTS collection_events_no_delete ON collection_events');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS collection_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS collection_events_no_delete');
        }

        Schema::dropIfExists('collection_exemptions');
        Schema::dropIfExists('collection_events');
        Schema::dropIfExists('collection_cases');
        Schema::dropIfExists('dunning_fires');
        Schema::dropIfExists('dunning_policies');
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
