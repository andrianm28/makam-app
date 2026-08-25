<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for `service_acceptances` — customer acceptance of a
 * completed work order, optionally including a satisfaction rating.
 */
final class ServiceAcceptance extends Model
{
    use HasUuids;

    protected $table = 'service_acceptances';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'work_order_id',
        'customer_id',
        'accepted_at',
        'rating',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'accepted_at' => 'immutable_datetime',
            'rating' => 'integer',
        ];
    }
}
