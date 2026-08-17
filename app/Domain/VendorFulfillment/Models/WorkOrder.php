<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Models;

use App\Domain\VendorFulfillment\WorkOrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `work_orders` — the primary fulfillment record for
 * a care service visit.
 *
 * Billing status and work status are TWO separate indicators (AC2).
 * One work order per paid subscription cycle (AC5/AC8).
 */
final class WorkOrder extends Model
{
    use HasUuids;

    protected $table = 'work_orders';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'care_plan_id',
        'subscription_cycle_id',
        'vendor_id',
        'assigned_to',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function status(): WorkOrderStatus
    {
        return WorkOrderStatus::from($this->status);
    }

    /**
     * @return BelongsTo<\App\Domain\CareSubscription\Models\CarePlan, $this>
     */
    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\CareSubscription\Models\CarePlan::class, 'care_plan_id');
    }

    /**
     * @return HasMany<WorkOrderTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(WorkOrderTask::class, 'work_order_id');
    }

    /**
     * @return HasMany<WorkEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(WorkEvidence::class, 'work_order_id');
    }
}
