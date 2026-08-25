<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SubscriptionInvoice extends Model
{
    use HasUuids;

    protected $table = 'subscription_invoices';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subscription_cycle_id',
        'payment_session_id',
        'amount_minor',
        'currency',
        'status',
        'issued_at',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
