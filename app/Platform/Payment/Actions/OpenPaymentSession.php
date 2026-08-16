<?php

declare(strict_types=1);

namespace App\Platform\Payment\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
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
use App\Platform\Payment\Exceptions\PaymentSessionOrderAlreadyPaidException;
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
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;
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
 * An already-paid order is refused BEFORE the guard (whole-branch review,
 * fix wave)
 * ---------------------------------------------------------------------------
 * A DIBAYAR order satisfies all six guard conditions (its confirmation is
 * valid, its quote accepted), so the guard alone cannot stop a resumed
 * wizard from opening a SECOND session and collecting a second payment that
 * `ApplyPaidEffects` would silently swallow. This action therefore refuses
 * the opening before the guard evaluation: an order whose status is DIBAYAR
 * is already paid, and there is nothing left to authorize payment for. The
 * refusal is recorded as a `PAYMENT_SESSION_OPENING_REFUSED` audit event
 * (`AuditOutcome::Denied`) before the exception is thrown.
 *
 * This is the PAID half of the review's "no second session for an
 * already-paid order" protection. The OPEN half — an order still
 * `MENUNGGU_PEMBAYARAN` whose earlier checkout was never settled — is not
 * expressible here: `payment_sessions` deliberately carries no order
 * reference (see `SessionState`'s doc block; the order reference travels the
 * provider round trip), so an "open sessions for this order" query does not
 * exist before the provider echo. That race is caught at settlement time
 * instead, by `ApplyPaymentSettlement`'s audited duplicate-arrival record.
 * The provider-side duplicate record is also what protects the DIBAYAR case
 * against the race where a second payment was already created before this
 * refusal could land.
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

        $this->assertOrderNotAlreadyPaid($order);

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
                    'badan_usaha_ref' => (string) app(SettingsService::class)
                        ->setting(SiteSetting::KEY_PAYMENT_BADAN_USAHA_REF, (string) config('payment.badan_usaha_ref', '')),
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
     * The "no second session for an already-paid order" refusal — see the
     * class doc block. A DIBAYAR order is already paid; opening a session for
     * it would let the customer be charged a second time, and the second
     * payment would be silently swallowed by `ApplyPaidEffects`. The refusal
     * is audited (`PAYMENT_SESSION_OPENING_REFUSED`, `AuditOutcome::Denied`)
     * before the exception is thrown, so an operator can see the attempt.
     *
     * Deliberately an order-status check, not a `payment_sessions` query:
     * sessions carry no order reference (class doc block), so the order's own
     * DIBAYAR status is the only pre-provider authority that a payment was
     * collected for this order.
     */
    private function assertOrderNotAlreadyPaid(Order $order): void
    {
        if ($order->status !== OrderStatus::DIBAYAR->value) {
            return;
        }

        $actor = $this->actors->resolve();
        $actorRole = $actor->isAuthenticated() ? 'customer' : 'guest';
        $correlationId = $this->correlation->current();

        Audit::record(
            action: PaymentAuditActions::SESSION_OPENING_REFUSED,
            subject: new AuditSubject('order', (string) $order->getKey()),
            outcome: AuditOutcome::Denied,
            actorRef: $actor->identityReference,
            actorRole: $actorRole,
            source: AuditSource::Api,
            correlationId: $correlationId === null ? null : (string) $correlationId,
            // `note` is an EXISTING `MetadataAllowlist::ALLOWED_KEYS` key —
            // this lane adds none. A closed-list value only; the subject
            // carries the order. No amount, no identifier, nothing restricted.
            metadata: ['note' => 'session opening refused; order already paid'],
        );

        throw PaymentSessionOrderAlreadyPaidException::forReference($order->reference);
    }

    /**
     * The session's merchant must be the merchant this deployment is bound
     * to. The binding resolves through `SettingsService`'s config → env →
     * `site_settings` → default precedence (P2 `admin-data-management`): an
     * operator-managed `payment_merchant_ref` row now participates without
     * an environment change, and the config default keeps the pre-existing
     * env behaviour identical while no DB row exists. The guard's condition
     * 6 has already verified that binding is non-empty before this runs, so
     * only the claim-versus-binding comparison is checked here.
     */
    private function assertMerchantBound(OpenPaymentSessionCommand $command): void
    {
        $boundMerchant = (string) app(SettingsService::class)
            ->setting(SiteSetting::KEY_PAYMENT_MERCHANT_REF, (string) config('payment.merchant_ref', ''));

        if ($command->merchantRef !== $boundMerchant) {
            throw PaymentSessionMerchantMismatchException::forRefs($command->merchantRef, $boundMerchant);
        }
    }
}
