<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('type', 30);
            $table->string('file')->nullable();
            $table->json('mapping');
            $table->json('counts');
            $table->json('applied')->nullable();
            $table->json('snapshots')->nullable();
            $table->string('status', 20);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->timestamp('undo_until')->nullable();
            $table->timestamps();
        });

        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('ulid', 26)->unique();
            $table->string('token', 48)->unique();
            $table->string('type', 30);
            $table->json('filters')->nullable();
            $table->string('format', 8);
            $table->string('status', 20);
            $table->string('path')->nullable();
            $table->unsignedInteger('rows')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        foreach (['customers', 'leads', 'customer_services', 'stock_items', 'price_list_items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->timestamp('archived_at')->nullable();
                $blueprint->unsignedBigInteger('import_batch_id')->nullable()->index();
            });
        }

        Schema::table('coverage_items', function (Blueprint $blueprint) {
            $blueprint->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('import_batches');
    }
};
