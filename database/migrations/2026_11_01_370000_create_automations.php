<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('key', 40);
            $table->unsignedInteger('version')->default(1);
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('trigger', 40);
            $table->json('conditions');
            $table->json('actions');
            $table->boolean('enabled')->default(true);
            $table->boolean('is_current')->default(true);
            $table->unsignedInteger('rate_per_hour')->default(30);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['key', 'is_current']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('trigger', 40);
            $table->string('status', 20);
            $table->boolean('dry_run')->default(false);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->string('message')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('name');
            $table->string('url');
            $table->text('secret');
            $table->json('events');
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error', 120)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_delivery_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('result', 20);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('name');
            $table->string('token_hash', 64);
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->guard('automation_runs');
        $this->guard('webhook_attempts');

        $now = now();
        $rules = [
            ['lead_sla_warning', 'Lead clock warning', 'تحذير مهلة العميل المحتمل', 'lead.sla_warning', [], [['type' => 'notify', 'role' => 'cc_supervisor', 'title' => 'Lead clock warning']]],
            ['lead_sla_breach', 'Lead clock breach', 'تجاوز مهلة العميل المحتمل', 'lead.sla_breach', [], [['type' => 'notify', 'role' => 'cc_supervisor', 'title' => 'Lead clock breach']]],
            ['p1_unassigned', 'Urgent ticket waiting', 'تذكرة عاجلة بلا مسؤول', 'ticket.p1_unassigned', ['op' => 'and', 'rules' => [['field' => 'priority', 'cmp' => 'eq', 'value' => 'P1']]], [['type' => 'notify', 'role' => 'cc_supervisor', 'title' => 'P1 is unassigned']]],
            ['ticket_followup', 'Follow-up after a resolution', 'متابعة بعد الحل', 'ticket.resolved', [], [['type' => 'create_task', 'title' => 'Follow-up call', 'hours' => 24]]],
            ['health_at_risk', 'At-risk customer', 'عميل في خطر', 'customer.health', [], [['type' => 'add_tag', 'tag' => 'at-risk'], ['type' => 'create_task', 'title' => 'Retention check', 'hours' => 24]]],
            ['renewal_due', 'Renewal coming up', 'تجديد قريب', 'subscription.expiring', [], [['type' => 'create_task', 'title' => 'Renewal call', 'hours' => 24]]],
            ['low_stock', 'Low stock', 'مخزون منخفض', 'stock.low', [], [['type' => 'notify', 'role' => 'warehouse', 'title' => 'Stock is low']]],
        ];

        foreach ($rules as $rule) {
            DB::table('automations')->insert([
                'ulid' => (string) str()->ulid(),
                'key' => $rule[0],
                'version' => 1,
                'name_en' => $rule[1],
                'name_ar' => $rule[2],
                'trigger' => $rule[3],
                'conditions' => json_encode($rule[4] === [] ? ['op' => 'and', 'rules' => []] : $rule[4]),
                'actions' => json_encode($rule[5]),
                'enabled' => true,
                'is_current' => true,
                'rate_per_hour' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('webhook_attempts');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automations');
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
