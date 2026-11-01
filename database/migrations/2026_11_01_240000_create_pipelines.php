<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('key', 30)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->json('service_types');
            $table->timestamps();
        });

        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 20);
            $table->string('key', 40);
            $table->string('name_en');
            $table->string('name_ar');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unsignedTinyInteger('probability')->default(0);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->unsignedSmallInteger('stale_after_days')->nullable();
            $table->json('required_fields')->nullable();
            $table->timestamps();
            $table->unique(['pipeline_id', 'entity', 'key']);
        });

        Schema::create('loss_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->boolean('win_back')->default(false);
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone_e164')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('pipeline_stages')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_type', 30)->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedBigInteger('coverage_check_id')->nullable();
            $table->text('coverage_note')->nullable();
            $table->decimal('mrc', 14, 2)->default(0);
            $table->decimal('nrc', 14, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->unsignedTinyInteger('probability')->default(0);
            $table->date('expected_close')->nullable();
            $table->timestamp('stage_entered_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->foreignId('loss_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->string('competitor')->nullable();
            $table->text('override_reason')->nullable();
            $table->boolean('management_confirmed')->default(false);
            $table->timestamps();
        });

        Schema::create('opportunity_stage_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('pipeline_stages')->cascadeOnDelete();
            $table->timestamp('entered_at');
            $table->timestamp('left_at');
            $table->unsignedInteger('seconds');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('opportunity_id')->nullable()->after('lead_id')->constrained()->nullOnDelete();
        });

        $this->seed();
        $this->guard('opportunity_stage_history');
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opportunity_id');
        });
        Schema::dropIfExists('opportunity_stage_history');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('loss_reasons');
        Schema::dropIfExists('pipeline_stages');
        Schema::dropIfExists('pipelines');
    }

    private function seed(): void
    {
        $retail = DB::table('pipelines')->insertGetId([
            'key' => 'retail',
            'name_en' => 'Retail',
            'name_ar' => 'أفراد',
            'service_types' => json_encode(['ftth', 'fttb', 'ptmp', 'ptp', 'dsl', 'internal', 'hsi']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $business = DB::table('pipelines')->insertGetId([
            'key' => 'business',
            'name_en' => 'Business',
            'name_ar' => 'شركات',
            'service_types' => json_encode(['ftth', 'fttb', 'ptmp', 'ptp', 'dsl', 'internal']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reseller = DB::table('pipelines')->insertGetId([
            'key' => 'reseller',
            'name_en' => 'Reseller',
            'name_ar' => 'موزع',
            'service_types' => json_encode(['ftth', 'fttb', 'ptmp', 'ptp']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $standard = [
            ['feasibility', 'Feasibility', 'الجدوى', 10, 10, false, false, 7],
            ['site_survey', 'Site survey', 'كشف الموقع', 20, 25, false, false, 14],
            ['proposal', 'Proposal', 'العرض', 30, 50, false, false, 14],
            ['negotiation', 'Negotiation', 'التفاوض', 40, 75, false, false, 21],
            ['won', 'Won', 'رابحة', 50, 100, true, false, null],
            ['lost', 'Lost', 'خاسرة', 60, 0, false, true, null],
        ];
        $partner = [
            ['qualified', 'Qualified', 'مؤهل', 10, 20, false, false, 7],
            ['due_diligence', 'Due diligence', 'التدقيق', 20, 40, false, false, 14],
            ['proposal', 'Proposal', 'العرض', 30, 60, false, false, 14],
            ['agreement', 'Agreement', 'الاتفاق', 40, 80, false, false, 14],
            ['won', 'Won', 'رابحة', 50, 100, true, false, null],
            ['lost', 'Lost', 'خاسرة', 60, 0, false, true, null],
        ];

        foreach ([$retail, $business] as $pipeline) {
            foreach ($standard as $row) {
                $this->stage($pipeline, $row);
            }
        }

        foreach ($partner as $row) {
            $this->stage($reseller, $row);
        }

        $reasons = [
            ['price', 'Price', 'السعر', true],
            ['postponed', 'Postponed', 'مؤجل', true],
            ['competitor', 'Competitor', 'منافس', false],
            ['no_coverage', 'No coverage', 'لا تغطية', false],
            ['no_budget', 'No budget', 'لا ميزانية', false],
            ['other', 'Other', 'سبب آخر', false],
        ];

        foreach ($reasons as [$key, $en, $ar, $winBack]) {
            DB::table('loss_reasons')->insert([
                'key' => $key,
                'name_en' => $en,
                'name_ar' => $ar,
                'win_back' => $winBack,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: int, 4: int, 5: bool, 6: bool, 7: int|null}  $row
     */
    private function stage(int $pipeline, array $row): void
    {
        DB::table('pipeline_stages')->insert([
            'pipeline_id' => $pipeline,
            'entity' => 'opportunity',
            'key' => $row[0],
            'name_en' => $row[1],
            'name_ar' => $row[2],
            'sort' => $row[3],
            'probability' => $row[4],
            'is_won' => $row[5],
            'is_lost' => $row[6],
            'stale_after_days' => $row[7],
            'required_fields' => json_encode($this->required($row[0])),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function required(string $key): array
    {
        return match ($key) {
            'proposal' => ['coverage_note'],
            'won' => ['override_reason'],
            'lost' => ['loss_reason_id'],
            'agreement' => ['management_confirmed'],
            default => [],
        };
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
