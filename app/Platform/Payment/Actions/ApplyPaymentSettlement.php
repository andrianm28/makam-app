<?php

declare(strict_types=1);

namespace App\Platform\Payment\Actions;

use App\Domain\Marketplace\Actions\MarkMarketplaceOrderPaid;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\OrderWorkflow\Actions\ApplyPaidEffects;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\PaidTrigger;
use App\Domain\OrderWorkflow\PaidTriggerSource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\FinancialLedger\Money;
use App\Platform\Payment\Exceptions\SettlementTargetUnresolvableException;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\ProviderEventType;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;

/**
 * Task 5 — the dual-target settlement dispatcher: everything that happens
 * AFTER a provider event is claimed. `ProcessWebhookEvent` calls
 * `settle()` / `applyOutcome()` inside its claim transaction, so the claim,
 * the paid effects, their audit rows, the outbox row and the session-state
 * transition commit or roll back together.
 *
 * ---------------------------------------------------------------------------
 * The target-resolution mapping, and why it has two legs
 * ---------------------------------------------------------------------------
 * A claimed `provider_events` row carries two identifiers, and each resolves
 * a different half of the question:
 *
 *  1. `provider_transaction_id` (= `data.payment_id`, the provider payment
 *     id) resolves the AUTHORIZATION: `payment_sessions.provider_payment_id`
 *     is UNIQUE per provider, and the session is the record of a payment this
 *     system authorized (the six-condition guard at session opening,
 *     `WebhookValidator`'s AC13 binding + amount snapshot at VALIDATED time).
 *     The settlement re-performs the session lookup rather than trusting the
 *     claim — the row's VALIDATED status is only reachable through the
 *     validator, but an event seeded or replayed around it must still fail
 *     closed here.
 *
 *  2. `invoice_reference` (= `data.order_id`) resolves the TARGET. The
 *     `payment_sessions` row deliberately carries no order reference column —
 *     a session describes ONE checkout attempt and nothing about the order it
 *     would settle (`SessionState`'s doc block). The order reference travels
 *     the provider round trip instead: at session opening the order reference
 *     is sent as the provider's `order_id`, and the provider echoes it back
 *     in the webhook, where the receiver stores it as `invoice_reference`.
 *     So the settlement maps: booking `Order` by `reference` first, else
 *     `MarketplaceOrder` by `order_number`.
 *
 * The two namespaces are disjoint — booking references are `MK-{year}-{8}`
 * (`SubmitBookingDraft::nextReference()`), marketplace order numbers are
 * `MKT-{10}` (`PlaceMarketplaceOrder`) — so the booking-first lookup order is
 * deterministic and cannot silently pick a marketplace order for a booking
 * reference (or vice versa). A reference that matches neither table is a
 * data-integrity anomaly and fails closed (`SettlementTargetUnresolvableException`).
 *
 * ---------------------------------------------------------------------------
 * Booking: the webhook trigger
 * ---------------------------------------------------------------------------
 * The trigger's `sourceId`/`businessKey` are the PROVIDER PAYMENT id
 * (`payment:{provider_transaction_id}`) — the plan's `PaidTrigger(Webhook,
 * 'payment:{id}')`. That id is the one stable identity a payment has across
 * every event the provider sends for it, which is what makes the outbox
 * `idempotencyKey` (`paid_effects:payment:{id}`, UNIQUE) dedupe two events
 * for the same payment as well as two deliveries of one event. `actorRef` is
 * the INTERNAL `provider_events` id, per `PaidTrigger`'s doc block: a webhook
 * holds no credential of ours, and the provider event row is the most
 * specific true statement about who caused the transition.
 *
 * The amount comes from the SESSION snapshot (`amount_minor`), not the
 * payload: `WebhookValidator` proved them equal at VALIDATED time, and the
 * session is the value this system authorized. `ApplyPaidEffects` then
 * re-checks the amount and currency against the order's accepted quote as its
 * own precondition (integer minor units, never a float); the marketplace leg
 * passes the same snapshot into `MarkMarketplaceOrderPaid`, which asserts it
 * against the order's `total_minor` the same way.
 *
 * ---------------------------------------------------------------------------
 * The session state follows the claimed event
 * ---------------------------------------------------------------------------
 * A `payment_sessions` row leaves `AWAITING_PAYMENT` only through a validated
 * webhook (never a browser return URL — `SessionState`): a successfully
 * settled `payment.completed` moves the session to `PAID` (Task 6's return
 * screen reads this), a claimed `payment.failed` to `FAILED`, a claimed
 * `payment.expired` to `EXPIRED`. The transition is guarded: `PAID` is the
 * strongest fact and is never regressed by a late out-of-order
 * `failed`/`expired` arrival, and `FAILED`/`EXPIRED` apply only to an open
 * (CREATED/AWAITING_PAYMENT) session.
 *
 * ---------------------------------------------------------------------------
 * Marketplace: the domain Action owns the effects
 * ---------------------------------------------------------------------------
 * The settlement routes, the marketplace domain applies: `MarkMarketplaceOrderPaid`
 * transitions `payment_state` to `DIBAYAR`, re-assesses the vendor payable
 * with the paid fact, and audits — all in its own transaction, following the
 * `PlaceMarketplaceOrder`/`UpdateVendorOrderStatus` conventions. This class
 * deliberately knows nothing about `PaymentState` or `vendor_payables`, so
 * the two domains stay uncoupled through the same seam the plan's risk
 * register demands ("the router dispatches without coupling domains").
 *
 * G-PAYOUT-01 stays closed: the payable release is a re-assessment
 * (`HELD` -> `payable` when the eligibility rule permits), never a call to
 * `ManualPayout::pay()` and never a `paid` state on this path.
 */
