<?php

namespace App\Services\Imports;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\CommercialSettings;
use App\Support\Phone\PhoneNumbers;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ImportDesk
{
    /** @var array<string, list<string>> */
    public const REQUIRED = [
        'customers' => ['name', 'phone'],
        'services' => ['phone', 'plan'],
        'leads' => ['name', 'phone'],
        'coverage' => ['name', 'latitude', 'longitude'],
        'inventory' => ['sku', 'name'],
        'prices' => ['sku', 'price'],
    ];

    /** @var list<string> */
    public const KRYPTON = ['Name', 'Username', 'Status', 'Phone Number', 'Mobile Number'];

    public function __construct(
        private PhoneNumbers $phones,
        private ImportMachine $machine,
        private CommercialSettings $commercial,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, string>  $mapping
     * @return array{created: int, skipped: int, errors: list<string>}
     */
    public function preview(string $type, string $csv, array $mapping): array
    {
        return $this->plan($type, $csv, $mapping)['counts'];
    }

    /**
     * @param  array<string, string>  $mapping
     */
    public function apply(string $type, string $csv, array $mapping, User $actor, Request $request): object
    {
        $plan = $this->plan($type, $csv, $mapping);
        $applied = [];
        $snapshots = [];

        foreach ($plan['rows'] as $row) {
            $id = $this->insert($type, $row, null);
            $applied[$type][] = $id;
        }

        $batch = DB::table('import_batches')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'type' => $type,
            'mapping' => json_encode($mapping),
            'counts' => json_encode($plan['counts']),
            'applied' => json_encode($applied),
            'snapshots' => json_encode($snapshots),
            'status' => 'applied',
            'created_by' => $actor->id,
            'undo_until' => now()->addHours(max(1, (int) $this->commercial->get('import_undo_hours'))),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($applied[$type] ?? [] as $id) {
            $this->stamp($type, (int) $id, (int) $batch);
        }

        $this->audit->record('import.applied', $actor, ['batch_id' => $batch, 'type' => $type, 'counts' => $plan['counts']], $request);

        return DB::table('import_batches')->where('id', $batch)->first();
    }

    public function undo(int $batchId, User $actor, Request $request): void
    {
        $batch = DB::table('import_batches')->where('id', $batchId)->first();

        if ($batch === null) {
            throw ValidationException::withMessages(['batch' => __('ui.imports.missing')]);
        }

        $this->machine->assert((string) $batch->status, 'undone');

        if ($batch->undo_until !== null && now()->greaterThan(Carbon::parse((string) $batch->undo_until))) {
            throw ValidationException::withMessages(['undo' => __('ui.imports.too_late')]);
        }

        $applied = json_decode((string) $batch->applied, true) ?: [];
        $snapshots = json_decode((string) $batch->snapshots, true) ?: [];

        foreach ($snapshots as $snap) {
            if (isset($snap['id'], $snap['was'])) {
                DB::table('customers')->where('id', $snap['id'])->update(['name_en' => $snap['was'], 'updated_at' => now()]);
            }
        }

        foreach ($applied as $type => $ids) {
            foreach ($ids as $id) {
                $this->archive((string) $type, (int) $id);
            }
        }

        DB::table('import_batches')->where('id', $batchId)->update([
            'status' => 'undone',
            'undone_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit->record('import.undone', $actor, ['batch_id' => $batchId, 'type' => $batch->type], $request);
    }

    /**
     * @return array{new: int, changed: int, missing: int, errors: list<string>, worklist: list<array{phone: string, name: string, suggestion: string|null}>}
     */
    public function kryptonPreview(string $csv): array
    {
        $this->guardKrypton();
        $plan = $this->kryptonPlan($csv);

        return $plan['counts'] + ['worklist' => $plan['worklist']];
    }

    public function kryptonApply(string $csv, User $actor, Request $request): object
    {
        $this->guardKrypton();
        $plan = $this->kryptonPlan($csv);
        $created = [];
        $snapshots = [];

        foreach ($plan['create'] as $row) {
            $created[] = $this->insert('customers', $row, null);
        }

        foreach ($plan['change'] as $row) {
            $snapshots[] = ['id' => $row['id'], 'was' => $row['was']];
            DB::table('customers')->where('id', $row['id'])->update(['name_en' => $row['name'], 'updated_at' => now()]);
        }

        $batch = DB::table('import_batches')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'type' => 'krypton',
            'mapping' => json_encode(['fixed' => self::KRYPTON]),
            'counts' => json_encode($plan['counts']),
            'applied' => json_encode(['customers' => $created]),
            'snapshots' => json_encode($snapshots),
            'status' => 'applied',
            'created_by' => $actor->id,
            'undo_until' => now()->addHours(max(1, (int) $this->commercial->get('import_undo_hours'))),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($created as $id) {
            $this->stamp('customers', (int) $id, (int) $batch);
        }

        $this->audit->record('import.applied', $actor, ['batch_id' => $batch, 'type' => 'krypton', 'counts' => $plan['counts']], $request);

        return DB::table('import_batches')->where('id', $batch)->first();
    }

    /**
     * @param  array<string, string>  $mapping
     * @return array{counts: array{created: int, skipped: int, errors: list<string>}, rows: list<array<string, string>>}
     */
    private function plan(string $type, string $csv, array $mapping): array
    {
        if (! isset(self::REQUIRED[$type])) {
            throw ValidationException::withMessages(['type' => __('ui.imports.illegal')]);
        }

        [$headers, $records] = $this->table($csv);

        foreach (self::REQUIRED[$type] as $field) {
            $column = $mapping[$field] ?? '';

            if ($column === '' || ! in_array($column, $headers, true)) {
                throw ValidationException::withMessages(['mapping' => __('ui.imports.missing_column', ['column' => $field])]);
            }
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        $rows = [];
        $seen = [];

        foreach ($records as $index => $record) {
            $mapped = [];

            foreach (self::REQUIRED[$type] as $field) {
                $mapped[$field] = trim((string) ($record[$mapping[$field]] ?? ''));
            }

            if (isset($mapped['phone'])) {
                try {
                    $mapped['phone'] = $this->phones->normalize($mapped['phone']);
                } catch (InvalidArgumentException) {
                    $errors[] = __('ui.imports.bad_phone', ['row' => $index + 2]);

                    continue;
                }

                if (isset($seen[$mapped['phone']]) || $this->phoneExists($type, $mapped['phone'])) {
                    $skipped++;

                    continue;
                }

                $seen[$mapped['phone']] = true;
            }

            if (($mapped['sku'] ?? '') !== '' && $type === 'inventory' && DB::table('stock_items')->where('sku', $mapped['sku'])->exists()) {
                $skipped++;

                continue;
            }

            if ($type === 'prices' && ! DB::table('products')->where('sku', $mapped['sku'])->exists()) {
                $errors[] = __('ui.imports.unknown_sku', ['row' => $index + 2]);

                continue;
            }

            $rows[] = $mapped;
            $created++;
        }

        return ['counts' => ['created' => $created, 'skipped' => $skipped, 'errors' => $errors], 'rows' => $rows];
    }

    /**
     * @return array{counts: array{new: int, changed: int, missing: int, errors: list<string>}, create: list<array<string, string>>, change: list<array{id: int, name: string, was: string}>, worklist: list<array{phone: string, name: string, suggestion: string|null}>}
     */
    private function kryptonPlan(string $csv): array
    {
        [$headers, $records] = $this->table($csv);

        foreach (self::KRYPTON as $header) {
            if (! in_array($header, $headers, true)) {
                throw ValidationException::withMessages(['file' => __('ui.imports.missing_column', ['column' => $header])]);
            }
        }

        $errors = [];
        $create = [];
        $change = [];
        $phones = [];

        foreach ($records as $index => $record) {
            $raw = trim((string) ($record['Mobile Number'] ?: $record['Phone Number']));

            try {
                $phone = $this->phones->normalize($raw);
            } catch (InvalidArgumentException) {
                $errors[] = __('ui.imports.bad_phone', ['row' => $index + 2]);

                continue;
            }

            $phones[] = $phone;
            $existing = DB::table('customer_phones')->where('phone_e164', $phone)->first();

            if ($existing === null) {
                $create[] = ['name' => trim((string) $record['Name']), 'phone' => $phone];
            } else {
                $was = (string) DB::table('customers')->where('id', $existing->customer_id)->value('name_en');
                $change[] = ['id' => (int) $existing->customer_id, 'name' => trim((string) $record['Name']), 'was' => $was];
            }
        }

        $knownRows = DB::table('customer_phones')
            ->join('customers', 'customers.id', '=', 'customer_phones.customer_id')
            ->whereNull('customers.archived_at')
            ->get(['customer_phones.phone_e164 as phone', 'customers.name_en as name']);
        $known = $knownRows->pluck('phone')->all();
        $missingPhones = array_values(array_diff($known, $phones));
        $names = [];

        foreach ($knownRows as $row) {
            $names[(string) $row->phone] = (string) $row->name;
        }

        $worklist = [];

        foreach ($missingPhones as $phone) {
            $suggestion = null;
            $tail = substr((string) $phone, -6);

            foreach ($phones as $candidate) {
                if ($candidate !== $phone && str_ends_with($candidate, $tail)) {
                    $suggestion = $candidate;
                    break;
                }
            }

            $worklist[] = ['phone' => (string) $phone, 'name' => $names[(string) $phone] ?? '', 'suggestion' => $suggestion];
        }

        return [
            'counts' => ['new' => count($create), 'changed' => count($change), 'missing' => count($missingPhones), 'errors' => $errors],
            'create' => $create,
            'change' => $change,
            'worklist' => $worklist,
        ];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function insert(string $type, array $row, ?int $batch): int
    {
        $now = now();

        return match ($type) {
            'customers' => $this->insertCustomer($row, $batch, $now),
            'leads' => (int) DB::table('leads')->insertGetId([
                'name' => $row['name'], 'phone_e164' => $row['phone'], 'status' => 'new', 'import_batch_id' => $batch,
                'created_at' => $now, 'updated_at' => $now,
            ]),
            'services' => $this->insertService($row, $batch, $now),
            'coverage' => (int) DB::table('coverage_items')->insertGetId([
                'kind' => 'fat', 'name' => $row['name'], 'latitude' => $row['latitude'], 'longitude' => $row['longitude'],
                'status' => 'live', 'source' => 'import', 'import_batch_id' => $batch === null ? null : (string) $batch,
                'created_at' => $now, 'updated_at' => $now,
            ]),
            'inventory' => (int) DB::table('stock_items')->insertGetId([
                'sku' => $row['sku'], 'name_en' => $row['name'], 'name_ar' => $row['name'], 'unit' => 'piece',
                'import_batch_id' => $batch, 'created_at' => $now, 'updated_at' => $now,
            ]),
            'prices' => (int) DB::table('price_list_items')->insertGetId([
                'product_id' => DB::table('products')->where('sku', $row['sku'])->value('id'),
                'price' => bcadd($row['price'], '0', 2), 'currency' => 'USD', 'valid_from' => $now->toDateString(),
                'import_batch_id' => $batch, 'created_at' => $now, 'updated_at' => $now,
            ]),
            default => throw ValidationException::withMessages(['type' => __('ui.imports.illegal')]),
        };
    }

    /**
     * @param  array<string, string>  $row
     */
    private function insertCustomer(array $row, ?int $batch, Carbon $now): int
    {
        $id = (int) DB::table('customers')->insertGetId([
            'account_no' => 'IM-'.strtoupper(Str::random(8)),
            'type' => 'individual',
            'status' => 'active',
            'name_en' => $row['name'],
            'name_ar' => $row['name'],
            'import_batch_id' => $batch,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('customer_phones')->insert([
            'customer_id' => $id, 'phone_e164' => $row['phone'], 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function insertService(array $row, ?int $batch, Carbon $now): int
    {
        $customerId = DB::table('customer_phones')->where('phone_e164', $row['phone'])->value('customer_id');

        if ($customerId === null) {
            $row['name'] = $row['name'] ?? $row['phone'];
            $customerId = $this->insertCustomer($row, $batch, $now);
        }

        return (int) DB::table('customer_services')->insertGetId([
            'customer_id' => $customerId,
            'service_type' => 'ftth',
            'plan_name' => $row['plan'],
            'status' => 'pending',
            'monthly_usd' => '0.00',
            'import_batch_id' => $batch,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function stamp(string $type, int $id, int $batch): void
    {
        $table = $this->tableName($type);

        if ($table === null) {
            return;
        }

        $values = ['import_batch_id' => $table === 'coverage_items' ? (string) $batch : $batch];
        DB::table($table)->where('id', $id)->update($values);
    }

    private function archive(string $type, int $id): void
    {
        $table = $this->tableName($type);

        if ($table === null) {
            return;
        }

        DB::table($table)->where('id', $id)->update(['archived_at' => now()]);
    }

    private function tableName(string $type): ?string
    {
        return match ($type) {
            'customers' => 'customers',
            'leads' => 'leads',
            'services' => 'customer_services',
            'coverage' => 'coverage_items',
            'inventory' => 'stock_items',
            'prices' => 'price_list_items',
            default => null,
        };
    }

    private function phoneExists(string $type, string $phone): bool
    {
        if ($type === 'leads') {
            return DB::table('leads')->where('phone_e164', $phone)->exists();
        }

        if (in_array($type, ['customers', 'services'], true)) {
            return DB::table('customer_phones')->where('phone_e164', $phone)->exists();
        }

        return false;
    }

    /**
     * @return array{0: list<string>, 1: list<array<string, string>>}
     */
    private function table(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        $headers = str_getcsv((string) array_shift($lines));
        $headers = array_map(fn ($header) => trim((string) $header), $headers);
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cols = str_getcsv($line);
            $record = [];

            foreach ($headers as $index => $header) {
                $record[$header] = trim((string) ($cols[$index] ?? ''));
            }

            $rows[] = $record;
        }

        return [$headers, $rows];
    }

    private function guardKrypton(): void
    {
        if (! (bool) $this->commercial->get('krypton_import')) {
            throw ValidationException::withMessages(['krypton' => __('ui.imports.krypton_off')]);
        }
    }
}
