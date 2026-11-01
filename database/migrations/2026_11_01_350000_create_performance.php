<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('period', 10);
            $table->string('subject_type', 10);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_key', 40)->nullable();
            $table->string('metric', 40);
            $table->decimal('value', 14, 2);
            $table->date('period_start');
            $table->char('currency', 3)->default('USD');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['period', 'metric', 'period_start']);
        });

        Schema::create('commission_plans', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->unsignedInteger('version');
            $table->json('rules');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_current')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('kind', 20);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('status', 20);
            $table->date('period');
            $table->foreignId('plan_id')->nullable()->constrained('commission_plans')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'period']);
        });

        Schema::create('deal_events', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('USD');
            $table->boolean('is_big_deal')->default(false);
            $table->boolean('fanfare')->default(false);
            $table->string('label');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['kind', 'created_at']);
        });

        $this->guard('commission_entries');
        $this->guard('deal_events');
        $now = now();
        DB::table('commission_plans')->insert([
            'ulid' => (string) str()->ulid(),
            'version' => 1,
            'rules' => json_encode([
                'mrc_percent' => '10.00',
                'install_amount' => '5.00',
                'payment_percent' => '0.00',
                'accelerator_above' => 100,
                'accelerator_percent' => '15.00',
                'clawback_days' => 90,
            ]),
            'valid_from' => $now->toDateString(),
            'is_current' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([
            ['commission_clawback', 'Commission clawback', 'استرداد عمولة'],
            ['commission_adjustment', 'Commission adjustment', 'تعديل عمولة'],
        ] as $row) {
            if (DB::table('approval_policies')->where('key', $row[0])->exists()) {
                continue;
            }

            DB::table('approval_policies')->insert([
                'key' => $row[0],
                'name_en' => $row[1],
                'name_ar' => $row[2],
                'steps' => json_encode([
                    ['roles' => ['management'], 'mode' => 'any_of', 'timeout_hours' => 48],
                ]),
                'timeout_hours' => 48,
                'escalate_to' => 'super_admin',
                'low_risk' => false,
                'pick' => 'chain',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_events');
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('commission_plans');
        Schema::dropIfExists('targets');
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
