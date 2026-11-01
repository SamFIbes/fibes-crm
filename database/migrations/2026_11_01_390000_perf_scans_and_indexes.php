<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->timestamp('scanned_at')->nullable();
            $table->string('scan_result', 20)->nullable();
        });

        Schema::table('audit_log', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->index();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index('name_en');
            $table->index('name_ar');
        });

        Schema::table('field_jobs', function (Blueprint $table) {
            $table->index('window_start');
        });
    }

    public function down(): void
    {
        Schema::table('field_jobs', function (Blueprint $table) {
            $table->dropIndex(['window_start']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['name_en']);
            $table->dropIndex(['name_ar']);
        });
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropColumn('customer_id');
        });
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn(['scanned_at', 'scan_result']);
        });
    }
};