final readonly class ApplyPaymentSettlement
{
    /**
     * Settle one claimed settling event. Must run inside the caller's
     * transaction — see the class doc block. On success the session that
     * authorized the payment moves to `SessionState::Paid`.
     *
     * @throws SettlementTargetUnresolvableException when the event cannot be
     *                                               mapped to a session or an order.
     */
    public function settle(ProviderEvent $event): void
    {
        $session = $this->resolveSessionOrFail($event);

        $invoiceReference = (string) $event->invoice_reference;

        $order = Order::query()->where('reference', $invoiceReference)->first();

        if ($order instanceof Order) {
            $this->settleBooking($order, $event, $session);
            $this->transitionToTerminal($session, SessionState::Paid);

            return;
        }

        $marketplaceOrder = MarketplaceOrder::query()->where('order_number', $invoiceReference)->first();

        if (! $marketplaceOrder instanceof MarketplaceOrder) {
            throw SettlementTargetUnresolvableException::becauseNoOrder($invoiceReference);
        }

        $this->settleMarketplace($marketplaceOrder, $event, $session);
        $this->transitionToTerminal($session, SessionState::Paid);
    }

    /**
     * Apply the outcome a claimed NON-settling event (`payment.failed`,
     * `payment.expired`) records on its session. Must run inside the caller's
     * transaction like `settle()`. A `payment.test` or unrecognised event
     * type changes nothing.
     *
     * @throws SettlementTargetUnresolvableException when the event cannot be
     *                                               mapped to a session — the
     *                                               same fail-closed stance as
     *                                               the settling leg.
     */
    public function applyOutcome(ProviderEvent $event): void
    {
        $session = $this->resolveSessionOrFail($event);

        $state = match (ProviderEventType::tryFrom((string) $event->event_type)) {
            ProviderEventType::Failed => SessionState::Failed,
            ProviderEventType::Expired => SessionState::Expired,
            default => null,
        };

        if ($state !== null) {
            $this->transitionToTerminal($session, $state);
        }
    }

    /**
     * The session this provider transaction belongs to — the same
     * `(provider, provider_payment_id)` lookup `WebhookValidator` performs, so
     * a VALIDATED event always resolves here and an event seeded or replayed
     * around the validator fails closed instead of applying anywhere.
     */
    private function resolveSessionOrFail(ProviderEvent $event): PaymentSession
    {
        $session = PaymentSession::query()
            ->where('provider', $event->provider)
            ->where('provider_payment_id', $event->provider_transaction_id)
            ->first();

        if (! $session instanceof PaymentSession) {
            throw SettlementTargetUnresolvableException::becauseNoSession(
                (string) $event->provider,
                $event->provider_transaction_id,
            );
        }

        return $session;
    }

    /**
     * The session-state machine's only writer, as guarded by the class doc
     * block: `PAID` is never regressed (a late out-of-order `expired` or
     * `failed` arrival after a settled `completed` must not unset the paid
     * fact), and `FAILED`/`EXPIRED` apply only to an open session — a session
     * that already ended terminal stays where the winning event left it.
     */
    private function transitionToTerminal(PaymentSession $session, SessionState $state): void
    {
        $current = SessionState::tryFrom((string) $session->state);

        if ($current === SessionState::Paid) {
            return;
        }

        if ($state !== SessionState::Paid
            && $current !== SessionState::Created
            && $current !== SessionState::AwaitingPayment) {
            return;
        }

        if ($current !== $state) {
            $session->forceFill(['state' => $state->value])->save();
        }
    }

    private function settleBooking(Order $order, ProviderEvent $event, PaymentSession $session): void
    {
        $trigger = new PaidTrigger(
            source: PaidTriggerSource::Webhook,
            sourceId: (string) $event->provider_transaction_id,
            businessKey: 'payment:'.(string) $event->provider_transaction_id,
            amount: new Money((int) $session->amount_minor),
            currency: (string) $session->currency,
            occurredAt: $event->event_occurred_at ?? CarbonImmutable::now(),
            actorRef: (string) $event->getKey(),
            actorRole: 'provider',
        );

        $order = app(ApplyPaidEffects::class)($order, $trigger);

        // Duplicate-arrival detection (whole-branch review fix wave): the
        // claim guarantees this (provider, transaction) pair is new, so an
        // order that is DIBAYAR with a paid source OTHER than this event's
        // transaction was paid by an earlier, independent payment — a second
        // charge that must surface at reconciliation, never silently
        // swallowed by `ApplyPaidEffects::alreadyPaid()`.
        if ($order->status === OrderStatus::DIBAYAR->value
            && (string) $order->paid_source_ref !== (string) $event->provider_transaction_id) {
            $this->recordDuplicateArrival($event);
        }
    }

    /**
     * A duplicate payment arrival is a financial-integrity event: a second,
     * independent provider transaction paid an order an earlier transaction
     * already paid. The trail an operator reads, in the same shape
     * `ProcessWebhookEvent::auditSettlementConflict()` established: subject =
     * the `provider_events` row (which carries the provider transaction id —
     * the id itself is a provider payload value and stays out of the audit
     * row, AC14), `note` closed-list only, no authenticated actor (a provider
     * holds no credential of ours).
     *
     * Runs inside the caller's claim transaction, so this record commits
     * atomically with the claim — a duplicate arrival can never be
     * acknowledged without its record.
     */
    private function recordDuplicateArrival(ProviderEvent $event): void
    {
        Audit::record(
            action: PaymentAuditActions::DUPLICATE_ARRIVAL,
            subject: new AuditSubject('provider_event', (string) $event->getKey()),
            outcome: AuditOutcome::Denied,
            actorRef: null,
            actorRole: 'provider',
            source: AuditSource::Api,
            correlationId: $event->correlation_id,
            metadata: ['note' => 'duplicate payment arrival; order already paid by an earlier transaction'],
        );
    }

    private function settleMarketplace(MarketplaceOrder $order, ProviderEvent $event, PaymentSession $session): void
    {
        app(MarkMarketplaceOrderPaid::class)(
            $order,
            // The session snapshot, the same authority the booking leg uses:
            // `WebhookValidator` proved the payload amount equals it at
            // VALIDATED time, and `MarkMarketplaceOrderPaid` asserts it
            // against the order's `total_minor` before any write.
            amountMinor: (int) $session->amount_minor,
            // The webhook's only fact is that the money arrived. The other two
            // eligibility conditions — accepted fulfilment evidence and the
            // dispute window — have no record in the marketplace domain yet
            // (`VendorPayableEligibility`'s doc block names those specs), and
            // AC8 forbids inventing them: "a paid state SHALL NOT imply
            // payable". The fail-closed defaults keep the payable HELD until
            // the owning domain supplies the facts; the release then happens
            // through this same call.
            fulfilmentEvidenceAccepted: false,
            disputeWindowEndsAt: null,
            // The internal `provider_events` id, the same actor reference the
            // booking leg's `PaidTrigger` carries: a webhook holds no
            // credential of ours, and the provider event row is the most
            // specific true statement about who caused the transition.
            actorRef: (string) $event->getKey(),
            actorRole: 'provider',
            correlationId: $event->correlation_id,
            // `now` deliberately unset: ledger rows (payable assessments,
            // journal batches) are stamped with the system clock, never the
            // provider-controlled occurrence time.
        );
    }
}
