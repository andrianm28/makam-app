<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use App\Domain\OrderWorkflow\Actions\AuthorizeOrderPaymentOpening;
use App\Domain\OrderWorkflow\Exceptions\OrderPaymentOpeningNotAuthorisedException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\Quotation\Models\Quote;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
use App\Platform\FinancialLedger\Money;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\Payment\Models\PaymentIntent;
use Carbon\CarbonImmutable;

/**
 * AC2's six-condition payment guard — the ONLY path to a payment session,
 * per `.kiro/specs/platform-payment-adapter/design.md` §Payment guard: "All
 * six must hold. The guard is the only path to a payment session; a denial
 * returns an explanatory result, never a silent no-op."
 *
 * ---------------------------------------------------------------------------
 * DENY-ONLY — Wave 1b ruling 1b-L3-01 (approved 10 Aug 2026)
 * ---------------------------------------------------------------------------
 * Read the ruling in `docs/superpowers/plans/2026-08-09-platform-payment-
 * adapter.md` §Task 2 before changing anything here. Its finding, verified
 * against this repository: exactly ONE of the six conditions had an
 * authoritative upstream record at the time of the ruling.
 *
 * | # | Condition                              | Status (post-Task-6)             |
 * |---|----------------------------------------|-----------------------------------|
 * | 1 | product gate / server-resolved mode    | REAL — `ModeResolver::paymentMode()`, gate `G-PAY-01` |
 * | 2 | confirmation valid OR reservation active | REAL — `Order::status` membership |
 * | 3 | quote accepted and unexpired           | REAL — `Quote::currentFor()` + `isAcceptedAndUnexpired()` |
 * | 4 | authorized opening                     | REAL — `AuthorizeOrderPaymentOpening` |
 * | 5 | amount == quote total                  | REAL — integer minor-unit comparison |
 * | 6 | merchant + `badan_usaha` bound        | UNAVAILABLE — `FIN-DEC-01` TBD   |
 *
 * Conditions 2-5 are REAL as of Task 6 (2026-08-12). Each denies with a
 * genuine `DomainDenied` when its record is missing or unsatisfied.
 * Condition 6 alone retains `UnavailableUpstream` because the merchant/
 * `badan_usaha` binding cannot exist while financial decision `FIN-DEC-01`
 * is `TBD`.
 *
 * There is consequently NO pass path and no `CreatePaymentSession` — see
 * `GuardResult` (no allowed factory) and `Models\PaymentSession` (refuses to
 * insert). The ruling's instruction when downstream tasks hit this wall is to
 * escalate, not to widen scope or stub the upstream.
 *
 * ---------------------------------------------------------------------------
 * All six conditions are evaluated, not short-circuited at the first failure
 * ---------------------------------------------------------------------------
 * design.md requires all six to hold, so no information is lost by
 * continuing past a failure — and a great deal is gained. With condition 2
 * unconditionally denied today, a fail-fast guard would make conditions 3-6
 * permanently unreachable and untestable, and design.md §Observability's
 * "guard denial reasons" would only ever show one. `GuardResult` reports the
 * first failure in design.md's fixed order as the primary denial and carries
 * the whole list.
 *
 * ---------------------------------------------------------------------------
 * The payment mode is server-resolved at evaluation time (AC1)
 * ---------------------------------------------------------------------------
 * `ModeResolver::paymentMode()` is called HERE, inside the evaluation. No
 * parameter of this class — constructor or `__invoke` — accepts a
 * `PaymentMode`, a mode name, or a gate state, so no request input, no
 * caller, and no config value can select it. `GuardPaymentSessionTest`
 * asserts that by reflection, not by convention.
 */
