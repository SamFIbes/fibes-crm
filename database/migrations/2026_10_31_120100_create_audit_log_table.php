<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->timestamp('occurred_at')->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event')->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION fibes_audit_log_immutable()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_log is append-only';
                END;
                $$;

                CREATE TRIGGER audit_log_no_update
                BEFORE UPDATE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION fibes_audit_log_immutable();

                CREATE TRIGGER audit_log_no_delete
                BEFORE DELETE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION fibes_audit_log_immutable();
            SQL);
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_log_no_update
                BEFORE UPDATE ON audit_log
                BEGIN
                    SELECT RAISE(ABORT, 'audit_log is append-only');
                END;

                CREATE TRIGGER audit_log_no_delete
                BEFORE DELETE ON audit_log
                BEGIN
                    SELECT RAISE(ABORT, 'audit_log is append-only');
                END;
            SQL);
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_log_no_update ON audit_log');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_log_no_delete ON audit_log');
            DB::unprepared('DROP FUNCTION IF EXISTS fibes_audit_log_immutable()');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_log_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_log_no_delete');
        }

        Schema::dropIfExists('audit_log');
    }
};
