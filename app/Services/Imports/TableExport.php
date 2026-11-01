<?php

namespace App\Services\Imports;

use App\Jobs\BuildCustomerExport;
use App\Models\Customer;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\CommercialSettings;
use App\Support\Access\AccessGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TableExport
{
    public function __construct(
        private AccessGate $access,
        private AuditLogger $audit,
        private CommercialSettings $commercial,
    ) {}

    public function customers(User $actor, Request $request): object
    {
        $count = $this->rows($actor)->count();
        $threshold = max(1, (int) $this->commercial->get('report_async_rows'));
        $token = Str::random(40);
        $hours = max(1, (int) $this->commercial->get('report_link_hours'));
        $id = DB::table('export_jobs')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'token' => $token,
            'type' => 'customers',
            'filters' => json_encode(['scope' => $this->access->scopeFor($actor, 'customers.view')]),
            'format' => 'csv',
            'status' => 'pending',
            'path' => null,
            'rows' => $count,
            'requested_by' => $actor->id,
            'expires_at' => now()->addHours($hours),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit->record('export.created', $actor, ['export_id' => $id, 'type' => 'customers', 'rows' => $count], $request);

        if ($count >= $threshold) {
            BuildCustomerExport::dispatch($id);
        } else {
            $this->write($id);
        }

        return DB::table('export_jobs')->where('id', $id)->first();
    }

    public function write(int $id): void
    {
        $job = DB::table('export_jobs')->where('id', $id)->first();
        $actor = $job === null ? null : User::query()->find($job->requested_by);

        if ($job === null || $job->path || ! $actor instanceof User) {
            return;
        }

        $rows = $this->rows($actor)->orderBy('id')->get(['id', 'account_no', 'name_en']);
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['account_no', 'name_en']);

        foreach ($rows as $row) {
            fputcsv($handle, [$row->account_no, $row->name_en]);
        }

        rewind($handle);
        $body = stream_get_contents($handle) ?: '';
        fclose($handle);
        $path = 'exports/'.$job->token.'.csv';
        Storage::disk('local')->put($path, $body);
        DB::table('export_jobs')->where('id', $id)->update([
            'status' => 'ready',
            'path' => $path,
            'rows' => $rows->count(),
            'updated_at' => now(),
        ]);
    }

    public function download(string $token): string
    {
        $job = DB::table('export_jobs')->where('token', $token)->first();
        $expired = $job !== null && $job->expires_at !== null && now()->greaterThan(Carbon::parse($job->expires_at));

        if ($job === null || $job->expires_at === null || $expired || ! $job->path || ! Storage::disk('local')->exists($job->path)) {
            abort(403);
        }

        return Storage::disk('local')->path($job->path);
    }

    private function rows(User $actor): Builder
    {
        return $this->access->restrict(Customer::query()->whereNull('archived_at'), $actor, 'customers.view', 'owner_id', 'team_id');
    }
}
