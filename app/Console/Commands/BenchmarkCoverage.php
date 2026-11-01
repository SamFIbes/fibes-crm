<?php

namespace App\Console\Commands;

use App\Models\CoverageItem;
use App\Services\Coverage\CoverageDesk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BenchmarkCoverage extends Command
{
    protected $signature = 'coverage:benchmark {--count=50000}';

    protected $description = 'Time a nearest-FAT check against a batch of coverage items.';

    public function handle(CoverageDesk $coverage): int
    {
        $count = max(1, (int) $this->option('count'));
        $batch = (string) Str::ulid();
        $started = microtime(true);
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'service_type' => 'ftth',
                'kind' => 'fat',
                'name' => 'FAT '.$i,
                'latitude' => 33.80 + (($i % 500) * 0.0002),
                'longitude' => 35.45 + ((int) ($i / 500) * 0.0002),
                'capacity_total' => 8,
                'capacity_used' => $i % 17 === 0 ? 8 : 1,
                'status' => 'live',
                'source' => 'csv',
                'import_batch_id' => $batch,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($rows) === 1000) {
                DB::table('coverage_items')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('coverage_items')->insert($rows);
        }

        $seeded = (int) round((microtime(true) - $started) * 1000);
        $queryStarted = microtime(true);
        $result = $coverage->evaluate(33.85, 35.46);
        $query = (int) round((microtime(true) - $queryStarted) * 1000);
        CoverageItem::query()->where('import_batch_id', $batch)->delete();

        $this->info('driver='.DB::connection()->getDriverName()." items={$count} seed_ms={$seeded} query_ms={$query} result={$result['result']} distance_m=".($result['distance_m'] ?? 'null'));

        return self::SUCCESS;
    }
}
