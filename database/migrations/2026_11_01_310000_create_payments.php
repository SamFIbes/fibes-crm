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
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('status', 30)->index();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening', 14, 2)->default(0);
            $table->decimal('expected', 14, 2)->default(0);
            $table->decimal('counted', 14, 2)->nullable();
            $table->decimal('variance', 14, 2)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('number')->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->decimal('tender_amount', 14, 2);
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 18, 4);
            $table->string('method', 30);
            $table->string('reference')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->string('status', 30)->index();
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['payment_id', 'invoice_id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('status', 30);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('write_offs', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('status', 30);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('cash_session_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        $this->guard('payment_events');
        $this->guard('cash_session_events');
        $this->policies();
        $this->grant();
    }

    public function down(): void
    {
        foreach (['payment_events', 'cash_session_events'] as $table) {
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

        Schema::dropIfExists('cash_session_events');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('write_offs');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('cash_sessions');
    }

    private function policies(): void
    {
        $now = now();
        $rows = [
            ['payment_reversal', 'Payment reversal', 'عكس دفعة', null, [
                ['roles' => ['finance'], 'mode' => 'any_of', 'when' => ['amount_up_to' => 50], 'timeout_hours' => 24],
                ['roles' => ['management'], 'mode' => 'any_of', 'when' => ['amount_above' => 50, 'amount_up_to' => 500], 'timeout_hours' => 24],
                ['roles' => ['super_admin'], 'mode' => 'any_of', 'when' => ['amount_above' => 500], 'timeout_hours' => 24],
            ], 24, 'management', null, false, 'ladder'],
            ['cash_variance', 'Cash difference', 'فرق الصندوق', null, [
                ['roles' => ['finance', 'management'], 'mode' => 'any_of', 'timeout_hours' => 24],
            ], 24, 'management', null, false, 'chain'],
        ];

        foreach ($rows as $row) {
            if (DB::table('approval_policies')->where('key', $row[0])->exists()) {
                continue;
            }

            DB::table('approval_policies')->insert([
                'key' => $row[0],
                'name_en' => $row[1],
                'name_ar' => $row[2],
                'conditions' => $row[3] === null ? null : json_encode($row[3]),
                'steps' => json_encode($row[4]),
                'timeout_hours' => $row[5],
                'escalate_to' => $row[6],
                'comment_above' => $row[7],
                'low_risk' => $row[8],
                'pick' => $row[9],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function grant(): void
    {
        if (! Schema::hasTable('roles') || Role::query()->doesntExist()) {
            return;
        }

        $key = 'payments.collect';
        $meta = Catalog::permissions()[$key] ?? null;

        if (! is_array($meta)) {
            return;
        }

        $permission = Permission::query()->updateOrCreate(['key' => $key], $meta);

        foreach (Catalog::grants()[$key] ?? [] as $roleKey => $scope) {
            $role = Role::query()->where('key', $roleKey)->first();

            if ($role) {
                $role->permissions()->syncWithoutDetaching([$permission->id => ['scope' => $scope]]);
            }
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
};
