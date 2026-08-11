<?php

declare(strict_types=1);

namespace App\Platform\Payment;

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
 * against this repository: exactly ONE of the six conditions has an
 * authoritative upstream record today.
 *
 * | # | Condition                              | Upstream                          |
 * |---|----------------------------------------|-----------------------------------|
 * | 1 | product gate / server-resolved mode    | REAL — `ModeResolver::paymentMode()`, gate `G-PAY-01` |
 * | 2 | confirmation valid OR reservation active | absent — no `Confirmation`, no `PlotReservation` |
 * | 3 | quote accepted and unexpired           | absent — no persisted `Quote`     |
 * | 4 | authorized opening                     | absent — no opening-authorization API; `ActorContext` exposes no roles/scopes |
 * | 5 | amount == quote total                  | absent — depends on 3             |
 * | 6 | merchant + `badan_usaha` bound         | absent — no merchant/`badan_usaha` model |
 *
 * Those five records are owned by
 * `.kiro/specs/booking-and-order-orchestration/`, whose tasks are unchecked
 * and whose build `docs/planning/sprint-plan.md` schedules for Sprint 7.
 *
 * So conditions 2-6 each return an explicit `UnavailableUpstream` DENIAL
 * naming the record that is missing. That is a refusal, never a bypass. The
 * alternative — stubbing "confirmation valid / quote accepted / opening
 * authorized" to true — would construct exactly what `AGENTS.md` §Domain and
 * financial invariants forbids ("Never create payment before valid
 * confirmation/reservation, accepted quote, and authorized opening") and
 * would ship a guard whose passing path had never once been exercised
 * against a real record. Fail-closed is the only safe shape while the
 * upstream is absent: a guard that cannot pass cannot create a payment, so
 * it is safe to merge ahead of the orchestration spec.
 *
 * There is consequently NO pass path and no `CreatePaymentSession` — see
 * `GuardResult` (no allowed factory) and `Models\PaymentSession` (refuses to
 * insert). Tasks 3-8 sit downstream of a CREATED session and hit this same
 * wall; the ruling's instruction when they do is to escalate, not to widen
 * scope or stub the upstream.
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
    public function __construct(
        private ModeResolver $modes,
        private ActorContextResolver $actors,
        private CorrelationContext $correlation,
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
     * @param  Money  $requestedAmount  What the caller wants to charge, in
     *                                  integer minor units. Typed as `Money` so no float can enter the
     *                                  money path (Wave 0 ruling 0c); `Money` itself is owned by the
     *                                  financial-ledger lane and consumed here read-only.
     * @return GuardResult always a denial — see the class doc block.
     */
    public function __invoke(Money $requestedAmount): GuardResult
    {
        $mode = $this->modes->paymentMode();
        $actor = $this->actors->resolve();

        $denials = [];

        foreach (GuardCondition::inEvaluationOrder() as $condition) {
            $denial = $this->evaluate($condition, $mode);

            if ($denial !== null) {
                $denials[] = $denial;
            }
        }

        // `GuardResult::denied()` throws on an empty list. That is the
        // backstop, not an expected branch: conditions 2-6 deny
        // unconditionally, so `$denials` cannot be empty while the upstream
        // records are absent. If a future edit ever makes it empty, this
        // fails loudly instead of silently returning something a caller
        // could read as a pass.
        $result = GuardResult::denied($denials);

        $this->record($result, $requestedAmount, $mode, $actor);

        return $result;
    }

    /**
     * One condition. Returns `null` when the condition HOLDS — which today
     * only ever happens for condition 1 with `G-PAY-01` open.
     */
    private function evaluate(GuardCondition $condition, PaymentMode $mode): ?ConditionDenial
    {
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

            GuardCondition::ConfirmationOrReservation => $this->unavailable(
                $condition,
                'Confirmation|PlotReservation',
                'Payment cannot be started because the booking confirmation or plot reservation is not available.',
            ),

            GuardCondition::QuoteAcceptedAndUnexpired => $this->unavailable(
                $condition,
                'Quote',
                'Payment cannot be started because an accepted, unexpired quote is not available.',
            ),

            GuardCondition::AuthorizedOpening => $this->unavailable(
                $condition,
                'AuthorizePaymentOpening',
                'Payment cannot be started because authorization to open payment is not available.',
            ),

            // Depends on condition 3's record: with no quote total to
            // compare against, the comparison cannot be performed at all.
            // Reporting the requested amount as "matching" nothing would be
            // the single most dangerous stub on this whole path.
            GuardCondition::AmountMatchesQuoteTotal => $this->unavailable(
                $condition,
                'Quote',
                'Payment cannot be started because there is no quote total to check the amount against.',
            ),

            GuardCondition::MerchantAndBadanUsahaBound => $this->unavailable(
                $condition,
                'Merchant|BadanUsaha',
                'Payment cannot be started because the merchant and business-entity binding is not available.',
            ),
        };
    }

    private function unavailable(
        GuardCondition $condition,
        string $missingUpstream,
        string $publicMessage,
    ): ConditionDenial {
        return new ConditionDenial(
            condition: $condition,
            reason: GuardDenialReason::UnavailableUpstream,
            publicMessage: $publicMessage,
            missingUpstream: $missingUpstream,
        );
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
