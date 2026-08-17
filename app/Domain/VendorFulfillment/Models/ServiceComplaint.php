<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Models;

use App\Domain\VendorFulfillment\ComplaintStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'immutable_datetime',
            'filed_at' => 'immutable_datetime',
        ];
    }

    public function status(): ComplaintStatus
    {
        return ComplaintStatus::from($this->status);
    }
}
