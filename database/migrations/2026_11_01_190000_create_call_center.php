<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('extension', 20)->nullable()->unique();
        });

        Schema::create('wrap_up_codes', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phone_e164');
            $table->string('status')->default('new');
            $table->string('source')->default('call_center');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique('phone_e164');
        });

        Schema::create('interactions', function (Blueprint $table) {
            $table->id();
            $table->string('direction');
            $table->string('channel');
            $table->foreignId('wrap_up_code_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('notes')->nullable();
            $table->string('phone_e164')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interactions');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('wrap_up_codes');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('extension');
        });
    }
};
