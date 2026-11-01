<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('network_nodes')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('name');
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('customer_services', function (Blueprint $table) {
            $table->foreignId('network_node_id')->nullable()->after('area_id')->constrained('network_nodes')->nullOnDelete();
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignId('network_node_id')->nullable()->after('network_element')->constrained('network_nodes')->nullOnDelete();
        });

        Schema::create('field_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('type', 20);
            $table->string('status', 20)->index();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('required_skill', 40)->nullable();
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->string('failure_reason', 40)->nullable();
            $table->text('failure_note')->nullable();
            $table->text('notes')->nullable();
            $table->text('materials')->nullable();
            $table->string('signed_by')->nullable();
            $table->foreignId('signature_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('job_type', 20)->nullable();
            $table->foreignId('ticket_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('label_en');
            $table->string('label_ar');
            $table->boolean('required')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('job_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('field_jobs')->cascadeOnDelete();
            $table->string('label_en');
            $table->string('label_ar');
            $table->boolean('required')->default(true);
            $table->boolean('done')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('ticket_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('label_en');
            $table->string('label_ar');
            $table->boolean('required')->default(true);
            $table->boolean('done')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('csat_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('source', 20)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->foreignId('ticket_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->string('title');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('user_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('skill', 40);
            $table->timestamps();
            $table->unique(['user_id', 'skill']);
        });

        Schema::create('job_sync_keys', function (Blueprint $table) {
            $table->string('idempotency_key')->primary();
            $table->foreignId('job_id')->constrained('field_jobs')->cascadeOnDelete();
            $table->string('action', 40);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_sync_keys');
        Schema::dropIfExists('user_skills');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('csat_responses');
        Schema::dropIfExists('ticket_checks');
        Schema::dropIfExists('job_checks');
        Schema::dropIfExists('checklist_templates');
        Schema::dropIfExists('field_jobs');
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('network_node_id');
        });
        Schema::table('customer_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('network_node_id');
        });
        Schema::dropIfExists('network_nodes');
    }
};
