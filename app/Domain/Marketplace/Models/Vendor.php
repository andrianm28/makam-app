<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Vendor extends Model
{
    use HasUuids;

    protected $table = 'vendors';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['name', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<VendorUser, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(VendorUser::class, 'vendor_id');
    }

    /** @return HasMany<VendorListing, $this> */
    public function listings(): HasMany
    {
        return $this->hasMany(VendorListing::class, 'vendor_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
