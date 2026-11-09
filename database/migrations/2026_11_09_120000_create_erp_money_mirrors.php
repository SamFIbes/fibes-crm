<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('erp_name')->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_erp_name')->nullable()->index();
            $table->date('posting_date')->nullable();
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->decimal('outstanding_amount', 14, 2)->default(0);
            $table->string('status', 40);
            $table->unsignedTinyInteger('docstatus')->default(0);
            $table->boolean('is_return')->default(false);
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('erp_modified')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_payment_entries', function (Blueprint $table) {
            $table->id();
            $table->string('erp_name')->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_erp_name')->nullable()->index();
            $table->date('posting_date')->nullable();
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('received_amount', 14, 2)->default(0);
            $table->string('mode_of_payment', 80)->nullable();
            $table->string('status', 40)->nullable();
            $table->unsignedTinyInteger('docstatus')->default(0);
            $table->string('party_type', 40)->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('erp_modified')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_payment_entries');
        Schema::dropIfExists('erp_sales_invoices');
    }
};
