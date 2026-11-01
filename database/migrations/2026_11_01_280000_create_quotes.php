<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('type', 40);
            $table->string('locale', 5);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('name');
            $table->longText('body');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['type', 'locale', 'version']);
        });

        Schema::create('clauses', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40);
            $table->string('locale', 5);
            $table->string('title');
            $table->text('body');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['key', 'locale']);
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('number')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('parent_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->index();
            $table->string('language', 5)->default('en');
            $table->char('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 18, 4);
            $table->date('valid_until')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->boolean('apply_vat')->default(true);
            $table->string('promo_code')->nullable();
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->foreignId('pdf_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->string('approver_name')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('public_token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['number', 'version']);
        });

        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('qty', 14, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('discount_pct', 8, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->boolean('is_recurring')->default(false);
            $table->boolean('custom_price')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('quote_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('number')->nullable()->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 30)->index();
            $table->unsignedSmallInteger('term_months')->default(12);
            $table->date('start_on')->nullable();
            $table->date('end_on')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->decimal('early_termination_fee', 14, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->longText('body_html')->nullable();
            $table->longText('snapshot_html')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signer_name')->nullable();
            $table->string('signer_phone')->nullable();
            $table->string('signer_ip', 45)->nullable();
            $table->string('signer_user_agent', 500)->nullable();
            $table->foreignId('signature_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->foreignId('signed_pdf_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->char('sha256', 64)->nullable();
            $table->string('public_token', 64)->unique();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        $this->seed();
        $this->guard('quote_events');
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS quote_events_no_update ON quote_events');
            DB::unprepared('DROP TRIGGER IF EXISTS quote_events_no_delete ON quote_events');
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS quote_events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS quote_events_no_delete');
        }

        Schema::dropIfExists('contracts');
        Schema::dropIfExists('quote_events');
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('clauses');
        Schema::dropIfExists('document_templates');
    }

    private function seed(): void
    {
        $now = now();
        $types = [
            'quote' => ['Quote', 'عرض السعر'],
            'contract' => ['Contract', 'عقد'],
            'invoice' => ['Invoice', 'فاتورة'],
            'receipt' => ['Receipt', 'إيصال'],
            'work_order' => ['Work order', 'أمر عمل'],
            'statement' => ['Statement', 'كشف حساب'],
            'suspension_notice' => ['Suspension notice', 'إشعار إيقاف'],
            'termination_letter' => ['Termination letter', 'خطاب إنهاء'],
            'installation_report' => ['Installation report', 'تقرير التركيب'],
            'equipment_handover' => ['Equipment handover', 'تسليم المعدات'],
        ];

        foreach ($types as $type => [$en, $ar]) {
            foreach (['en' => $en, 'ar' => $ar] as $locale => $name) {
                DB::table('document_templates')->insert([
                    'ulid' => (string) Str::ulid(),
                    'type' => $type,
                    'locale' => $locale,
                    'version' => 1,
                    'name' => $name,
                    'body' => $this->body($type, $locale),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $clauses = [
            ['term', 'en', 'Term', 'The term is {{term_months}} months. This length is to confirm.', 10],
            ['term', 'ar', 'المدة', 'المدة {{term_months}} شهراً. هذه المدة بحاجة إلى تأكيد.', 10],
            ['vat', 'en', 'VAT', 'VAT is shown at 11 percent and is to confirm.', 20],
            ['vat', 'ar', 'الضريبة', 'ضريبة القيمة المضافة ١١ بالمئة ومعروضة بانتظار التأكيد.', 20],
            ['early_termination', 'en', 'Early termination', 'The early termination fee is {{early_termination_fee}} and is to confirm.', 30],
            ['early_termination', 'ar', 'الإنهاء المبكر', 'رسم الإنهاء المبكر {{early_termination_fee}} وبانتظار التأكيد.', 30],
        ];

        foreach ($clauses as $clause) {
            DB::table('clauses')->insert([
                'key' => $clause[0],
                'locale' => $clause[1],
                'title' => $clause[2],
                'body' => $clause[3],
                'sort' => $clause[4],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function body(string $type, string $locale): string
    {
        if ($type === 'contract' && $locale === 'ar') {
            return '<h1>عقد خدمة</h1><p>يتعاقد {{customer.name}} على الباقة {{plan.name}} لمدة {{term_months}} شهراً. رسم الإنهاء المبكر {{early_termination_fee}} (بانتظار التأكيد). عرض السعر {{quote.number}} والمجموع {{quote.total}} {{quote.currency}}.</p>';
        }

        if ($type === 'contract') {
            return '<h1>Service agreement</h1><p>{{customer.name}} takes the plan {{plan.name}} for {{term_months}} months. The early termination fee is {{early_termination_fee}} (to confirm). Quote {{quote.number}}, total {{quote.total}} {{quote.currency}}.</p>';
        }

        if ($locale === 'ar') {
            return '<p>'.$this->arabicName($type).' {{quote.number}} للعميل {{customer.name}}. هذا النموذج جاهز ويُستخدم في خطوة لاحقة. الأسعار بحاجة إلى تأكيد.</p>';
        }

        return '<p>'.ucfirst(str_replace('_', ' ', $type)).' {{quote.number}} for {{customer.name}}. This template is a stub for a later step. Prices are to confirm.</p>';
    }

    private function arabicName(string $type): string
    {
        return match ($type) {
            'quote' => 'عرض السعر',
            'invoice' => 'فاتورة',
            'receipt' => 'إيصال',
            'work_order' => 'أمر عمل',
            'statement' => 'كشف حساب',
            'suspension_notice' => 'إشعار إيقاف',
            'termination_letter' => 'خطاب إنهاء',
            'installation_report' => 'تقرير التركيب',
            'equipment_handover' => 'تسليم المعدات',
            default => 'مستند',
        };
    }

    private function guard(string $table): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION fibes_{$table}_immutable()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION '{$table} is append-only';
                END;
                $$;

                CREATE TRIGGER {$table}_no_update
                BEFORE UPDATE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION fibes_{$table}_immutable();

                CREATE TRIGGER {$table}_no_delete
                BEFORE DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION fibes_{$table}_immutable();
            SQL);
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_no_update
                BEFORE UPDATE ON {$table}
                BEGIN
                    SELECT RAISE(ABORT, '{$table} is append-only');
                END;

                CREATE TRIGGER {$table}_no_delete
                BEFORE DELETE ON {$table}
                BEGIN
                    SELECT RAISE(ABORT, '{$table} is append-only');
                END;
            SQL);
        }
    }
};
