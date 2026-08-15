<?php

declare(strict_types=1);

namespace App\Platform\Payment\Models;

use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
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
 * This model REFUSES to insert while the payment gate is closed, and that
 * refusal is the feature
 * ---------------------------------------------------------------------------
 * Wave 1b ruling 1b-L3-01 made the payment guard deny-only until the
 * confirmation/reservation, quote, opening-authorization, and
 * merchant/`badan_usaha` upstreams exist, so "there must be no reachable
 * PASS outcome, and therefore no `payment_sessions` row creatable by any
 * caller". The online-payment gateway task lands the real pass path: when
 * `G-PAY-01` is open (dev), creation is allowed; while it is closed, the
 * `creating` hook below turns the ruling from a convention into a runtime
 * fact.
 *
 * The refusal is the THIRD of three layers, deliberately stacked because
 * any one of them could be weakened by a later edit without the others
 * noticing:
 *
 *   1. `App\Platform\Payment\GuardPaymentSession` evaluates every one of
 *      its six conditions; with the gate closed it has no reachable pass
 *      outcome.
 *   2. Session creation goes through the guard-composed session-opening
 *      path only — there is no unguarded creation path to call.
 *   3. This hook — defence in depth, now gate-conditional.
 *
 * What layer 3 does NOT stop, stated plainly rather than assumed shut:
 * `PaymentSession::query()->insert([...])`,
 * `DB::table('payment_sessions')->insert(...)`, raw SQL, or any process with
 * direct database credentials. Eloquent model events never see those. Layers
 * 1 and 2 are what actually make a session uncreatable from application
 * code while the gate is closed.
 *
 * Removing this hook belongs to the task that implements a real pass path,
 * together with a guard that can genuinely evaluate all six conditions —
 * never on its own. Widening it (the gate check below) is exactly that
 * task, and it applies only while `G-PAY-01` is open; production keeps the
 * gate closed and this hook refuses there.
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
            if (app(ModeResolver::class)->paymentMode() !== PaymentMode::Online) {
                throw PaymentSessionCreationUnavailableException::becauseGuardIsDenyOnly();
            }
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
