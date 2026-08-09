<?php

declare(strict_types=1);

namespace App\Platform\Payment\Models;

use App\Platform\Payment\Exceptions\PaymentSessionCreationUnavailableException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `payment_sessions` — see
 * `2026_08_09_100100_create_payment_sessions_table.php` for the schema and
 * for why the table ships empty.
 *
 * ---------------------------------------------------------------------------
 * This model REFUSES to insert, and that refusal is the feature
 * ---------------------------------------------------------------------------
 * Wave 1b ruling 1b-L3-01: the payment guard is deny-only until the
 * confirmation/reservation, quote, opening-authorization, and
 * merchant/`badan_usaha` upstreams exist, so "there must be no reachable
 * PASS outcome, and therefore no `payment_sessions` row creatable by any
 * caller". The `creating` hook below turns that from a convention into a
 * runtime fact.
 *
 * This is the THIRD and weakest of three layers, deliberately stacked
 * because any one of them could be weakened by a later edit without the
 * others noticing:
 *
 *   1. `App\Platform\Payment\GuardResult` has no factory that produces an
 *      allowed result, so no code can even represent a pass.
 *   2. `CreatePaymentSession` and the `PaymentProvider` contract are not
 *      built — there is no creation path to call.
 *   3. This hook — defence in depth.
 *
 * What layer 3 does NOT stop, stated plainly rather than assumed shut:
 * `PaymentSession::query()->insert([...])`,
 * `DB::table('payment_sessions')->insert(...)`, raw SQL, or any process with
 * direct database credentials. Eloquent model events never see those. Layers
 * 1 and 2 are what actually make a session uncreatable from application
 * code.
 *
 * Removing this hook belongs to the task that implements a real pass path,
 * together with a guard that can genuinely evaluate all six conditions —
 * never on its own.
 */
final class PaymentSession extends Model
{
    use HasUuids;

    protected $table = 'payment_sessions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_intent_id',
        'provider',
        'provider_payment_id',
        'payment_link_url',
        'amount_minor',
        'currency',
        'merchant_ref',
        'badan_usaha_ref',
        'state',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Integer minor units, never float — Wave 0 ruling 0c. This is
            // the value a webhook amount is compared against (AC6), so an
            // inexact type here would be an inexact security check.
            'amount_minor' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $session): void {
            throw PaymentSessionCreationUnavailableException::becauseGuardIsDenyOnly();
        });
    }

    /**
     * @return BelongsTo<PaymentIntent, $this>
     */
    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }
}
