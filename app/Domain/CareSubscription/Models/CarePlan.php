<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription\Models;

use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\CarePlanStatus;
use App\Domain\Marketplace\Models\Vendor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `care_plans` — the master-data catalogue for recurring
 * grave maintenance plans.
 *
 * Each plan defines a frequency, price (in minor units), and optional checklist
 * template. Subscriptions reference a care plan; work orders derive from paid cycles.
 */
final class CarePlan extends Model
{
    use HasUuids;

    protected $table = 'care_plans';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'name',
        'description',
        'product_code',
        'frequency',
        'price_minor',
        'currency',
        'vendor_id',
        'checklist_template',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'checklist_template' => 'array',
        ];
    }

    public function status(): CarePlanStatus
    {
        return CarePlanStatus::from($this->status);
    }

    public function frequency(): CarePlanFrequency
    {
        return CarePlanFrequency::from($this->frequency);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'care_plan_id');
    }
}
