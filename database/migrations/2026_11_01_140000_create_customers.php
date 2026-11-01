<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('account_no')->unique();
            $table->string('type', 20);
            $table->string('status', 20)->index();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('whatsapp_e164')->nullable()->index();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('building')->nullable();
            $table->string('floor')->nullable();
            $table->string('landmark')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('tags')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('customer_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('phone_e164')->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('customer_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('service_type', 20)->index();
            $table->string('plan_name')->nullable();
            $table->string('speed')->nullable();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->date('install_date')->nullable();
            $table->string('status', 20)->index();
            $table->decimal('monthly_usd', 14, 2)->default(0);
            $table->string('radius_username')->nullable();
            $table->text('radius_password')->nullable();
            $table->string('static_ip')->nullable();
            $table->string('mac')->nullable();
            $table->string('nas')->nullable();
            $table->string('vlan')->nullable();
            $table->string('ont_serial')->nullable();
            $table->string('router_model')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('customer_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('filters');
            $table->timestamps();
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->nullableMorphs('attachable');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropMorphs('attachable');
        });
        Schema::dropIfExists('customer_views');
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('customer_services');
        Schema::dropIfExists('customer_phones');
        Schema::dropIfExists('customers');
    }
};
