<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalation_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('level')->unique();
            $table->string('key')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('escalation_tier_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escalation_tier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('on_duty')->default(false);
            $table->timestamps();
            $table->unique(['escalation_tier_id', 'user_id']);
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('title');
            $table->string('status')->default('open');
            $table->foreignId('governorate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_type')->nullable();
            $table->string('network_element')->nullable();
            $table->text('summary')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_mode')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('escalation_tier_id')->nullable()->after('team_id')->constrained()->nullOnDelete();
            $table->foreignId('incident_id')->nullable()->after('escalation_tier_id')->constrained()->nullOnDelete();
        });

        Schema::create('ticket_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('direction');
            $table->foreignId('from_tier_id')->nullable()->constrained('escalation_tiers')->nullOnDelete();
            $table->foreignId('to_tier_id')->nullable()->constrained('escalation_tiers')->nullOnDelete();
            $table->foreignId('from_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->text('handover_note');
            $table->boolean('automatic')->default(false);
            $table->string('trigger')->default('manual');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_escalations');
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incident_id');
            $table->dropConstrainedForeignId('escalation_tier_id');
        });
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('escalation_tier_user');
        Schema::dropIfExists('escalation_tiers');
    }
};
