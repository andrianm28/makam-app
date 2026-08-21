<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription\Models;

use App\Domain\CareSubscription\SubscriptionStatus;
use App\Domain\PlotInventory\Models\GravePlot;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `subscriptions` — recurring care agreements linking a
 * grave to a care plan.
 *
 * Status lifecycle: DRAFT -> ACTIVE -> PAUSED -> ENDED | CANCELLED.
 * AC7 gate: pause/cancel require policy configuration.
 */
final class Subscription extends Model
{
    use HasUuids;

    protected $table = 'subscriptions';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'grave_id',
        'care_plan_id',
        'customer_id',
        'status',
        'frequency',
        'price_minor',
        'currency',
        'current_cycle_number',
        'started_at',
        'paused_at',
        'ended_at',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'price_minor' => 'integer',
            'current_cycle_number' => 'integer',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'ended_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function status(): SubscriptionStatus
    {
        return SubscriptionStatus::from($this->status);
    }

    /**
     * @return BelongsTo<GravePlot, $this>
     */
    public function grave(): BelongsTo
    {
        return $this->belongsTo(GravePlot::class, 'grave_id');
    }

    /**
     * @return BelongsTo<CarePlan, $this>
     */
    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(CarePlan::class, 'care_plan_id');
    }

    /**
     * @return HasMany<SubscriptionCycle, $this>
     */
    public function cycles(): HasMany
    {
        return $this->hasMany(SubscriptionCycle::class, 'subscription_id');
    }
}
