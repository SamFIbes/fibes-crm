<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\Access\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverage_items', function (Blueprint $table) {
            $table->id();
            $table->string('service_type', 30)->default('ftth');
            $table->string('kind', 20);
            $table->string('name');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->json('polygon')->nullable();
            $table->decimal('azimuth', 6, 2)->nullable();
            $table->decimal('beamwidth', 6, 2)->nullable();
            $table->decimal('range_m', 10, 2)->nullable();
            $table->unsignedInteger('capacity_total')->default(0);
            $table->unsignedInteger('capacity_used')->default(0);
            $table->string('status', 20)->default('live')->index();
            $table->string('source', 20)->default('manual');
            $table->string('import_batch_id', 40)->nullable()->index();
            $table->foreignId('network_node_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('coverage_checks', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('address_text')->nullable();
            $table->string('result', 20);
            $table->json('matched_items')->nullable();
            $table->decimal('distance_m', 10, 1)->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('survey_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->unique()->constrained('field_jobs')->cascadeOnDelete();
            $table->boolean('feasible')->default(false);
            $table->string('reason')->nullable();
            $table->decimal('distance_m', 10, 1)->nullable();
            $table->string('los', 20)->nullable();
            $table->decimal('signal_dbm', 6, 1)->nullable();
            $table->decimal('cable_m', 8, 1)->nullable();
            $table->string('mounting_point')->nullable();
            $table->boolean('riser_ok')->default(false);
            $table->boolean('power_ok')->default(false);
            $table->json('materials')->nullable();
            $table->string('recommended_service_type', 30)->nullable();
            $table->json('photos')->nullable();
            $table->timestamps();
        });

        Schema::table('field_jobs', function (Blueprint $table) {
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->decimal('last_latitude', 10, 7)->nullable();
            $table->decimal('last_longitude', 10, 7)->nullable();
            $table->timestamp('last_located_at')->nullable();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
                DB::statement('ALTER TABLE coverage_items ADD COLUMN geom geometry(Geometry, 4326)');
                DB::statement('CREATE INDEX coverage_items_geom_gix ON coverage_items USING GIST (geom)');
                DB::statement('ALTER TABLE coverage_checks ADD COLUMN geom geometry(Point, 4326)');
                DB::statement('CREATE INDEX coverage_checks_geom_gix ON coverage_checks USING GIST (geom)');
            } catch (Throwable) {
                // The test database is SQLite. PostGIS is used when the extension is present.
            }
        }

        $this->grant();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_latitude', 'last_longitude', 'last_located_at']);
        });
        Schema::table('field_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opportunity_id');
        });
        Schema::dropIfExists('survey_results');
        Schema::dropIfExists('coverage_checks');
        Schema::dropIfExists('coverage_items');
    }

    private function grant(): void
    {
        if (! Schema::hasTable('roles') || Role::query()->doesntExist()) {
            return;
        }

        $key = 'coverage.edit';
        $meta = Catalog::permissions()[$key] ?? null;

        if (! is_array($meta)) {
            return;
        }

        $permission = Permission::query()->updateOrCreate(['key' => $key], $meta);

        foreach (Catalog::grants()[$key] ?? [] as $roleKey => $scope) {
            $role = Role::query()->where('key', $roleKey)->first();

            if ($role) {
                $role->permissions()->syncWithoutDetaching([$permission->id => ['scope' => $scope]]);
            }
        }
    }
};
