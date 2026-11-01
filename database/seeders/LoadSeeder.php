<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LoadSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The load seed refuses to run in production.');
        }

        $count = max(1, (int) config('fibes.load.customers', 30000));

        if (DB::table('customers')->where('account_no', 'like', 'LD-%')->exists()) {
            return;
        }

        $owner = User::query()->where('role', 'super_admin')->value('id');
        $tech = User::query()->firstOrCreate(
            ['email' => 'load-tech@fibes.test'],
            ['name' => 'Load Tech', 'password' => 'password', 'role' => 'field_technician', 'is_active' => true],
        );
        $category = DB::table('ticket_categories')->where('key', 'load')->value('id');

        if ($category === null) {
            $category = DB::table('ticket_categories')->insertGetId([
                'key' => 'load',
                'name_en' => 'Load',
                'name_ar' => 'حمل',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $now = now()->toDateTimeString();
        $week = now()->startOfWeek();
        DB::disableQueryLog();

        for ($offset = 1; $offset <= $count; $offset += 200) {
            $rows = [];
            $end = min($count, $offset + 199);

            for ($i = $offset; $i <= $end; $i++) {
                $rows[] = [
                    'account_no' => sprintf('LD-%06d', $i),
                    'type' => 'individual',
                    'status' => $i % 17 === 0 ? 'suspended' : 'active',
                    'name_en' => 'Load '.$i,
                    'name_ar' => $i === 1 ? 'أحمد حمل' : 'حمل '.$i,
                    'owner_id' => $owner,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('customers')->insert($rows);
        }

        $ids = DB::table('customers')->where('account_no', 'like', 'LD-%')->orderBy('id')->pluck('id')->all();
        $this->chunk($ids, function (array $slice, int $base) use ($now) {
            $phones = [];
            $services = [];

            foreach ($slice as $index => $id) {
                $n = $base + $index + 1;
                $phones[] = [
                    'customer_id' => $id,
                    'phone_e164' => sprintf('+9613%07d', $n),
                    'is_primary' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($n % 2 === 0) {
                    $services[] = [
                        'customer_id' => $id,
                        'service_type' => 'ftth',
                        'plan_name' => 'Home',
                        'status' => 'active',
                        'monthly_usd' => '35.00',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            DB::table('customer_phones')->insert($phones);

            if ($services !== []) {
                DB::table('customer_services')->insert($services);
            }
        });

        $this->chunk(array_slice($ids, 0, max(1, intdiv($count, 10))), function (array $slice, int $base) use ($category, $owner, $now) {
            $rows = [];

            foreach ($slice as $index => $id) {
                $n = $base + $index + 1;
                $rows[] = [
                    'number' => sprintf('LD-T-%06d', $n),
                    'customer_id' => $id,
                    'category_id' => $category,
                    'priority' => 'P3',
                    'channel' => 'phone',
                    'status' => 'open',
                    'subject' => 'Load ticket '.$n,
                    'owner_id' => $owner,
                    'response_minutes' => 60,
                    'resolve_minutes' => 480,
                    'response_due_at' => $now,
                    'resolve_due_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('tickets')->insert($rows);
        });

        $jobIds = array_slice($ids, 0, max(1, intdiv($count, 20)));
        $this->chunk($jobIds, function (array $slice, int $base) use ($tech, $owner, $week) {
            $rows = [];

            foreach ($slice as $index => $id) {
                $n = $base + $index + 1;
                $start = $week->copy()->addHours($n % (7 * 24));
                $rows[] = [
                    'number' => sprintf('LD-J-%06d', $n),
                    'type' => 'repair',
                    'status' => 'scheduled',
                    'customer_id' => $id,
                    'technician_id' => $n % 25 === 0 ? null : $tech->id,
                    'window_start' => $start->toDateTimeString(),
                    'window_end' => $start->copy()->addHours(2)->toDateTimeString(),
                    'owner_id' => $owner,
                    'created_at' => $start->toDateTimeString(),
                    'updated_at' => $start->toDateTimeString(),
                ];
            }

            DB::table('field_jobs')->insert($rows);
        });

        $this->chunk(array_slice($ids, 0, max(1, intdiv($count, 5))), function (array $slice, int $base) use ($now) {
            $rows = [];

            foreach ($slice as $index => $id) {
                $n = $base + $index + 1;
                $rows[] = [
                    'ulid' => sprintf('01LOAD%020d', $n),
                    'number' => sprintf('LD-INV-%06d', $n),
                    'customer_id' => $id,
                    'type' => 'invoice',
                    'currency' => 'USD',
                    'exchange_rate' => '89500.0000',
                    'subtotal' => '35.00',
                    'discount' => '0.00',
                    'tax' => '3.85',
                    'total' => '38.85',
                    'lbp_total' => '3477075.00',
                    'status' => 'issued',
                    'issued_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('invoices')->insert($rows);
        });

        $this->chunk(array_slice($ids, 0, max(1, intdiv($count, 10))), function (array $slice, int $base) use ($owner, $now) {
            $rows = [];

            foreach ($slice as $index => $id) {
                $n = $base + $index + 1;
                $rows[] = [
                    'ulid' => sprintf('01LOGS%020d', $n),
                    'occurred_at' => $now,
                    'actor_id' => $owner,
                    'event' => 'customer.noted',
                    'metadata' => json_encode(['customer_id' => $id]),
                ];
            }

            DB::table('audit_log')->insert($rows);
        });
    }

    /**
     * @param  list<int>  $ids
     * @param  callable(list<int>, int): void  $write
     */
    private function chunk(array $ids, callable $write): void
    {
        $base = 0;

        foreach (array_chunk($ids, 200) as $slice) {
            $write($slice, $base);
            $base += count($slice);
        }
    }
}
