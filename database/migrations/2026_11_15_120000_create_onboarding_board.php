<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_stages', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->unsignedSmallInteger('position')->index();
            $table->string('task_title_en');
            $table->string('task_title_ar');
            $table->boolean('is_terminal')->default(false);
            $table->timestamps();
        });

        Schema::create('onboarding_settings', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->text('value');
            $table->timestamps();
        });

        Schema::create('onboarding_cards', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->string('client_name')->nullable();
            $table->string('client_phone')->nullable();
            $table->string('client_email')->nullable();
            $table->string('language', 5)->nullable();
            $table->string('source_kind', 20);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('salesperson_name')->nullable();
            $table->string('stage_key', 40)->index();
            $table->timestamp('stage_entered_at');
            $table->timestamp('last_activity_at')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source_kind', 'source_id']);
            $table->index('salesperson_id');
            $table->index('assigned_to');
            $table->index('lead_id');
            $table->index('customer_id');
        });

        Schema::create('onboarding_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('onboarding_cards')->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('kind', 30);
            $table->string('from_stage', 40)->nullable();
            $table->string('to_stage', 40)->nullable();
            $table->text('detail')->nullable();
            $table->timestamps();
        });

        Schema::create('onboarding_followups', function (Blueprint $table) {
            $table->foreignId('card_id')->primary()->constrained('onboarding_cards')->cascadeOnDelete();
            $table->date('due_on')->index();
            $table->text('note')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('set_at');
            $table->timestamps();
        });

        $timestamp = now();

        DB::table('onboarding_stages')->insert(array_map(
            fn (array $stage): array => $stage + ['created_at' => $timestamp, 'updated_at' => $timestamp],
            [
                ['key' => 'new_lead', 'name_en' => 'New lead from sales', 'name_ar' => 'عميل محتمل جديد من المبيعات', 'position' => 1, 'task_title_en' => 'Contact the client', 'task_title_ar' => 'التواصل مع العميل', 'is_terminal' => false],
                ['key' => 'contacted', 'name_en' => 'Contacted', 'name_ar' => 'تم التواصل', 'position' => 2, 'task_title_en' => 'Book a site survey', 'task_title_ar' => 'تحديد موعد معاينة الموقع', 'is_terminal' => false],
                ['key' => 'site_survey', 'name_en' => 'Site survey', 'name_ar' => 'معاينة الموقع', 'position' => 3, 'task_title_en' => 'Complete the site survey', 'task_title_ar' => 'إتمام معاينة الموقع', 'is_terminal' => false],
                ['key' => 'quote_sent', 'name_en' => 'Quote sent', 'name_ar' => 'أُرسل عرض السعر', 'position' => 4, 'task_title_en' => 'Follow up on the quote', 'task_title_ar' => 'متابعة عرض السعر', 'is_terminal' => false],
                ['key' => 'contract_signed', 'name_en' => 'Contract signed', 'name_ar' => 'وُقّع العقد', 'position' => 5, 'task_title_en' => 'Schedule the installation', 'task_title_ar' => 'تحديد موعد التركيب', 'is_terminal' => false],
                ['key' => 'installation_scheduled', 'name_en' => 'Installation scheduled', 'name_ar' => 'حُدد موعد التركيب', 'position' => 6, 'task_title_en' => 'Confirm the installation', 'task_title_ar' => 'تأكيد التركيب', 'is_terminal' => false],
                ['key' => 'active', 'name_en' => 'Active', 'name_ar' => 'نشط', 'position' => 7, 'task_title_en' => 'Confirm the service is active', 'task_title_ar' => 'تأكيد أن الخدمة نشطة', 'is_terminal' => true],
            ],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_followups');
        Schema::dropIfExists('onboarding_events');
        Schema::dropIfExists('onboarding_cards');
        Schema::dropIfExists('onboarding_settings');
        Schema::dropIfExists('onboarding_stages');
    }
};
