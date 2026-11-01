<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->boolean('is_builtin')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->string('home_route')->default('/dashboard');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('module');
            $table->string('label_en');
            $table->string('label_ar');
            $table->timestamps();
        });

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 8);
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_role', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['team_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('force_two_factor')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('user_role');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('force_two_factor');
        });
    }
};
