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
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;
use Carbon\CarbonImmutable;

/**
 * AC2's six-condition payment guard — the ONLY path to a payment session,
 * per `.kiro/specs/platform-payment-adapter/design.md` §Payment guard: "All
 * six must hold. The guard is the only path to a payment session; a denial
 * returns an explanatory result, never a silent no-op."
 *
 * ---------------------------------------------------------------------------
 * DENY-ONLY is over; the six conditions are all real (Task 6 + the gateway)
 * ---------------------------------------------------------------------------
 * Wave 1b ruling 1b-L3-01 (approved 10 Aug 2026) shipped this guard deny-only
 * because exactly ONE of the six conditions had an authoritative upstream
 * record. The ruling's instruction for downstream tasks meeting the wall was
 * to escalate — and the online-payment gateway design
 * (`docs/superpowers/specs/2026-08-14-online-payment-integration-design.md`,
 * human-approved) is that escalation's resolution for condition 6:
 *
 * | # | Condition                              | Status (post-gateway)                    |
 * |---|----------------------------------------|------------------------------------------|
 * | 1 | product gate / server-resolved mode    | REAL — `ModeResolver::paymentMode()`, gate `G-PAY-01` |
 * | 2 | confirmation valid OR reservation active | REAL — `Order::status` membership |
 * | 3 | quote accepted and unexpired           | REAL — `Quote::currentFor()` + `isAcceptedAndUnexpired()` |
 * | 4 | authorized opening                     | REAL — `AuthorizeOrderPaymentOpening` |
 * | 5 | amount == quote total                  | REAL — integer minor-unit comparison |
 * | 6 | merchant + `badan_usaha` bound        | REAL — `config('payment.merchant_ref')` + `config('payment.badan_usaha_ref')` (FIN-DEC-01 provisioning, empty → denies) |
 *
 * Condition 6 is now config-evaluated: the merchant/`badan_usaha` binding is
 * the FIN-DEC-01 provisioning channel the design names ("condition 6's
 * merchant binding becomes real via config", "merchant ref from config").
 * When both config values are non-blank the binding exists and the condition
 * holds; when either is blank the binding does not exist and the condition
 * denies with `UnavailableUpstream` exactly as it did under the ruling.
 *
 * The deny-only posture still holds for production: `G-PAY-01` stays closed
 * there, so condition 1 denies before anything else can pass. A pass requires
 * ALL six to hold — the gate open AND the merchant bound AND a confirmed
 * order AND an accepted unexpired quote AND an authorized opener AND a
 * matching amount.
 *
 * ---------------------------------------------------------------------------
 * All six conditions are evaluated, not short-circuited at the first failure
 * ---------------------------------------------------------------------------
 * design.md requires all six to hold, so no information is lost by
 * continuing past a failure — and a great deal is gained. `GuardResult`
 * reports the first failure in design.md's fixed order as the primary denial
 * and carries the whole list.
 *
 * ---------------------------------------------------------------------------
 * The payment mode is server-resolved at evaluation time (AC1)
 * ---------------------------------------------------------------------------
 * `ModeResolver::paymentMode()` is called HERE, inside the evaluation. No
 * parameter of this class — constructor or `__invoke` — accepts a
 * `PaymentMode`, a mode name, or a gate state, so no request input, no
 * caller, and no config value can select it. `GuardPaymentSessionTest`
 * asserts that by reflection, not by convention.
 *
 * ---------------------------------------------------------------------------
 * What an ALLOWED evaluation records — nothing, deliberately
 * ---------------------------------------------------------------------------
 * Every DENIED evaluation writes one `payment_intents` row plus its
 * `PAYMENT_GUARD_DENIED` audit event, in one transaction (ruling 1b-L3-01
 * Step 3's accounting, unchanged). An ALLOWED evaluation writes nothing
 * here: the decision record for an allowed opening is written by
 * `Actions\OpenPaymentSession` — the guard's only caller — atomically with
 * the session it authorizes (intent + session + `PAYMENT_SESSION_OPENED`
 * audit in one transaction), so an opening can never exist without its
 * Allowed intent and vice versa. If the provider call fails before that
 * write, no decision record exists because the authorization was never used
 * — the exception IS the record. Moving the Allowed-intent write back into
 * this class would split the pair across two transactions.
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
     * A DENIED evaluation writes exactly one `payment_intents` row
     * (ruling 1b-L3-01 Step 3: "Every guard evaluation — pass or deny —
     * writes a `payment_intents` decision record") and one
     * `PAYMENT_GUARD_DENIED` audit event with `AuditOutcome::Denied`, in the
     * SAME transaction as the intent row (`Audit::wrap`). An ALLOWED
     * evaluation writes nothing here — see the class doc block: its decision
     * record is written by `Actions\OpenPaymentSession` atomically with the
     * session it authorizes.
     *
     * @param  Order  $order  The order to evaluate payment-opening conditions against.
     * @param  Money  $requestedAmount  What the caller wants to charge, in
     *                                  integer minor units. Typed as `Money` so no float can enter the
     *                                  money path (Wave 0 ruling 0c); `Money` itself is owned by the
     *                                  financial-ledger lane and consumed here read-only.
     * @return GuardResult allowed() when all six conditions hold, otherwise a
     *                     denial — see the class doc block.
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

        // All six held. Nothing is recorded here — see the class doc block
        // for why the allowed decision record belongs to the session-opening
        // caller instead.
        if ($denials === []) {
            return GuardResult::allowed();
        }

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

            // Condition 4 — REAL. Requires THIS ORDER to hold an active
            // ORDER-scope grant held by an actor who currently holds ADMIN —
            // a property of the order (an admin authorized it), not of the
            // current caller. Fixed 18 Aug 2026: previously required the
            // CALLING actor to hold that grant, which made online payment
            // structurally unreachable from the public booking wizard for
            // every real (guest) customer — see
            // `AuthorizeOrderPaymentOpening`'s class doc block.
            GuardCondition::AuthorizedOpening => $this->conditionFour($condition, $order, $actor),

            // Condition 5 — REAL. Requires the requested amount to match the
            // current quote's total in integer minor units, and that total
            // must be strictly positive.
            GuardCondition::AmountMatchesQuoteTotal => $this->conditionFive($condition, $currentQuote, $requestedAmount),

            // Condition 6 — REAL. The merchant/`badan_usaha` binding is the
            // FIN-DEC-01 provisioning channel the approved gateway design
            // makes real via config: when both refs are configured the
            // binding exists and the condition holds; when either is blank
            // it does not, and the denial keeps the ruling's
            // `UnavailableUpstream` shape (`FIN-DEC-01` pending). Production
            // never reaches this condition's pass side anyway — condition 1
            // denies while `G-PAY-01` is closed.
            //
            // The refs resolve through `SettingsService`'s config → env →
            // `site_settings` → default precedence (the P2
            // `admin-data-management` lane): an operator-managed
            // `payment_merchant_ref`/`payment_badan_usaha_ref` row now
            // participates without any environment change, and the config
            // default keeps the pre-existing env behaviour byte-identical
            // while no DB row exists.
            GuardCondition::MerchantAndBadanUsahaBound => $this->conditionSix($condition),
        };
    }

    private function conditionSix(GuardCondition $condition): ?ConditionDenial
    {
        $merchantRef = trim((string) app(SettingsService::class)
            ->setting(SiteSetting::KEY_PAYMENT_MERCHANT_REF, (string) config('payment.merchant_ref', '')));
        $badanUsahaRef = trim((string) app(SettingsService::class)
            ->setting(SiteSetting::KEY_PAYMENT_BADAN_USAHA_REF, (string) config('payment.badan_usaha_ref', '')));

        if ($merchantRef !== '' && $badanUsahaRef !== '') {
            return null;
        }

        return new ConditionDenial(
            condition: $condition,
            reason: GuardDenialReason::UnavailableUpstream,
            publicMessage: 'Payment cannot be started because the merchant and business-entity binding is not available (FIN-DEC-01 pending).',
            missingUpstream: 'Merchant|BadanUsaha (FIN-DEC-01)',
        );
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
     * Persist a DENIED evaluation's decision record and its audit event
     * together. Never called for an allowed evaluation — `__invoke` returns
     * `GuardResult::allowed()` before reaching this, and the allowed
     * decision record is the session-opening caller's to write (class doc
     * block).
     */
    private function record(
        GuardResult $result,
        Money $requestedAmount,
        PaymentMode $mode,
        ActorContext $actor,
    ): void {
        // The role label is derived from authentication state, not from
        // `ActorContext::$roles`: a guard evaluation happens at the moment a
        // payment attempt is made, and the label's only job is to
        // distinguish a signed-in attempt from a guest one — exactly as
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
