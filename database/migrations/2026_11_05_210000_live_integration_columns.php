<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('whish_checkout_id')->nullable()->index();
            $table->string('whish_url', 500)->nullable();
            $table->foreignId('target_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('whish_checkout_id')->nullable()->index();
            $table->string('whish_url', 500)->nullable();
        });

        Schema::table('customer_services', function (Blueprint $table) {
            $table->string('radius_remote_status', 40)->nullable();
            $table->boolean('radius_online')->nullable();
            $table->string('radius_framed_ip', 45)->nullable();
            $table->timestamp('radius_checked_at')->nullable();
        });

        Schema::create('abandoned_calls', function (Blueprint $table) {
            $table->id();
            $table->string('call_id', 80)->nullable()->unique();
            $table->string('caller', 40);
            $table->string('extension', 20)->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('whatsapp_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_id', 191);
            $table->string('status', 40);
            $table->string('recipient', 20)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['provider_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_events');
        Schema::dropIfExists('abandoned_calls');

        Schema::table('customer_services', function (Blueprint $table) {
            $table->dropColumn(['radius_remote_status', 'radius_online', 'radius_framed_ip', 'radius_checked_at']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['whish_checkout_id', 'whish_url']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('target_invoice_id');
            $table->dropColumn(['whish_checkout_id', 'whish_url']);
        });
    }
};
