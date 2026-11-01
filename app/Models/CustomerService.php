<?php

namespace App\Models;

use App\Services\Automations\AutomationDesk;
use App\Services\Performance\CommissionDesk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerService extends Model
{
    protected $fillable = [
        'customer_id', 'service_type', 'plan_name', 'plan_id', 'speed', 'area_id', 'network_node_id', 'install_date', 'status',
        'monthly_usd', 'radius_username', 'radius_password', 'static_ip', 'mac', 'nas', 'vlan',
        'ont_serial', 'router_model', 'subscription_id', 'order_id', 'activated_at', 'suspended_at',
        'suspension_reason', 'terminated_at', 'termination_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'install_date' => 'date',
            'monthly_usd' => 'decimal:2',
            'radius_password' => 'encrypted',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (CustomerService $service): void {
            if ($service->wasChanged('status') && $service->status === 'terminated') {
                app(CommissionDesk::class)->proposeClawback($service);
            }

            if ($service->wasChanged('status') && $service->status === 'suspended') {
                app(AutomationDesk::class)->emit('service.suspended', [
                    'subject_type' => 'customer_service', 'subject_id' => $service->id, 'customer_id' => $service->customer_id,
                ]);
            }
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }
}
