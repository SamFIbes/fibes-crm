<?php

namespace App\Services\Coverage;

use App\Models\CoverageCheck;
use App\Models\CoverageItem;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Audit\AuditLogger;
use App\Services\Leads\LeadMachine;
use App\Services\Settings\SettingsStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CoverageDesk
{
    public function __construct(
        private Geo $geo,
        private AuditLogger $audit,
        private SettingsStore $settings,
        private LeadMachine $leads,
        private CoverageImport $imports,
        private FiberDesk $fiberdesk,
    ) {}

    /**
     * @return array{covered_m: int, near_m: int, tile_url: string}
     */
    public function limits(): array
    {
        $stored = $this->settings->get('coverage', []);

        return [
            'covered_m' => (int) ($stored['covered_m'] ?? config('fibes.coverage.covered_m')),
            'near_m' => (int) ($stored['near_m'] ?? config('fibes.coverage.near_m')),
            'tile_url' => (string) ($stored['tile_url'] ?? config('fibes.coverage.tile_url')),
        ];
    }

    /**
     * @return array{result: string, distance_m: float|null, matched: list<array<string, mixed>>}
     */
    public function evaluate(float $lat, float $lng, ?int $customerId = null): array
    {
        $limits = $this->limits();
        $nearPad = ($limits['near_m'] * 1.4) / 111320;
        $wide = ((float) config('fibes.coverage.sector_m', 8000)) / 111320;
        $items = CoverageItem::query()
            ->where(function ($query) use ($lat, $lng, $nearPad, $wide): void {
                $query->where(function ($near) use ($lat, $lng, $nearPad): void {
                    $near->whereIn('kind', ['fat', 'olt'])
                        ->whereBetween('latitude', [$lat - $nearPad, $lat + $nearPad])
                        ->whereBetween('longitude', [$lng - $nearPad, $lng + $nearPad]);
                })->orWhere(function ($far) use ($lat, $lng, $wide): void {
                    $far->whereIn('kind', ['building', 'polygon', 'tower', 'sector'])
                        ->whereBetween('latitude', [$lat - $wide, $lat + $wide])
                        ->whereBetween('longitude', [$lng - $wide, $lng + $wide]);
                });
            })
            ->get();

        $matched = [];
        $covered = false;
        $wireless = false;
        $nearest = null;

        foreach ($items as $item) {
            if ($item->status === 'planned') {
                continue;
            }

            $distance = $this->geo->meters($lat, $lng, (float) $item->latitude, (float) $item->longitude);
            $inside = is_array($item->polygon) && $item->polygon !== [] && $this->geo->contains($lat, $lng, $item->polygon);
            $hit = [
                'id' => $item->id,
                'name' => $item->name,
                'kind' => $item->kind,
                'distance_m' => (int) round($distance),
                'free' => $item->freePorts(),
            ];

            if ($nearest === null || $distance < $nearest) {
                $nearest = $distance;
            }

            if ($item->kind === 'building' && $inside && $item->status === 'live' && ! $item->isFull()) {
                $covered = true;
                $matched[] = $hit;
            }

            if ($item->kind === 'fat' && $item->status === 'live' && ! $item->isFull() && $distance <= $limits['covered_m']) {
                $covered = true;
                $matched[] = $hit;
            }

            if (in_array($item->kind, ['tower', 'sector'], true) && $inside && $item->status === 'live' && ! $item->isFull()) {
                $wireless = true;
                $matched[] = $hit;
            }
        }

        $sameBuilding = $customerId !== null && $this->customerNearby($customerId, $lat, $lng);

        if ($covered) {
            $result = 'covered';
        } elseif ($wireless && $sameBuilding) {
            $result = 'covered';
        } elseif ($wireless) {
            $result = 'needs_survey';
        } elseif ($nearest !== null && $nearest <= $limits['near_m']) {
            $result = 'near';
        } else {
            $result = 'not_covered';
        }

        return [
            'result' => $result,
            'distance_m' => $nearest === null ? null : round($nearest, 1),
            'matched' => $matched,
        ];
    }

    public function record(string $subject, int $subjectId, float $lat, float $lng, ?string $address, User $actor, Request $request): CoverageCheck
    {
        $customerId = null;

        if ($subject === 'opportunity') {
            $customerId = Opportunity::query()->whereKey($subjectId)->value('customer_id');
        }

        $result = $this->evaluate($lat, $lng, is_numeric($customerId) ? (int) $customerId : null);
        $check = CoverageCheck::query()->create([
            'subject_type' => $subject,
            'subject_id' => $subjectId,
            'latitude' => $lat,
            'longitude' => $lng,
            'address_text' => $address,
            'result' => $result['result'],
            'matched_items' => $result['matched'],
            'distance_m' => $result['distance_m'],
            'checked_by' => $actor->id,
            'checked_at' => now(),
        ]);

        if ($subject === 'lead') {
            $lead = Lead::query()->find($subjectId);

            if ($lead instanceof Lead) {
                $lead->latitude = $lat;
                $lead->longitude = $lng;

                if ($result['result'] === 'not_covered' && $this->leads->allows($lead->status, 'waitlisted')) {
                    $lead->status = 'waitlisted';
                }

                $lead->save();
            }
        }

        if ($subject === 'opportunity') {
            Opportunity::query()->whereKey($subjectId)->update([
                'coverage_check_id' => $check->id,
            ]);
        }

        $this->audit->record('coverage.checked', $actor, [
            'check_id' => $check->id,
            'subject' => $subject,
            'subject_id' => $subjectId,
            'result' => $check->result,
        ], $request);

        return $check;
    }

    public function publish(CoverageItem $item, User $actor, Request $request): int
    {
        $item->status = 'live';
        $item->save();
        $woken = 0;
        $limits = $this->limits();

        Lead::query()->where('status', 'waitlisted')->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('id')->each(function (Lead $lead) use ($item, $actor, $limits, &$woken): void {
            $distance = $this->geo->meters((float) $lead->latitude, (float) $lead->longitude, (float) $item->latitude, (float) $item->longitude);
            $inside = is_array($item->polygon) && $this->geo->contains((float) $lead->latitude, (float) $lead->longitude, $item->polygon);

            if (! $inside && $distance > $limits['near_m']) {
                return;
            }

            $exists = WorkTask::query()->where('lead_id', $lead->id)->where('kind', 'coverage_ready')->exists();

            if ($exists) {
                return;
            }

            WorkTask::query()->create([
                'kind' => 'coverage_ready',
                'title' => __('ui.coverage.ready_title', ['name' => $lead->displayName()]),
                'lead_id' => $lead->id,
                'customer_id' => null,
                'assignee_id' => $lead->owner_id,
                'owner_id' => $actor->id,
                'due_at' => now()->addDay(),
                'status' => 'open',
                'priority' => 'high',
            ]);

            if ($this->leads->allows($lead->status, 'assigned')) {
                $lead->status = 'assigned';
                $lead->save();
            }

            $woken++;
        });

        $this->audit->record('coverage.published', $actor, [
            'item_id' => $item->id,
            'leads' => $woken,
        ], $request);

        return $woken;
    }

    /**
     * @return array{preview: list<array<string, mixed>>, written: int}
     */
    public function import(string $contents, string $name, bool $apply, User $actor, Request $request): array
    {
        $rows = str_ends_with(strtolower($name), '.fiberdesk')
            ? $this->fiberdesk->rows($contents)
            : $this->imports->parse($contents, $name);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('ui.coverage.malformed')]);
        }

        if (! $apply) {
            return ['preview' => $rows, 'written' => 0];
        }

        $batch = (string) str()->ulid();
        $written = 0;

        DB::transaction(function () use ($rows, $batch, $actor, &$written): void {
            foreach ($rows as $row) {
                $this->storeItem($row, $batch, $actor);
                $written++;
            }
        });

        $this->audit->record('coverage.imported', $actor, [
            'batch' => $batch,
            'count' => $written,
            'file' => $name,
        ], $request);

        return ['preview' => $rows, 'written' => $written];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function storeItem(array $row, ?string $batch, ?User $actor): CoverageItem
    {
        $polygon = $row['polygon'] ?? null;

        if (($row['kind'] ?? '') === 'sector' && isset($row['azimuth'], $row['beamwidth'], $row['range_m'])) {
            $polygon = $this->geo->sector(
                (float) $row['latitude'],
                (float) $row['longitude'],
                (float) $row['azimuth'],
                (float) $row['beamwidth'],
                (float) $row['range_m'],
            );
        }

        return CoverageItem::query()->create([
            'service_type' => $row['service_type'] ?? 'ftth',
            'kind' => $row['kind'],
            'name' => $row['name'],
            'latitude' => $row['latitude'],
            'longitude' => $row['longitude'],
            'polygon' => $polygon,
            'azimuth' => $row['azimuth'] ?? null,
            'beamwidth' => $row['beamwidth'] ?? null,
            'range_m' => $row['range_m'] ?? null,
            'capacity_total' => $row['capacity_total'] ?? 0,
            'capacity_used' => $row['capacity_used'] ?? 0,
            'status' => $row['status'] ?? 'live',
            'source' => $row['source'] ?? 'manual',
            'import_batch_id' => $batch,
            'network_node_id' => $row['network_node_id'] ?? null,
        ]);
    }

    private function customerNearby(int $customerId, float $lat, float $lng): bool
    {
        $customer = Customer::query()->find($customerId);

        if (! $customer instanceof Customer || $customer->latitude === null || $customer->longitude === null) {
            return false;
        }

        return $this->geo->meters($lat, $lng, (float) $customer->latitude, (float) $customer->longitude) <= 30;
    }
}
