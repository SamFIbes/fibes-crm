<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('keys');
            $table->timestamps();
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report_key', 40);
            $table->string('format', 8);
            $table->string('status', 20)->default('pending');
            $table->string('path')->nullable();
            $table->unsignedInteger('rows')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report_key', 40);
            $table->string('cadence', 10);
            $table->unsignedTinyInteger('hour')->default(8);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->date('last_sent_on')->nullable();
            $table->timestamps();
        });

        Schema::create('report_digests', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);
            $table->date('sent_on');
            $table->timestamps();
            $table->unique(['kind', 'sent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_digests');
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('dashboard_layouts');
    }
};
