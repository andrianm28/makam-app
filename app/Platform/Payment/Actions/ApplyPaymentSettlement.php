<?php

declare(strict_types=1);

namespace App\Platform\Payment\Actions;

use App\Domain\Marketplace\Actions\MarkMarketplaceOrderPaid;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\OrderWorkflow\Actions\ApplyPaidEffects;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\PaidTrigger;
use App\Domain\OrderWorkflow\PaidTriggerSource;
use App\Platform\FinancialLedger\Money;
use App\Platform\Payment\Exceptions\SettlementTargetUnresolvableException;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\ProviderEvent;
use Carbon\CarbonImmutable;

/**
 * Task 5 — the dual-target settlement dispatcher: everything that happens
 * AFTER a settling provider event is claimed. `ProcessWebhookEvent` calls
 * `settle()` inside its claim transaction, so the claim, the paid effects,
 * their audit rows and the outbox row commit or roll back together.
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
 * own precondition (integer minor units, never a float).
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
     * transaction — see the class doc block.
     *
     * @throws SettlementTargetUnresolvableException when the event cannot be
     *                                               mapped to a session or an order.
     */
    public function settle(ProviderEvent $event): void
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

        $invoiceReference = (string) $event->invoice_reference;

        $order = Order::query()->where('reference', $invoiceReference)->first();

        if ($order instanceof Order) {
            $this->settleBooking($order, $event, $session);

            return;
        }

        $marketplaceOrder = MarketplaceOrder::query()->where('order_number', $invoiceReference)->first();

        if (! $marketplaceOrder instanceof MarketplaceOrder) {
            throw SettlementTargetUnresolvableException::becauseNoOrder($invoiceReference);
        }

        $this->settleMarketplace($marketplaceOrder, $event);
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

        app(ApplyPaidEffects::class)($order, $trigger);
    }

    private function settleMarketplace(MarketplaceOrder $order, ProviderEvent $event): void
    {
        app(MarkMarketplaceOrderPaid::class)(
            $order,
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
            actorRef: null,
            actorRole: 'provider',
            correlationId: $event->correlation_id,
            now: $event->event_occurred_at,
        );
    }
}
