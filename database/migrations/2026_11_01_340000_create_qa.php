<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_forms', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('key');
            $table->unsignedInteger('version');
            $table->string('name_en');
            $table->string('name_ar');
            $table->json('criteria');
            $table->boolean('is_current')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['key', 'version']);
        });

        Schema::create('qa_scripts', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('call_type', 40);
            $table->unsignedInteger('version');
            $table->string('name_en');
            $table->string('name_ar');
            $table->json('steps');
            $table->boolean('is_current')->default(true);
            $table->timestamps();
            $table->unique(['call_type', 'version']);
        });

        Schema::create('qa_evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('call_id')->nullable()->constrained('interactions')->nullOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('evaluator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('form_id')->constrained('qa_forms')->cascadeOnDelete();
            $table->unsignedInteger('form_version');
            $table->json('scores');
            $table->decimal('total_pct', 6, 2)->default(0);
            $table->boolean('critical_fail')->default(false);
            $table->text('coaching_notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('qa_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qa_evaluation_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('qa_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('interaction_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->timestamps();
            $table->unique(['agent_id', 'interaction_id', 'week_start']);
        });

        Schema::create('agent_status_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        $this->guard('qa_events');
        $this->seed();
    }

    public function down(): void
    {
        foreach (['qa_events'] as $table) {
            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'pgsql') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update ON {$table}");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete ON {$table}");
            }

            if ($driver === 'sqlite') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
            }
        }

        Schema::dropIfExists('agent_status_log');
        Schema::dropIfExists('qa_samples');
        Schema::dropIfExists('qa_events');
        Schema::dropIfExists('qa_evaluations');
        Schema::dropIfExists('qa_scripts');
        Schema::dropIfExists('qa_forms');
    }

    private function seed(): void
    {
        $now = now();
        DB::table('qa_forms')->insert([
            'ulid' => (string) str()->ulid(),
            'key' => 'call',
            'version' => 1,
            'name_en' => 'Call quality',
            'name_ar' => 'جودة المكالمة',
            'criteria' => json_encode([
                ['key' => 'greeting', 'label_en' => 'Greeting', 'label_ar' => 'الترحيب', 'weight' => 50, 'critical' => false],
                ['key' => 'compliance', 'label_en' => 'Compliance', 'label_ar' => 'الالتزام', 'weight' => 50, 'critical' => true],
            ]),
            'is_current' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([
            ['sales', 'Sales call', 'مكالمة مبيعات'],
            ['support', 'No internet', 'لا إنترنت'],
            ['billing', 'Billing', 'الفوترة'],
            ['retention', 'Retention', 'الإبقاء'],
        ] as $script) {
            DB::table('qa_scripts')->insert([
                'ulid' => (string) str()->ulid(),
                'call_type' => $script[0],
                'version' => 1,
                'name_en' => $script[1],
                'name_ar' => $script[2],
                'steps' => json_encode([
                    ['en' => 'Greet the customer', 'ar' => 'رحّب بالعميل'],
                    ['en' => 'Confirm the account', 'ar' => 'أكّد الحساب'],
                ]),
                'is_current' => true,
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
