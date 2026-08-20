<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `subscription_cycles` — one row per billing period.
 * Unique on subscription_id + cycle_start + cycle_end (idempotency key).
 */
final class SubscriptionCycle extends Model
{
    use HasUuids;

    protected $table = 'subscription_cycles';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subscription_id',
        'cycle_start',
        'cycle_end',
        'status',
        'invoice_id',
        'work_order_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cycle_start' => 'date',
            'cycle_end' => 'date',
        ];
    }

    /**
     * @return BelongsTo<SubscriptionInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'invoice_id');
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
