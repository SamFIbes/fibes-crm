<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_links', function (Blueprint $table): void {
            $table->id();
            $table->string('doctype', 60)->index();
            $table->string('erp_name', 140);
            $table->string('fibes_type', 40);
            $table->unsignedBigInteger('fibes_id')->nullable();
            $table->timestamp('erp_modified')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['doctype', 'erp_name']);
            $table->index(['fibes_type', 'fibes_id']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('erp_name')->nullable()->unique()->after('account_no');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['erp_name']);
            $table->dropColumn('erp_name');
        });

        Schema::dropIfExists('erp_links');
    }
};
