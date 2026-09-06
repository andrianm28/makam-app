<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Models;

use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `service_complaints` — customer-filed complaints
 * about a work order. Lifecycle: OPEN → INVESTIGATING → RESOLVED | DISMISSED.
 */
final class ServiceComplaint extends Model
{
    use HasUuids;

    protected $table = 'service_complaints';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'work_order_id',
        'customer_id',
        'complaint_text',
        'status',
        'resolution_notes',
        'resolved_at',
        'filed_at',
        'make_good_order_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'resolved_at' => 'immutable_datetime',
            'filed_at' => 'immutable_datetime',
        ];
    }

    public function status(): ComplaintStatus
    {
        return ComplaintStatus::from($this->status);
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return BelongsTo<MakeGoodOrder, $this> */
    public function makeGood(): BelongsTo
    {
        return $this->belongsTo(MakeGoodOrder::class, 'make_good_order_id');
    }
}
