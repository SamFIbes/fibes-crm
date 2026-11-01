<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 30)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('channel', 30)->default('campaign');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->decimal('budget', 14, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('external_id')->nullable()->index();
            $table->unsignedBigInteger('promo_code_id')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('disqualify_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique(['phone_e164']);
            $table->index('phone_e164');
            $table->string('number')->nullable()->unique();
            $table->foreignId('source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('referrer_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('alt_phone')->nullable();
            $table->string('whatsapp_e164')->nullable();
            $table->string('email')->nullable();
            $table->string('service_type_interest', 30)->nullable();
            $table->unsignedBigInteger('plan_interest_id')->nullable();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address_text')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('queue', 20)->default('cc');
            $table->foreignId('disqualify_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('sla_started_at')->nullable();
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('qualify_due_at')->nullable();
            $table->timestamp('handoff_due_at')->nullable();
            $table->string('sla_stage', 30)->nullable();
            $table->timestamp('sla_warned_at')->nullable();
            $table->timestamp('sla_breached_at')->nullable();
            $table->timestamp('handed_to_sales_at')->nullable();
            $table->foreignId('converted_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedBigInteger('converted_opportunity_id')->nullable();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('brought_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('no_answer_count')->default(0);
            $table->string('language', 8)->nullable();
            $table->string('create_reason')->nullable();
            $table->json('utm')->nullable();
            $table->json('custom')->nullable();
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 40);
            $table->text('body')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('referral_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('status', 20)->default('pending');
            $table->boolean('needs_approval')->default(true);
            $table->timestamps();
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->string('kind', 20)->default('fixed');
            $table->boolean('confirmed')->default(true);
        });

        $this->seedSources();
        $this->seedReasons();
        $this->seedIslamicHolidays();
        $this->numberExisting();
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_credits');
        Schema::dropIfExists('lead_activities');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_id');
            $table->dropConstrainedForeignId('brought_by_id');
            $table->dropConstrainedForeignId('converted_customer_id');
            $table->dropConstrainedForeignId('disqualify_reason_id');
            $table->dropConstrainedForeignId('team_id');
            $table->dropConstrainedForeignId('area_id');
            $table->dropConstrainedForeignId('referrer_customer_id');
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropConstrainedForeignId('source_id');
            $table->dropUnique(['number']);
            $table->dropIndex(['phone_e164']);
            $table->dropColumn([
                'number', 'first_name', 'last_name', 'alt_phone', 'whatsapp_e164', 'email',
                'service_type_interest', 'plan_interest_id', 'address_text', 'latitude', 'longitude',
                'queue', 'claimed_at', 'first_response_at', 'sla_started_at', 'sla_due_at',
                'qualify_due_at', 'handoff_due_at', 'sla_stage', 'sla_warned_at', 'sla_breached_at',
                'handed_to_sales_at', 'converted_opportunity_id', 'no_answer_count', 'language',
                'create_reason', 'utm', 'custom',
            ]);
            $table->unique('phone_e164');
        });
        Schema::dropIfExists('disqualify_reasons');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('lead_sources');
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn(['kind', 'confirmed']);
        });
    }

    private function seedSources(): void
    {
        $rows = [
            ['call', 'Call', 'مكالمة'],
            ['whatsapp', 'WhatsApp', 'واتساب'],
            ['web', 'Web form', 'نموذج الموقع'],
            ['social_ad', 'Ad', 'إعلان'],
            ['walk_in', 'Walk-in', 'زيارة'],
            ['referral', 'Referral', 'إحالة'],
            ['field_agent', 'Field agent', 'مندوب'],
            ['sales', 'Sales', 'مبيعات'],
            ['campaign', 'Campaign', 'حملة'],
            ['partner', 'Partner', 'شريك'],
        ];

        foreach ($rows as [$channel, $en, $ar]) {
            DB::table('lead_sources')->insert([
                'channel' => $channel,
                'name_en' => $en,
                'name_ar' => $ar,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedReasons(): void
    {
        $rows = [
            ['not_interested', 'Not interested', 'غير مهتم'],
            ['competitor', 'Chose someone else', 'اختار شركة أخرى'],
            ['wrong_number', 'Wrong number', 'رقم خاطئ'],
            ['no_coverage', 'Not covered', 'خارج التغطية'],
            ['other', 'Other', 'سبب آخر'],
        ];

        foreach ($rows as [$key, $en, $ar]) {
            DB::table('disqualify_reasons')->insert([
                'key' => $key,
                'name_en' => $en,
                'name_ar' => $ar,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedIslamicHolidays(): void
    {
        $rows = [
            ['2026-03-20', 'Eid al-Fitr', 'عيد الفطر'],
            ['2026-05-27', 'Eid al-Adha', 'عيد الأضحى'],
            ['2026-06-16', 'Islamic New Year', 'رأس السنة الهجرية'],
            ['2026-06-25', 'Ashura', 'عاشوراء'],
            ['2026-08-25', "Prophet's Birthday", 'المولد النبوي'],
            ['2027-03-10', 'Eid al-Fitr', 'عيد الفطر'],
            ['2027-05-17', 'Eid al-Adha', 'عيد الأضحى'],
            ['2027-06-06', 'Islamic New Year', 'رأس السنة الهجرية'],
            ['2027-06-15', 'Ashura', 'عاشوراء'],
            ['2027-08-14', "Prophet's Birthday", 'المولد النبوي'],
        ];

        foreach ($rows as [$date, $en, $ar]) {
            if (DB::table('holidays')->where('observed_on', $date)->exists()) {
                continue;
            }

            DB::table('holidays')->insert([
                'observed_on' => $date,
                'name_en' => $en,
                'name_ar' => $ar,
                'kind' => 'islamic',
                'confirmed' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function numberExisting(): void
    {
        foreach (DB::table('leads')->orderBy('id')->pluck('id') as $id) {
            DB::table('leads')->where('id', $id)->update([
                'number' => 'LD-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT),
            ]);
        }
    }
};
