<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `vendor_availability` — see the migration
 * (`2026_08_12_100040_create_vendor_availability_table.php`) for full schema
 * reasoning, including why a blocked day can never advertise capacity.
 */
final class VendorAvailability extends Model
{
    protected $table = 'vendor_availability';

    /** @var list<string> */
    protected $fillable = [
        'vendor_id',
        'available_date',
        'capacity',
        'is_blocked',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'available_date' => 'date',
            'capacity' => 'integer',
            'is_blocked' => 'boolean',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
