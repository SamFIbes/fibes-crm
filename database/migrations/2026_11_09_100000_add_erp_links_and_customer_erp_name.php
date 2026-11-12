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
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['doctype', 'erp_name']);
            $table->index(['fibes_type', 'fibes_id']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('erp_name')->nullable()->unique()->after('account_no');
        });

        Schema::create('erp_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('mode', 20);
            $table->string('status', 20);
            $table->integer('scanned')->default(0);
            $table->integer('would_create')->default(0);
            $table->integer('would_update')->default(0);
            $table->integer('unchanged')->default(0);
            $table->integer('ambiguous')->default(0);
            $table->integer('errors')->default(0);
            $table->integer('contacts')->default(0);
            $table->integer('addresses')->default(0);
            $table->integer('hub_contact_count')->nullable();
            $table->integer('hub_address_count')->nullable();
            $table->string('cursor')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sync_runs');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['erp_name']);
            $table->dropColumn('erp_name');
        });

        Schema::dropIfExists('erp_links');
    }
};
