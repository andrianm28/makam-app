<?php

declare(strict_types=1);

namespace App\Platform\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use App\Platform\Payment\PaymentReversalType;
use App\Platform\Payment\ReversalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `RequireRecentAuthentication`'s THIRD real attachment anywhere in this
 * repo — after `App\Http\Controllers\Admin\DisableMfaController` (first)
 * and `VerifyManualPaymentController` (second). Follows
 * `VerifyManualPaymentController`'s exact shape: `ReauthenticationService::
 * satisfy()` first, validate input, delegate to the write API
 * (`ReversalService` here, `VerifyManualPayment` there), redirect to
 * `filament.admin.pages.dashboard` — no admin UI screen exists for
 * reversals either, same honest "nothing to bounce back to yet" posture.
 *
 * ---------------------------------------------------------------------------
 * One parameterized route, not two separate ones — a judgement call, flagged
 * per the brief's own request
 * ---------------------------------------------------------------------------
 * The brief's item 5 offers both shapes ("`POST
 * /admin/payments/reversals/refund` and `POST
 * /admin/payments/reversals/chargeback` (or a single parameterized route if
 * you prefer — your call, flag which you chose)"). This picks the single
 * parameterized route, `POST /admin/payments/reversals/{reversalType}`
 * (`reversalType` constrained to `refund|chargeback` in `routes/web.php`),
 * for two reasons: (1) the brief's own literal middleware example for this
 * task uses one shared re-authentication reason for both types
 * (`RequireRecentAuthentication::class.':payment_reversal,...'` — not
 * `payment_reversal_refund`/`payment_reversal_chargeback`), which already
 * implies one route/one challenge rather than two; (2) `ReversalService`
 * itself is described as "a thin dispatcher (routes to the right Action by
 * `PaymentReversalType`)" — a single controller delegating to that one
 * dispatcher mirrors that shape directly, instead of two near-identical
 * controllers each hardcoding one type.
 */
final class RecordPaymentReversalController extends Controller
{
    /**
     * Must match the `$reason` this controller's own route passes to
     * `RequireRecentAuthentication::class.':...'` in `routes/web.php` — see
     * that middleware's own doc block for why the two are not otherwise
     * linked by a shared constant. Distinct from the mandatory audit
     * `reason` the request body supplies below, which explains WHY this
     * specific reversal was recorded.
     */
    private const string REASON = 'payment_reversal';

    public function __invoke(Request $request, string $reversalType): RedirectResponse
    {
        $type = match ($reversalType) {
            'refund' => PaymentReversalType::Refund,
            'chargeback' => PaymentReversalType::Chargeback,
            // Unreachable via the route's own `->where('reversalType', ...)`
            // constraint (see `routes/web.php`) — defensive only, the same
            // "honest failure, not a silent bypass" posture the rest of this
            // lane's controllers already use for out-of-band input.
            default => throw new NotFoundHttpException("Unknown payment reversal type [{$reversalType}]."),
        };

        $actorContext = app(ActorContext::class);

        app(ReauthenticationService::class)->satisfy(
            actorRef: $actorContext->identityReference,
            actorRole: 'authenticated_actor',
            reason: self::REASON,
            source: AuditSource::Panel,
        );

        $validated = $request->validate([
            // max:191 matches `$table->string('reference', 191)` in
            // `2026_08_11_100010_create_payment_reversals_table.php` — an
            // over-length reference is refused with a clean 422 here rather
            // than ever reaching the database, where a PostgreSQL
            // "value too long for varchar(191)" error would otherwise be
            // the raw failure surfaced (fix round 1, MINOR-2).
            'reference' => ['required', 'string', 'max:191'],
            'amount_minor' => ['nullable', 'integer'],
            'reason' => ['required', 'string'],
        ]);

        app(ReversalService::class)->record(
            type: $type,
            reference: $validated['reference'],
            amountMinor: array_key_exists('amount_minor', $validated) ? (int) $validated['amount_minor'] : null,
            reason: $validated['reason'],
            actorRef: $actorContext->identityReference,
            actorRole: 'authenticated_actor',
            source: AuditSource::Panel,
        );

        return redirect()->route('filament.admin.pages.dashboard');
    }
}
