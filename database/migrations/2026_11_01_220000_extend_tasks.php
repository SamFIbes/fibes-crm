<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\Access\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('priority', 20)->default('normal')->after('status');
            $table->foreignId('job_id')->nullable()->after('ticket_id')->constrained('field_jobs')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->after('job_id')->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->after('assignee_id')->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->after('team_id')->constrained('users')->nullOnDelete();
            $table->timestamp('remind_at')->nullable()->after('due_at');
            $table->boolean('reminded')->default(false)->after('remind_at');
            $table->boolean('overdue_notified')->default(false)->after('reminded');
            $table->foreignId('rule_id')->nullable()->after('overdue_notified');
            $table->date('occurrence_on')->nullable()->after('rule_id');
        });

        Schema::create('task_rules', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('frequency', 20);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->string('time_of_day', 5)->default('09:00');
            $table->timestamp('next_run_at')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('rule_id')->references('id')->on('task_rules')->nullOnDelete();
            $table->unique(['rule_id', 'occurrence_on']);
        });

        Schema::table('interactions', function (Blueprint $table) {
            $table->timestamp('callback_at')->nullable()->after('lead_id');
        });

        $this->grantNewPermissions();
    }

    public function down(): void
    {
        Schema::table('interactions', function (Blueprint $table) {
            $table->dropColumn('callback_at');
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['rule_id', 'occurrence_on']);
            $table->dropConstrainedForeignId('rule_id');
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('team_id');
            $table->dropConstrainedForeignId('lead_id');
            $table->dropConstrainedForeignId('job_id');
            $table->dropColumn(['priority', 'remind_at', 'reminded', 'overdue_notified', 'occurrence_on']);
        });
        Schema::dropIfExists('task_rules');
    }

    private function grantNewPermissions(): void
    {
        if (! Schema::hasTable('roles') || Role::query()->doesntExist()) {
            return;
        }

        $keys = ['tasks.view', 'tasks.create', 'tasks.edit', 'tasks.assign'];

        foreach ($keys as $key) {
            $meta = Catalog::permissions()[$key];
            $permission = Permission::query()->updateOrCreate(['key' => $key], $meta);
            $grants = Catalog::grants()[$key] ?? [];

            foreach ($grants as $roleKey => $scope) {
                $role = Role::query()->where('key', $roleKey)->first();

                if ($role) {
                    $role->permissions()->syncWithoutDetaching([$permission->id => ['scope' => $scope]]);
                }
            }
        }
    }
};
