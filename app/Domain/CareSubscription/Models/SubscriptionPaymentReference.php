<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SubscriptionPaymentReference extends Model
{
    use HasUuids;

    protected $table = 'subscription_payment_references';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subscription_id',
        'provider_token',
        'provider',
        'last_four',
        'status',
    ];
}
