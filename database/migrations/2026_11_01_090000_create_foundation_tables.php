<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governorates', function (Blueprint $table) {
            $table->id();
            $table->string('name_en');
            $table->string('name_ar');
            $table->timestamps();
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governorate_id')->constrained()->cascadeOnDelete();
            $table->string('name_en');
            $table->string('name_ar');
            $table->timestamps();
            $table->unique(['governorate_id', 'name_en']);
        });

        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('name_en');
            $table->string('name_ar');
            $table->timestamps();
            $table->unique(['district_id', 'name_en']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name_en');
            $table->string('name_ar');
            $table->unsignedTinyInteger('decimals');
            $table->string('symbol', 8);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('effective_on')->index();
            $table->decimal('usd_lbp', 18, 4);
            $table->boolean('confirmed')->default(false);
            $table->string('note')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('observed_on')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->timestamps();
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('scan_status', 20);
            $table->string('scan_detail')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        $this->guard('exchange_rates');
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS exchange_rates_no_update ON exchange_rates');
            DB::unprepared('DROP TRIGGER IF EXISTS exchange_rates_no_delete ON exchange_rates');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS exchange_rates_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS exchange_rates_no_delete');
        }

        Schema::dropIfExists('notifications');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('areas');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('governorates');
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
