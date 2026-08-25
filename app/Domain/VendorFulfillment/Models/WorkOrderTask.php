<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Models;

use App\Domain\VendorFulfillment\WorkOrderTaskStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `work_order_tasks` — individual checklist items
 * for a work order, expanded from `care_plans.checklist_template`.
 */
final class WorkOrderTask extends Model
{
    use HasUuids;

    protected $table = 'work_order_tasks';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'work_order_id',
        'name',
        'required_evidence',
        'sort_order',
        'status',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required_evidence' => 'boolean',
            'sort_order' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function status(): WorkOrderTaskStatus
    {
        return WorkOrderTaskStatus::from($this->status);
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }
}
