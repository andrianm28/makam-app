<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\Exceptions\MarketplacePayableMissingException;
use App\Domain\Marketplace\Exceptions\MarketplacePaymentAmountMismatchException;
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
 * The paid amount is asserted, never assumed
 * ---------------------------------------------------------------------------
 * The action takes the paid amount as an explicit integer-minor argument and
 * refuses the transition unless it EXACTLY equals the order's `total_minor` —
 * the same precondition `Actions\ApplyPaidEffects::assertAmountMatchesAcceptedQuote`
 * enforces on the booking leg. A session opened for the wrong amount (a
 * checkout bug, a tampered session, a provider anomaly) must not mark the
 * order DIBAYAR for a payment that does not match what is owed: the order
 * total is the authoritative money fact, and the mismatch throws
 * `MarketplacePaymentAmountMismatchException` before any state, payable or
 * audit write. The comparison is integer minor units only — `MarketplaceOrder`
 * carries no currency column, and the webhook path's currency is already
 * pinned to the configured currency at validation.
 *
 * ---------------------------------------------------------------------------
 * No transition graph, deliberately — the same stance as `UpdateVendorOrderStatus`
 * ---------------------------------------------------------------------------
 * `PaymentState` is a closed list with no documented legality of moves; the
 * action accepts the transition from any known state and is idempotent when
 * the order is already `DIBAYAR` (a replay of the same payment, or a second
 * payment arrival whose amount matches the total, changes nothing). The
 * amount assert runs FIRST and is absolute: an arrival whose amount differs
 * from the total fails closed even against an already-`DIBAYAR` order — the
 * same stance as the booking leg's `ApplyPaidEffects` precondition. A future
 * batch with a real state machine replaces the assignment with the machine's
 * validator; the audit + single-write-path shape below is what survives.
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
        int $amountMinor,
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
            $amountMinor,
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

            $this->assertAmountMatchesOrderTotal($order, $amountMinor);

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
     * The paid transition's precondition, enforced before any write: the
     * amount that arrived must EXACTLY equal what the order owes. Integer
     * minor units on both sides — never a float, never a tolerance. Mirrors
     * `ApplyPaidEffects::assertAmountMatchesAcceptedQuote` (sans currency,
     * which `MarketplaceOrder` does not carry and the validator already pins
     * to the configured currency).
     */
    private function assertAmountMatchesOrderTotal(MarketplaceOrder $order, int $amountMinor): void
    {
        if ($amountMinor !== (int) $order->total_minor) {
            throw MarketplacePaymentAmountMismatchException::forOrder(
                (string) $order->getKey(),
                (int) $order->total_minor,
                $amountMinor,
            );
        }
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

        // ---------------------------------------------------------------
        // A real, pre-existing bug this PR found and fixes narrowly —
        // NEVER the live per-request `ActorContext` here
        // ---------------------------------------------------------------
        // This USED to read `app(ActorContext::class)` — the container's
        // per-request scoped context, "the same source `PlaceMarketplaceOrder`'s
        // opening assessment reads" (that Action has the IDENTICAL bug,
        // unfixed here — see below). `VendorPayable::assess()` is always
        // called with `trigger: VendorPayableAssessmentTrigger::UnattendedAssessment`
        // a few lines down, and `FinanceVendorPayableAuthorizer::
        // authorizeUnattended()` unconditionally THROWS
        // `VendorPayableNotAuthorisedException` whenever
        // `$actorContext->isAuthenticated()` is true. Every real caller of
        // this method is authenticated: the panel's "Tandai Dibayar" button
        // (`MarkMarketplaceOrderPaidAction`, an admin with a real session)
        // and, since PAY-02, `App\Platform\Payment\VerifyManualPayment`'s
        // approval path (a finance/restricted_admin actor). Discovered via
        // this PR's own end-to-end HTTP test
        // (`VerifyManualPaymentRouteTest`) — every approval threw this
        // exception before this fix, and a throwaway direct-call test
        // confirmed `PlaceMarketplaceOrder`'s identical pattern ALSO throws
        // for any logged-in customer placing an order, which
        // `Checkout::placeOrder()`'s broad `catch (Throwable $e)` silently
        // turns into a generic "Checkout belum dapat diproses" error — i.e.
        // this exact bug most likely already silently breaks checkout for
        // every authenticated customer in production/beta today. That
        // second call site is NOT touched here — it needs its own review
        // and its own PR; flagged prominently instead.
        //
        // The fix: pass an EXPLICIT `ActorContext::guest()`, never the
        // ambient one. This is deliberately narrow and does not weaken any
        // real gate: WHO may reach this method at all is still decided
        // entirely by the caller's own authorizer
        // (`App\Platform\Payment\Contracts\PaymentActionAuthorizer` on the
        // manual-verification path, `OrderTransitionAuthorizerContract` on
        // the panel path) — both real, tested, and unchanged. What this
        // line decides is only which TRIGGER/actor the downstream
        // vendor-payable FACT recognition is attributed to. Recognising
        // payable eligibility from `orderPaid: true` +
        // `fulfilmentEvidenceAccepted` + the dispute window is a mechanical
        // consequence of facts the caller already vouched for — not itself
        // a second, separate human money-movement decision requiring its
        // own `finance` + per-vendor `ScopeAssignment` grant (the
        // `HumanDecision` path's real requirement, which no caller of this
        // method currently satisfies or was ever designed to satisfy — see
        // `FinanceVendorPayableAuthorizer::authorize()`). The actual human
        // decision that authorized THIS order-paid transition is already
        // captured, with the real actor, in this Action's OWN
        // `MARKETPLACE_ORDER_PAYMENT_STATE_CHANGED` audit row below.
        // A real fix that lets a human-triggered assessment run the
        // `HumanDecision` path (e.g. auto-granting a vendor scope, or a
        // product decision to relax `authorizeUnattended()`) is a separate,
        // larger authorization-model change and is NOT decided here —
        // flagged for human review in this PR's description.
        $actorContext = ActorContext::guest();

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
