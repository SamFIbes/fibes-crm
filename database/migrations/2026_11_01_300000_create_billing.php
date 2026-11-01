<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 8);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->unique(['prefix', 'year']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('customer_services')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('price', 14, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('billing_cycle', 20)->default('monthly');
            $table->boolean('prepaid')->default(true);
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->date('next_invoice_at')->nullable();
            $table->string('status', 30)->index();
            $table->boolean('auto_renew')->default(true);
            $table->unsignedBigInteger('discount_id')->nullable();
            $table->unsignedBigInteger('promo_id')->nullable();
            $table->unsignedSmallInteger('grace_days')->default(3);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('number')->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 18, 4)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('lbp_total', 14, 2)->default(0);
            $table->string('status', 30)->index();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('pdf_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['subscription_id', 'period_start', 'type']);
        });

        DB::statement("CREATE UNIQUE INDEX invoices_one_per_period ON invoices (subscription_id, period_start) WHERE type = 'invoice' AND subscription_id IS NOT NULL");

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('qty', 14, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->boolean('is_recurring')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('invoice_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('entry_type', 20);
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 18, 4)->default(0);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('balance_after', 14, 2);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        $this->guard('ledger_entries');
        $this->guard('invoice_events');
    }

    public function down(): void
    {
        foreach (['ledger_entries', 'invoice_events'] as $table) {
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

        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('invoice_events');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('invoice_sequences');
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
