<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->constrained('ticket_categories');
            $table->string('priority', 4)->index();
            $table->string('channel', 20);
            $table->string('status', 30)->index();
            $table->string('subject');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('escalation_reason')->nullable();
            $table->text('handover_note')->nullable();
            $table->unsignedInteger('response_minutes');
            $table->unsignedInteger('resolve_minutes');
            $table->timestamp('response_due_at')->index();
            $table->timestamp('resolve_due_at')->index();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('paused_seconds')->default(0);
            $table->boolean('response_warned')->default(false);
            $table->boolean('response_breached')->default(false);
            $table->boolean('resolve_warned')->default(false);
            $table->boolean('resolve_breached')->default(false);
            $table->timestamps();
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 20);
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('ticket_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('filters');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_views');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_categories');
    }
};
