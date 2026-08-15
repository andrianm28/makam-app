<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\Exceptions\MarketplacePayableMissingException;
use App\Domain\Marketplace\MarketplaceAuditActions;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\PaymentState;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\FinancialLedger\Actions\VendorPayable;
use App\Platform\FinancialLedger\Models\VendorPayable as VendorPayableModel;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\VendorPayableAssessmentTrigger;
use App\Platform\FinancialLedger\VendorPayableEligibility;
use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY path a marketplace order's `payment_state` may move to `DIBAYAR`
 * through — the customer-side order root's paid transition (Task 5 of
 * `docs/superpowers/plans/2026-08-14-online-payment-gateway.md`).
 *
 * ---------------------------------------------------------------------------
 * Who calls it, and what the webhook path can and cannot state
 * ---------------------------------------------------------------------------
 * `App\Platform\Payment\Actions\ApplyPaymentSettlement` routes a claimed
 * `payment.completed` webhook here. A webhook's only fact is that the money
 * arrived; the other two eligibility conditions — accepted fulfilment
 * evidence and the dispute window — have no record in this domain yet
 * (`VendorPayableEligibility`'s doc block names the specs that own them). The
 * action therefore takes them as explicit arguments with fail-closed defaults
 * (`false` / `null`), so no caller can make a payable eligible without stating
 * all three facts, and AC8 ("a paid state SHALL NOT imply payable") stays real
 * on this path: a webhook-paid order whose evidence facts are unmodelled stays
 * HELD. When the owning domain supplies the facts, the same call releases the
 * payable (`HELD` -> `payable`) — the release mechanism is wired now.
 *
 * ---------------------------------------------------------------------------
 * No transition graph, deliberately — the same stance as `UpdateVendorOrderStatus`
 * ---------------------------------------------------------------------------
 * `PaymentState` is a closed list with no documented legality of moves; the
 * action accepts the transition from any known state and is idempotent when
 * the order is already `DIBAYAR` (a replay of the same payment, or a second
 * payment arrival, changes nothing). A future batch with a real state machine
 * replaces the assignment with the machine's validator; the audit + single-
 * write-path shape below is what survives.
 *
 * ---------------------------------------------------------------------------
 * The vendor payable re-assessment
 * ---------------------------------------------------------------------------
 * Checkout (`PlaceMarketplaceOrder`) opens the payable HELD with
 * `(source_type = 'marketplace_order', source_id = order id)`. This action
 * re-assesses the SAME obligation with `orderPaid: true`, passing back the
 * payable's own amount so the frozen vendor share can never drift, and names
 * `VendorPayableAssessmentTrigger::UnattendedAssessment` — a webhook has no
 * human behind it, exactly as checkout's opening assessment does not
 * (`PlaceMarketplaceOrder` Ruling 2). A payment on an order whose payable was
 * never opened is a data-integrity anomaly and fails closed.
 *
 * G-PAYOUT-01 stays closed: the re-assessment at most reaches `payable`
 * (eligible). Nothing here calls `ManualPayout::pay()`; `paid` is reached
 * only through a human-authorized payout.
 *
 * ---------------------------------------------------------------------------
 * Audited, but not `SensitiveActions`-listed
 * ---------------------------------------------------------------------------
 * Same reasoning as `MarketplaceAuditActions`'s existing entry: a machine-
 * driven payment confirmation (the signed webhook is the authority) has no
 * human-authored justification to extract — the paid state is a recorded
 * fact, not a decision. `VENDOR_PAYABLE_ASSESSED` follows the same stance on
 * the payable row.
 */
final readonly class MarkMarketplaceOrderPaid
{
    public function __invoke(
        MarketplaceOrder $order,
        bool $fulfilmentEvidenceAccepted = false,
        ?CarbonImmutable $disputeWindowEndsAt = null,
        ?string $actorRef = null,
        string $actorRole = 'system',
        ?string $correlationId = null,
        AuditSource $source = AuditSource::Job,
        ?CarbonImmutable $now = null,
    ): MarketplaceOrder {
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use (
            $order,
            $fulfilmentEvidenceAccepted,
            $disputeWindowEndsAt,
            $actorRef,
            $actorRole,
            $correlationId,
            $source,
            $now,
        ): MarketplaceOrder {
            /** @var MarketplaceOrder $order */
            $order = MarketplaceOrder::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($order->payment_state === PaymentState::DIBAYAR) {
                return $order;
            }

            $previousState = $order->payment_state;

            $order->forceFill(['payment_state' => PaymentState::DIBAYAR])->save();

            $this->releasePayable($order, $fulfilmentEvidenceAccepted, $disputeWindowEndsAt, $correlationId, $now);

            Audit::record(
                action: MarketplaceAuditActions::ORDER_PAYMENT_STATE_CHANGED,
                subject: new AuditSubject('marketplace_order', $order->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: $source,
                correlationId: $correlationId,
                metadata: [
                    'previous_state' => (string) $previousState,
                    'new_state' => PaymentState::DIBAYAR,
                ],
            );

            return $order;
        });
    }

    /**
     * The ONE ledger call site for the paid fact: re-assess the payable the
     * placement opened, with `orderPaid: true` and the caller-stated facts.
     * The amount is the payable's own frozen share, never a recomputation.
     */
    private function releasePayable(
        MarketplaceOrder $order,
        bool $fulfilmentEvidenceAccepted,
        ?CarbonImmutable $disputeWindowEndsAt,
        ?string $correlationId,
        CarbonImmutable $now,
    ): void {
        $payable = VendorPayableModel::query()
            ->where('vendor_id', $order->vendor_id)
            ->where('source_type', 'marketplace_order')
            ->where('source_id', $order->getKey())
            ->first();

        if (! $payable instanceof VendorPayableModel) {
            throw MarketplacePayableMissingException::forOrder($order->getKey());
        }

        // Resolved here, not constructor-injected, so the Action stays
        // constructible with zero arguments while still reading the
        // container's per-request scoped `ActorContext` — the same source
        // `PlaceMarketplaceOrder`'s opening assessment reads.
        $actorContext = app(ActorContext::class);

        (new VendorPayable(actorContext: $actorContext))->assess(
            vendorId: (string) $order->vendor_id,
            entityRef: (string) $order->entity_ref,
            sourceType: 'marketplace_order',
            sourceId: $order->getKey(),
            amount: new Money((int) $payable->amount_minor),
            eligibility: new VendorPayableEligibility(
                orderPaid: true,
                fulfilmentEvidenceAccepted: $fulfilmentEvidenceAccepted,
                disputeWindowEndsAt: $disputeWindowEndsAt,
            ),
            trigger: VendorPayableAssessmentTrigger::UnattendedAssessment,
            correlationId: $correlationId,
            now: $now,
        );
    }
}
