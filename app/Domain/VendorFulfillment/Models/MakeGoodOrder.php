<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Models;

use App\Domain\VendorFulfillment\MakeGoodStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `make_good_orders` — replacement work orders issued
 * when the original service failed or was unsatisfactory.
 */
final class MakeGoodOrder extends Model
{
    use HasUuids;

    protected $table = 'make_good_orders';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'original_work_order_id',
        'replacement_work_order_id',
        'original_cycle_id',
        'status',
        'notes',
    ];

    public function status(): MakeGoodStatus
    {
        return MakeGoodStatus::from($this->status);
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function replacementWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'replacement_work_order_id');
    }
}