final readonly class GuardPaymentSession
{
    /**
     * Status values that indicate an active confirmation (quotation sent) or
     * reservation (plot offer accepted): PENAWARAN_TERKIRIM through SELESAI.
     *
     * @var list<string>
     */
    private const array CONFIRMED_STATUSES = [
        'PENAWARAN_TERKIRIM',
        'DISETUJUI_PEMESAN',
        'MENUNGGU_PEMBAYARAN',
        'MENUNGGU_VERIFIKASI_PEMBAYARAN',
        'DIBAYAR',
        'DIPROSES',
        'SELESAI',
    ];

    public function __construct(
        private ModeResolver $modes,
        private ActorContextResolver $actors,
        private CorrelationContext $correlation,
        private AuthorizeOrderPaymentOpening $authorizeOpening,
    ) {}

    /**
     * Evaluate the guard and record the decision.
     *
     * Writes exactly one `payment_intents` row per call (ruling 1b-L3-01
     * Step 3: "Every guard evaluation — pass or deny — writes a
     * `payment_intents` decision record"), and, because every evaluation is
     * a denial, one `PAYMENT_GUARD_DENIED` audit event with
     * `AuditOutcome::Denied`, in the SAME transaction as the intent row
     * (`Audit::wrap`).
     *
     * @param  Order  $order  The order to evaluate payment-opening conditions against.
     * @param  Money  $requestedAmount  What the caller wants to charge, in
     *                                  integer minor units. Typed as `Money` so no float can enter the
     *                                  money path (Wave 0 ruling 0c); `Money` itself is owned by the
     *                                  financial-ledger lane and consumed here read-only.
     * @return GuardResult always a denial — see the class doc block.
     */
    public function __invoke(Order $order, Money $requestedAmount): GuardResult
    {
        $mode = $this->modes->paymentMode();
        $actor = $this->actors->resolve();
        $currentQuote = Quote::currentFor($order);

        $denials = [];

        foreach (GuardCondition::inEvaluationOrder() as $condition) {
            $denial = $this->evaluate($condition, $mode, $order, $actor, $currentQuote, $requestedAmount);

            if ($denial !== null) {
                $denials[] = $denial;
            }
        }

        // `GuardResult::denied()` throws on an empty list. That is the
        // backstop, not an expected branch: condition 6 always denies
        // (`UnavailableUpstream`), so `$denials` cannot be empty. If a future
        // edit ever makes it empty, this fails loudly instead of silently
        // returning something a caller could read as a pass.
        $result = GuardResult::denied($denials);

        $this->record($result, $requestedAmount, $mode, $actor);

        return $result;
    }

    /**
     * One condition. Returns `null` when the condition HOLDS.
     */
    private function evaluate(
        GuardCondition $condition,
        PaymentMode $mode,
        Order $order,
        ActorContext $actor,
        ?Quote $currentQuote,
        Money $requestedAmount,
    ): ?ConditionDenial {
        return match ($condition) {
            // Condition 1 — REAL. `PaymentMode::ManualCoordination` is a
            // genuine, evaluated answer from a genuine source (gate
            // `G-PAY-01`), so it is a `DomainDenied`, never an
            // `UnavailableUpstream`. An operator can make this condition
            // pass by opening the gate with activation evidence; that is
            // precisely what distinguishes it from the five below.
            GuardCondition::ProductGateOpen => $mode === PaymentMode::Online
                ? null
                : new ConditionDenial(
                    condition: $condition,
                    reason: GuardDenialReason::DomainDenied,
                    publicMessage: 'Online payment is not currently available; payment is arranged manually.',
                ),

            // Condition 2 — REAL. The order aggregate IS the confirmation/
            // reservation record: `PENAWARAN_TERKIRIM` is reached only after
            // availability is confirmed, and `DISETUJUI_PEMESAN` only after
            // the customer accepts. A `MASUK`/`MENUNGGU_KETERSEDIAAN` order
            // has no active confirmation/reservation; terminal/rejected states
            // are equally invalid.
            GuardCondition::ConfirmationOrReservation => $this->conditionTwo($condition, $order),

            // Condition 3 — REAL. Requires a current accepted, unexpired quote.
            GuardCondition::QuoteAcceptedAndUnexpired => $this->conditionThree($condition, $currentQuote),

            // Condition 4 — REAL. Requires the actor to hold an ADMIN role
            // AND an active ORDER-scope grant.
            GuardCondition::AuthorizedOpening => $this->conditionFour($condition, $order, $actor),

            // Condition 5 — REAL. Requires the requested amount to match the
            // current quote's total in integer minor units, and that total
            // must be strictly positive.
            GuardCondition::AmountMatchesQuoteTotal => $this->conditionFive($condition, $currentQuote, $requestedAmount),

            // Condition 6 — UNAVAILABLE. The merchant/`badan_usaha` binding
            // cannot be built while financial decision `FIN-DEC-01` is `TBD`.
            GuardCondition::MerchantAndBadanUsahaBound => new ConditionDenial(
                condition: $condition,
                reason: GuardDenialReason::UnavailableUpstream,
                publicMessage: 'Payment cannot be started because the merchant and business-entity binding is not available (FIN-DEC-01 pending).',
                missingUpstream: 'Merchant|BadanUsaha (FIN-DEC-01)',
            ),
        };
    }

    private function conditionTwo(GuardCondition $condition, Order $order): ?ConditionDenial
    {
        if (in_array($order->status, self::CONFIRMED_STATUSES, true)) {
            return null;
        }

        return new ConditionDenial(
            condition: $condition,
            reason: GuardDenialReason::DomainDenied,
            publicMessage: 'Payment cannot be started because the booking confirmation or plot reservation is not available.',
        );
    }

    private function conditionThree(GuardCondition $condition, ?Quote $currentQuote): ?ConditionDenial
    {
        if ($currentQuote === null) {
            return new ConditionDenial(
                condition: $condition,
                reason: GuardDenialReason::DomainDenied,
                publicMessage: 'Payment cannot be started because an accepted, unexpired quote is not available.',
            );
        }

        if (! $currentQuote->isAcceptedAndUnexpired(CarbonImmutable::now())) {
            return new ConditionDenial(
                condition: $condition,
                reason: GuardDenialReason::DomainDenied,
                publicMessage: 'Payment cannot be started because an accepted, unexpired quote is not available.',
            );
        }

        return null;
    }

    private function conditionFour(GuardCondition $condition, Order $order, ActorContext $actor): ?ConditionDenial
    {
        try {
            $this->authorizeOpening->__invoke($actor, $order);
        } catch (OrderPaymentOpeningNotAuthorisedException) {
            return new ConditionDenial(
                condition: $condition,
                reason: GuardDenialReason::DomainDenied,
                publicMessage: 'Payment cannot be started because authorization to open payment is not available.',
            );
        }

        return null;
    }

    private function conditionFive(
        GuardCondition $condition,
        ?Quote $currentQuote,
        Money $requestedAmount,
    ): ?ConditionDenial {
        if ($currentQuote === null) {
            return new ConditionDenial(
                condition: $condition,
                reason: GuardDenialReason::DomainDenied,
                publicMessage: 'Payment cannot be started because there is no quote total to check the amount against.',
            );
        }

        if (! $currentQuote->totalMinor()->isPositive()) {
            return new ConditionDenial(
                condition: $condition,
                reason: GuardDenialReason::DomainDenied,
                publicMessage: 'Payment cannot be started because there is no quote total to check the amount against.',
            );
        }

        if ($currentQuote->totalMinor()->toMinorInt() !== $requestedAmount->toMinorInt()) {
            return new ConditionDenial(
                condition: $condition,
                reason: GuardDenialReason::DomainDenied,
                publicMessage: 'Payment cannot be started because the quoted amount does not match the requested amount.',
            );
        }

        return null;
    }

    /**
     * Persist the decision record and its audit event together.
     */
    private function record(
        GuardResult $result,
        Money $requestedAmount,
        PaymentMode $mode,
        ActorContext $actor,
    ): void {
        // `ActorContext::$roles` is always empty today (that class documents
        // why: no local roles table any spec has authorized), so a role is
        // derived from authentication state exactly as
        // `App\Domain\Booking\Actions\StartBookingDraft` already does. An
        // empty roles list is never read as "no roles required".
        $actorRole = $actor->isAuthenticated() ? 'customer' : 'guest';

        $correlationId = $this->correlation->current();

        Audit::wrap(
            mutation: fn (): PaymentIntent => PaymentIntent::create([
                'requested_amount_minor' => $requestedAmount->toMinorInt(),
                'currency' => (string) config('money.currency'),
                'payment_mode' => $mode->value,
                'decision' => PaymentIntentDecision::Denied->value,
                'denied_condition' => $result->condition()->value,
                'denial_reason' => $result->reason()->value,
                'missing_upstream' => $result->missingUpstream(),
                'denied_conditions' => $result->deniedConditionValues(),
                'public_message' => $result->publicMessage(),
                'actor_ref' => $actor->identityReference,
                'actor_role' => $actorRole,
                'correlation_id' => $correlationId === null ? null : (string) $correlationId,
                'evaluated_at' => CarbonImmutable::now(),
            ]),
            action: PaymentAuditActions::GUARD_DENIED,
            subject: fn (PaymentIntent $intent): AuditSubject => new AuditSubject('payment_intent', $intent->id),
            outcome: AuditOutcome::Denied,
            actorRef: $actor->identityReference,
            actorRole: $actorRole,
            // `Api`, not `Panel`: the guard is reached from the customer
            // payment request path, never from an admin panel action. If an
            // admin-initiated payment path is ever built it passes its own
            // source rather than reusing this one silently.
            source: AuditSource::Api,
            correlationId: $correlationId === null ? null : (string) $correlationId,
            // `note` is an EXISTING `MetadataAllowlist::ALLOWED_KEYS` key —
            // this lane adds none. The value is composed of closed-list
            // values and a record NAME only: no amount, no identifier, no
            // actor detail, nothing restricted (`AGENTS.md` §Observability).
            metadata: [
                'note' => sprintf(
                    'condition=%s; reason=%s; missing_upstream=%s; failed=%d/%d',
                    $result->condition()->value,
                    $result->reason()->value,
                    $result->missingUpstream() ?? 'none',
                    count($result->denials()),
                    count(GuardCondition::ORDER),
                ),
            ],
        );
    }
}
