<?php

declare(strict_types=1);

namespace App\Platform\Payment\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FinancialLedger\Money;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\Payment\Checkout\Contracts\PaymentCheckoutClient;
use App\Platform\Payment\Checkout\CreatePaymentRequest;
use App\Platform\Payment\Exceptions\PaymentSessionMerchantMismatchException;
use App\Platform\Payment\Exceptions\PaymentSessionOpeningDeniedException;
use App\Platform\Payment\Exceptions\PaymentSessionOrderNotFoundException;
use App\Platform\Payment\Exceptions\PaymentSessionOrderTypeNotSupportedException;
use App\Platform\Payment\GuardPaymentSession;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\OrderType;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentIntentDecision;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;

/**
 * Task 4's `OpenPaymentSession` — the ONLY production path that creates a
 * `payment_sessions` row, per `.kiro/specs/platform-payment-adapter/design.md`
 * §Payment guard: "The guard is the only path to a payment session."
 *
 * Flow, in the plan's order:
 *
 *   1. **Evaluate the six-condition guard** (`GuardPaymentSession`, booking
 *      orders only — see `OrderType` for why marketplace is deferred). A
 *      denial throws `PaymentSessionOpeningDeniedException`; the guard has
 *      already recorded the `payment_intents` denial row and its
 *      `PAYMENT_GUARD_DENIED` audit event in its own transaction, exactly as
 *      it always has. Nothing else happens — no provider call, no session.
 *   2. **Verify the merchant claim.** `command.merchantRef` must equal the
 *      config-bound merchant (`config('payment.merchant_ref')`) or the
 *      opening fails closed — a session must never bind a merchant this
 *      deployment does not serve (AC13, mirrored by `WebhookValidator`'s
 *      `REJECTED_MERCHANT` at webhook time).
 *   3. **Call the provider** (`PaymentCheckoutClient::createPayment()`) for
 *      the hosted-checkout link. This is deliberately OUTSIDE any database
 *      transaction: it is an HTTP call, and a long-held transaction would be
 *      a row-lock hazard. A provider failure throws before anything is
 *      written — an allowed evaluation that aborts here records nothing, and
 *      the exception IS the record.
 *   4. **Write the opening unit atomically** — the `PaymentIntent` with
 *      `PaymentIntentDecision::Allowed` (all denial columns null), the
 *      `PaymentSession` at `SessionState::AwaitingPayment` carrying the
 *      provider's `payment_id`, the hosted `payment_link_url`, the
 *      guard-verified `amount_minor`, and the merchant/badan-usaha binding —
 *      plus the `PAYMENT_SESSION_OPENED` audit event, all inside one
 *      `Audit::wrap()` transaction (AC4: no committed state change without
 *      its audit record).
 *
 * ---------------------------------------------------------------------------
 * What the guard does and does not write on an ALLOWED evaluation
 * ---------------------------------------------------------------------------
 * A denied evaluation keeps writing its `payment_intents` row through
 * `GuardPaymentSession::record()` (unchanged). An ALLOWED evaluation writes
 * NOTHING in the guard: the decision record for an allowed opening is this
 * action's to write, atomically with the session it authorizes, so an
 * opening can never exist without its Allowed intent and vice versa. The
 * guard's doc block states that division of labour; do not "simplify" by
 * moving the Allowed-intent write back into the guard, because the pair
 * would then be committed in two transactions.
 *
 * ---------------------------------------------------------------------------
 * Actor role label — deliberately the guard's derivation, not a role lookup
 * ---------------------------------------------------------------------------
 * The audit's `actor_role` uses the same `customer`/`guest` derivation
 * `GuardPaymentSession::record()` documents, so the Allowed intent row and
 * the `PAYMENT_SESSION_OPENED` audit row label the same actor identically.
 * A role-granted opener is an admin by condition 4, but the guard's intent
 * rows do not distinguish that either; relabelling one side would make the
 * pair disagree.
 */
