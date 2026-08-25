<?php

declare(strict_types=1);

namespace App\Platform\Payment\Actions;

use App\Domain\Marketplace\Actions\GuardMarketplacePaymentOpening;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\PaymentState;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\Renewal\Actions\GuardRenewalPaymentOpening;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalStatus;
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
 * ---------------------------------------------------------------------------
 * Three order types, three guards, one shared opening/provider/write path
 * ---------------------------------------------------------------------------
 * `OrderType::Booking`, `OrderType::Marketplace`, and `OrderType::Renewal`
 * are all supported (`OrderType`'s own doc block names `Marketplace`
 * above's deferred-then-landed history; `Renewal` landed real from the
 * start — no placeholder era). Each order type resolves its aggregate, runs
 * its own precondition guard, and returns a bare order-reference string;
 * everything after that point — merchant binding, the provider call, and
 * the atomic `PaymentIntent`/`PaymentSession`/audit write — is one shared
 * path that does not branch on order type at all (see `authorizeBooking()`/
 * `authorizeMarketplace()`/`authorizeRenewal()` and the shared body below).
 *
 * Booking uses `GuardPaymentSession`, the `Order`/`Quote`-typed six-condition
 * guard. Marketplace uses `App\Domain\Marketplace\Actions\
 * GuardMarketplacePaymentOpening`, a marketplace-owned, four-condition guard
 * — read that class's own doc block for exactly which of booking's six
 * conditions carry over and why two do not (no `Quote`, no admin
 * authorization step in marketplace checkout). Renewal uses
 * `App\Domain\Renewal\Actions\GuardRenewalPaymentOpening`, a renewal-owned,
 * four-condition guard whose "allowed" result additionally distinguishes
 * `manualCoordinationRequired` (see `authorizeRenewal()`'s own doc block for
 * why that state is refused here exactly like a denial). None of the three
 * guards are unified into one type: `GuardCondition`/`GuardResult`/
 * `ConditionDenial` are documented as booking's own contract, tied to
 * `payment_intents`' documented column shapes, and forcing another domain's
 * different condition set through that contract would either invent
 * booking-shaped denial data for a foreign fact or weaken the contract for
 * all three.
 *
 * Flow, in the plan's order (booking's original shape; marketplace and
 * renewal mirror it through their own guards):
 *
 *   1. **Evaluate the precondition guard** (`GuardPaymentSession` for
 *      booking, `GuardMarketplacePaymentOpening` for marketplace,
 *      `GuardRenewalPaymentOpening` for renewal). A denial throws
 *      `PaymentSessionOpeningDeniedException`; each guard has already
 *      recorded its own denial audit event before this exception exists —
 *      `PAYMENT_GUARD_DENIED` (+ a `payment_intents` row) for booking,
 *      `MARKETPLACE_PAYMENT_OPENING_DENIED` for marketplace. (Renewal's
 *      guard records no denial audit event of its own — see
 *      `GuardRenewalPaymentOpening`'s class doc block; this action's shared
 *      `PAYMENT_SESSION_OPENED` audit write only ever fires on the allowed
 *      path, so a renewal denial is unaudited here exactly as it always was
 *      before this action existed.) Nothing else happens — no provider
 *      call, no session.
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
 * fix wave) — for ALL THREE order types
 * ---------------------------------------------------------------------------
 * A DIBAYAR booking order satisfies all six guard conditions (its
 * confirmation is valid, its quote accepted), so the guard alone cannot stop
 * a resumed wizard from opening a SECOND session and collecting a second
 * payment that `ApplyPaidEffects` would silently swallow. This action
 * therefore refuses the opening before the guard evaluation: an order whose
 * status is DIBAYAR is already paid, and there is nothing left to authorize
 * payment for. The refusal is recorded as a `PAYMENT_SESSION_OPENING_REFUSED`
 * audit event (`AuditOutcome::Denied`) before the exception is thrown.
 *
 * The marketplace follow-up mirrors this exact split: a `MarketplaceOrder`
 * whose `payment_state` is already `DIBAYAR` is refused the same way, before
 * `GuardMarketplacePaymentOpening` ever runs — see
 * `assertMarketplaceOrderNotAlreadyPaid()`.
 *
 * The renewal leg needs this refusal for the SAME reason, not a weaker one:
 * `GuardRenewalPaymentOpening`'s four conditions (gate mode, grave published,
 * quote accepted+unexpired, amount matches) never reference `renewals.status`
 * or any settlement fact, and `RenewalQuote::isAcceptedAndUnexpired()` returns
 * true forever once accepted whenever `expires_at` is null — which
 * `OpenRenewal.php` always leaves null today (no expiry-window feature
 * exists yet). So an already-`DIBAYAR` renewal with a published grave and an
 * open gate would sail through the guard as a genuine `allowed()`, exactly
 * like a DIBAYAR booking order sails through its six conditions. This action
 * refuses it before the guard evaluation the same way, via
 * `assertRenewalNotAlreadySettled()` — see that method.
 *
 * This is the PAID half of the review's "no second session for an
 * already-paid order" protection. The OPEN half — an order still
 * `MENUNGGU_PEMBAYARAN` (booking/renewal) or `BELUM_DIBAYAR` (marketplace)
 * whose earlier checkout was never settled — is not expressible here:
 * `payment_sessions` deliberately carries no order reference (see
 * `SessionState`'s doc block; the order reference travels the provider round
 * trip), so an "open sessions for this order" query does not exist before
 * the provider echo. That race is caught at settlement time instead, by
 * `ApplyPaymentSettlement`'s audited duplicate-arrival record (booking leg;
 * the marketplace leg's `MarkMarketplaceOrderPaid` is idempotent on an exact
 * amount match instead — see that class's own doc block; the renewal leg's
 * `MarkRenewalPaidOnline`, per the online-payment gateway plan, refuses a
 * second `DIBAYAR` transition the same way). The provider-side duplicate
 * record is also what protects the DIBAYAR case against the race where a
 * second payment was already created before this refusal could land.
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
        private GuardMarketplacePaymentOpening $marketplaceGuard,
        private PaymentCheckoutClient $checkout,
        private ModeResolver $modes,
        private ActorContextResolver $actors,
        private CorrelationContext $correlation,
    ) {}

    public function __invoke(OpenPaymentSessionCommand $command): PaymentSession
    {
        $orderReference = match ($command->orderType) {
            OrderType::Booking => $this->authorizeBooking($command),
            OrderType::Marketplace => $this->authorizeMarketplace($command),
            OrderType::Renewal => $this->authorizeRenewal($command),
        };

        $this->assertMerchantBound($command);

        $providerResult = $this->checkout->createPayment(new CreatePaymentRequest(
            orderId: $orderReference,
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

    /**
     * The booking leg: resolve the `Order`, refuse it if already paid, run
     * the six-condition guard. Returns the order reference the provider call
     * uses. Unchanged in behaviour from before the marketplace follow-up —
     * only extracted into its own method so `__invoke()` can dispatch on
     * `$command->orderType`.
     */
    private function authorizeBooking(OpenPaymentSessionCommand $command): string
    {
        $order = $this->resolveOrder($command->orderRef);

        $this->assertOrderNotAlreadyPaid($order);

        $result = ($this->guard)($order, new Money($command->amountMinor));

        if ($result->isDenied()) {
            throw PaymentSessionOpeningDeniedException::forResult($result);
        }

        return $order->reference;
    }

    /**
     * The marketplace leg: resolve the `MarketplaceOrder`, refuse it if
     * already paid, run `GuardMarketplacePaymentOpening`. Returns the order
     * reference (`order_number`) the provider call uses. Mirrors
     * `authorizeBooking()`'s exact shape — see the class doc block for why
     * the two guards are not unified.
     */
    private function authorizeMarketplace(OpenPaymentSessionCommand $command): string
    {
        $order = $this->resolveMarketplaceOrder($command->orderRef);

        $this->assertMarketplaceOrderNotAlreadyPaid($order);

        $result = ($this->marketplaceGuard)($order, new Money($command->amountMinor));

        if ($result->isDenied()) {
            throw PaymentSessionOpeningDeniedException::forPublicMessage($result->denialReason());
        }

        return $order->order_number;
    }

    /**
     * The renewal leg: resolve the `Renewal` by its business reference,
     * refuse it if already settled, run `GuardRenewalPaymentOpening`. Returns
     * the reference (`renewals.reference`) the provider call uses. Mirrors
     * `authorizeBooking()`'s/`authorizeMarketplace()`'s exact shape,
     * including the pre-guard already-paid refusal — see the class doc
     * block's "already-paid order is refused BEFORE the guard" section for
     * why the renewal leg needs this every bit as much as the other two:
     * `GuardRenewalPaymentOpening`'s conditions never look at `renewals.
     * status`, so a settled renewal would otherwise sail through it.
     *
     * A `manualCoordinationRequired: true` result is refused exactly like a
     * denial — never allowed through to `PaymentSession::create()`. This is
     * the single most important branch in this method: the guard's "eligible
     * but the gate is closed" state must never reach the provider call.
     */
    private function authorizeRenewal(OpenPaymentSessionCommand $command): string
    {
        $renewal = $this->resolveRenewal($command->orderRef);

        $this->assertRenewalNotAlreadySettled($renewal);

        $result = app(GuardRenewalPaymentOpening::class)($renewal, new Money($command->amountMinor));

        if (! $result->isAllowed() || $result->isManualCoordinationRequired()) {
            throw PaymentSessionOpeningDeniedException::forPublicMessage(
                $result->denialReason()
                    ?? 'Online payment is not currently available; payment is arranged manually.'
            );
        }

        return $renewal->reference;
    }

    private function resolveOrder(string $orderRef): Order
    {
        $order = Order::query()->where('reference', $orderRef)->first();

        if ($order === null) {
            throw PaymentSessionOrderNotFoundException::forReference($orderRef);
        }

        return $order;
    }

    private function resolveMarketplaceOrder(string $orderRef): MarketplaceOrder
    {
        $order = MarketplaceOrder::query()->where('order_number', $orderRef)->first();

        if ($order === null) {
            throw PaymentSessionOrderNotFoundException::forReference($orderRef);
        }

        return $order;
    }

    /**
     * Looks up the `Renewal` by its independently-unique `reference` column
     * (the `PPJ-`-prefixed business reference, `2026_08_12_100000_
     * create_renewals_table.php`'s `->unique()` constraint) — NOT by
     * `renewals.id`. The bearer-UUID primary key is the anonymous-journey
     * access key the public Livewire screens use to load a specific
     * renewal (see `GuardRenewalPaymentOpening`'s own doc block); it is a
     * different identifier from the business reference this command's
     * `orderRef` carries, matching `resolveOrder()`'s and
     * `resolveMarketplaceOrder()`'s own reference-column lookups above.
     */
    private function resolveRenewal(string $orderRef): Renewal
    {
        $renewal = Renewal::query()->where('reference', $orderRef)->first();

        if ($renewal === null) {
            throw PaymentSessionOrderNotFoundException::forReference($orderRef);
        }

        return $renewal;
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
     * The marketplace leg of the "already paid" refusal — same reasoning as
     * `assertOrderNotAlreadyPaid()`, keyed off `MarketplaceOrder::
     * $payment_state` instead of `Order::$status`, subject type
     * `marketplace_order` instead of `order`.
     */
    private function assertMarketplaceOrderNotAlreadyPaid(MarketplaceOrder $order): void
    {
        if ($order->payment_state !== PaymentState::DIBAYAR) {
            return;
        }

        $actor = $this->actors->resolve();
        $actorRole = $actor->isAuthenticated() ? 'customer' : 'guest';
        $correlationId = $this->correlation->current();

        Audit::record(
            action: PaymentAuditActions::SESSION_OPENING_REFUSED,
            subject: new AuditSubject('marketplace_order', (string) $order->getKey()),
            outcome: AuditOutcome::Denied,
            actorRef: $actor->identityReference,
            actorRole: $actorRole,
            source: AuditSource::Api,
            correlationId: $correlationId === null ? null : (string) $correlationId,
            metadata: ['note' => 'session opening refused; order already paid'],
        );

        throw PaymentSessionOrderAlreadyPaidException::forReference($order->order_number);
    }

    /**
     * The renewal leg of the "already paid" refusal — same reasoning as
     * `assertOrderNotAlreadyPaid()`/`assertMarketplaceOrderNotAlreadyPaid()`,
     * keyed off `Renewal::$status` instead of `Order::$status`/
     * `MarketplaceOrder::$payment_state`, subject type `renewal` instead of
     * `order`/`marketplace_order`.
     *
     * This check is NOT redundant with `GuardRenewalPaymentOpening`: that
     * guard's four conditions (gate mode, grave published, quote
     * accepted+unexpired, amount matches) never reference `renewals.status`
     * or any settlement fact, and `RenewalQuote::isAcceptedAndUnexpired()`
     * returns true indefinitely once accepted whenever `expires_at` is null
     * — which `Actions\OpenRenewal` always leaves null today. A DIBAYAR
     * renewal with a published grave and an open gate is therefore a
     * genuine `allowed()` from the guard's own logic; only this pre-guard
     * check stops a second real payment session (and a second live
     * provider charge) from opening against it.
     */
    private function assertRenewalNotAlreadySettled(Renewal $renewal): void
    {
        if ($renewal->status !== RenewalStatus::DIBAYAR) {
            return;
        }

        $actor = $this->actors->resolve();
        $actorRole = $actor->isAuthenticated() ? 'customer' : 'guest';
        $correlationId = $this->correlation->current();

        Audit::record(
            action: PaymentAuditActions::SESSION_OPENING_REFUSED,
            subject: new AuditSubject('renewal', (string) $renewal->getKey()),
            outcome: AuditOutcome::Denied,
            actorRef: $actor->identityReference,
            actorRole: $actorRole,
            source: AuditSource::Api,
            correlationId: $correlationId === null ? null : (string) $correlationId,
            // `note` is an EXISTING `MetadataAllowlist::ALLOWED_KEYS` key —
            // this lane adds none. A closed-list value only; the subject
            // carries the renewal. No amount, no identifier, nothing
            // restricted.
            metadata: ['note' => 'session opening refused; renewal already settled'],
        );

        throw PaymentSessionOrderAlreadyPaidException::forReference($renewal->reference);
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
