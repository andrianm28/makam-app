<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership metadata only. See the migration's doc block: authorization is
 * decided by `scope_assignments`, never by this table.
 */
final class VendorUser extends Model
{
    protected $table = 'vendor_users';

    /** @var list<string> */
    protected $fillable = ['vendor_id', 'actor_identifier', 'revoked_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