final readonly class OpenPaymentSession
{
    public function __construct(
        private GuardPaymentSession $guard,
        private PaymentCheckoutClient $checkout,
        private ModeResolver $modes,
        private ActorContextResolver $actors,
        private CorrelationContext $correlation,
    ) {}

    public function __invoke(OpenPaymentSessionCommand $command): PaymentSession
    {
        if ($command->orderType !== OrderType::Booking) {
            throw PaymentSessionOrderTypeNotSupportedException::forOrderType($command->orderType);
        }

        $order = $this->resolveOrder($command->orderRef);

        $result = ($this->guard)($order, new Money($command->amountMinor));

        if ($result->isDenied()) {
            throw PaymentSessionOpeningDeniedException::forResult($result);
        }

        $this->assertMerchantBound($command);

        $providerResult = $this->checkout->createPayment(new CreatePaymentRequest(
            orderId: $order->reference,
            amountMinor: $command->amountMinor,
            successReturnUrl: $command->successReturnUrl,
            cancelReturnUrl: $command->cancelReturnUrl,
        ));

        $actor = $this->actors->resolve();
        $actorRole = $actor->isAuthenticated() ? 'customer' : 'guest';
        $correlationId = $this->correlation->current();
        $mode = $this->modes->paymentMode();
        $provider = (string) config('payment.default', PaymentProviders::SUMOPOD_SANDBOX);

        return Audit::wrap(
            mutation: function () use ($command, $providerResult, $mode, $actor, $actorRole, $provider, $correlationId): PaymentSession {
                $intent = PaymentIntent::query()->create([
                    'requested_amount_minor' => $command->amountMinor,
                    'currency' => (string) config('money.currency'),
                    'payment_mode' => $mode->value,
                    'decision' => PaymentIntentDecision::Allowed->value,
                    'actor_ref' => $actor->identityReference,
                    'actor_role' => $actorRole,
                    'correlation_id' => $correlationId === null ? null : (string) $correlationId,
                    'evaluated_at' => CarbonImmutable::now(),
                ]);

                return PaymentSession::query()->create([
                    'payment_intent_id' => $intent->id,
                    'provider' => $provider,
                    'provider_payment_id' => $providerResult->paymentId,
                    'payment_link_url' => $providerResult->paymentLinkUrl,
                    // The guard-verified amount, never the provider's echo:
                    // the provider is not the authority on what we authorized.
                    // A divergent echo is a provider bug and reconciliation's
                    // problem (AC10), not a reason to misrecord the session.
                    'amount_minor' => $command->amountMinor,
                    'currency' => (string) config('money.currency'),
                    'merchant_ref' => $command->merchantRef,
                    'badan_usaha_ref' => (string) config('payment.badan_usaha_ref', ''),
                    'state' => SessionState::AwaitingPayment->value,
                    'expires_at' => $providerResult->expiresAt,
                ]);
            },
            action: PaymentAuditActions::SESSION_OPENED,
            subject: fn (PaymentSession $session): AuditSubject => new AuditSubject('payment_session', $session->id),
            outcome: AuditOutcome::Allowed,
            actorRef: $actor->identityReference,
            actorRole: $actorRole,
            source: AuditSource::Api,
            correlationId: $correlationId === null ? null : (string) $correlationId,
            // `note` is an EXISTING `MetadataAllowlist::ALLOWED_KEYS` key —
            // this lane adds none. Closed-list values only: the order type
            // and the provider slug. No amount, no identifier, no URL,
            // nothing restricted (`AGENTS.md` §Observability).
            metadata: [
                'note' => sprintf(
                    'order_type=%s; provider=%s',
                    $command->orderType->value,
                    $provider,
                ),
            ],
        );
    }

    private function resolveOrder(string $orderRef): Order
    {
        $order = Order::query()->where('reference', $orderRef)->first();

        if ($order === null) {
            throw PaymentSessionOrderNotFoundException::forReference($orderRef);
        }

        return $order;
    }

    /**
     * The session's merchant must be the merchant this deployment is bound
     * to. `config('payment.merchant_ref')` is the FIN-DEC-01 provisioning
     * channel the approved design makes real via config; the guard's
     * condition 6 has already verified that binding is non-empty before this
     * runs, so only the claim-versus-binding comparison is checked here.
     */
    private function assertMerchantBound(OpenPaymentSessionCommand $command): void
    {
        $boundMerchant = (string) config('payment.merchant_ref', '');

        if ($command->merchantRef !== $boundMerchant) {
            throw PaymentSessionMerchantMismatchException::forRefs($command->merchantRef, $boundMerchant);
        }
    }
}
