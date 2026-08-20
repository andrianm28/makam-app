<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\MarketplaceAuditActions;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\PaymentState;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
use App\Platform\FinancialLedger\Money;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;

/**
 * The marketplace-shaped payment-opening precondition guard — the analog of
 * `App\Platform\Payment\GuardPaymentSession` for a `MarketplaceOrder`
 * instead of a booking `Order`/`Quote` pair. Called from
 * `App\Platform\Payment\Actions\OpenPaymentSession`'s marketplace branch,
 * the deferred follow-up `App\Platform\Payment\OrderType`'s doc block and
 * `docs/superpowers/plans/2026-08-14-online-payment-gateway.md` Task 4's
 * risk register both named ("the six-condition guard is `Order`/`Quote`-typed
 * and the marketplace has no `Quote`... escalate the exact shape if
 * ambiguous").
 *
 * ---------------------------------------------------------------------------
 * Why this is a NEW, marketplace-owned type and not a reuse of
 * `GuardPaymentSession`/`GuardCondition`/`GuardResult`
 * ---------------------------------------------------------------------------
 * `GuardCondition`, `GuardResult` and `ConditionDenial` are typed and
 * documented as booking's own six-condition contract
 * (`.kiro/specs/platform-payment-adapter/design.md` §Payment guard), and
 * `payment_intents.denied_condition`/`denied_conditions` are documented as
 * carrying `GuardCondition` values specifically. Writing marketplace-shaped
 * denial strings into that column would misrepresent the row's documented
 * contract even though no DB CHECK currently stops it. This class instead
 * follows the established second precedent in this codebase for a
 * domain-owned, non-`Order`-typed payment-opening guard:
 * `App\Domain\Renewal\Actions\GuardRenewalPaymentOpening` — its own
 * doc block: "Mirrors `GuardResult`'s shape... but is owned by [the domain]
 * and produces a [domain-shaped] result."
 *
 * ---------------------------------------------------------------------------
 * FOUR conditions, not six — the ones booking's guard has that are real here
 * ---------------------------------------------------------------------------
 * Read against booking's six (`GuardPaymentSession`'s own table):
 *
 * | # | Booking condition                      | Marketplace analog                                    |
 * |---|-----------------------------------------|--------------------------------------------------------|
 * | 1 | product gate open (G-PAY-01)             | SAME — `ModeResolver::paymentMode()`, real here too    |
 * | 2 | confirmation valid OR reservation active | order's `payment_state` is `BELUM_DIBAYAR`             |
 * | 3 | quote accepted and unexpired             | NOT APPLICABLE — no `Quote` concept (see below)        |
 * | 4 | authorized opening (admin grant)         | NOT APPLICABLE — no admin step in checkout (see below) |
 * | 5 | amount == quote total                    | amount == `MarketplaceOrder::total()`, integer minor   |
 * | 6 | merchant + `badan_usaha` bound            | SAME check, same config/settings binding               |
 *
 * Condition 3 has no marketplace analog: `.kiro/specs/funeral-marketplace-
 * and-vendor-portal/design.md` §MVP order flow has no confirmation/
 * acceptance step between checkout and payment — `PlaceMarketplaceOrder`
 * creates the order and its `vendor_orders` allocation synchronously, with
 * no "offer" the customer separately accepts. `MarketplaceOrder::total()`
 * (frozen at placement) is the closest analog and is folded into the
 * amount-match condition instead of invented as a second quote-shaped one.
 *
 * Condition 4 has no marketplace analog either, for the same reason
 * `GuardRenewalPaymentOpening`'s own doc block gives for renewal's missing
 * fifth condition: there is no admin authorization step in the marketplace
 * checkout flow to model (`funeral-marketplace-and-vendor-portal/design.md`
 * §Security lists vendor-scope, evidence-file and payout-action controls —
 * nothing gates a customer's own payment on an admin's prior authorization,
 * unlike a booking's plot reservation). Documenting the absence here is the
 * honest reading, per that guard's own precedent, rather than a
 * `$authorized = true` no branch ever reads.
 *
 * ---------------------------------------------------------------------------
 * "Already paid" is checked by the CALLER, not this guard — mirrors booking
 * ---------------------------------------------------------------------------
 * `OpenPaymentSession::assertOrderNotAlreadyPaid()` refuses a `DIBAYAR`
 * booking order BEFORE calling `GuardPaymentSession` at all (that class's own
 * doc block explains why: condition 2's `CONFIRMED_STATUSES` list includes
 * `DIBAYAR`, so the guard alone cannot stop a second session for an
 * already-paid order). `OpenPaymentSession`'s marketplace branch mirrors that
 * exact split with its own `assertMarketplaceOrderNotAlreadyPaid()` before
 * calling this guard. This guard's own `BELUM_DIBAYAR`-only condition 2 is
 * therefore redundant-safe with that precheck (defense in depth, the same
 * relationship booking's condition 2 and precheck have), not the primary
 * defense against a double charge.
 *
 * ---------------------------------------------------------------------------
 * Only `BELUM_DIBAYAR` opens a session — `GAGAL`/`MENUNGGU_VERIFIKASI` are
 * refused, deliberately, pending a human product decision
 * ---------------------------------------------------------------------------
 * `PaymentState::GAGAL` (a failed online attempt) and `::MENUNGGU_VERIFIKASI`
 * (a manual-transfer proof pending admin review) are both real states in the
 * closed list, but neither has a writer anywhere in this codebase today
 * (`SubmitManualPayment` deliberately does not touch
 * `marketplace_orders.payment_state` — see `Livewire\Public\Marketplace\
 * Checkout`'s class doc block). Whether a customer should be allowed to
 * retry online payment from `GAGAL`, or open a competing online session
 * while a manual proof sits in `MENUNGGU_VERIFIKASI`, is a real product
 * question with no approved spec answer — allowing either would invent
 * semantics `App\Platform\Payment\OrderType`'s own doc block warns against
 * ("mapping its state onto the booking conditions would invent semantics no
 * approved spec has ratified"). Refusing both is the fail-closed reading;
 * this is flagged in the implementing report as a decision for a human,
 * not resolved here.
 */
final readonly class GuardMarketplacePaymentOpening
{
    public function __construct(
        private ModeResolver $modes,
        private ActorContextResolver $actors,
        private CorrelationContext $correlation,
    ) {}

    public function __invoke(MarketplaceOrder $order, Money $requestedAmount): MarketplacePaymentOpeningResult
    {
        // Condition 1 — G-PAY-01, server-resolved here (never caller-
        // supplied), exactly as `GuardPaymentSession`'s own condition 1 is.
        if ($this->modes->paymentMode() !== PaymentMode::Online) {
            return $this->deny(
                $order,
                'Online payment is not currently available; payment is arranged manually.',
            );
        }

        // Condition 2 — the order is awaiting payment. See class doc block
        // for why only `BELUM_DIBAYAR` passes.
        if ($order->payment_state !== PaymentState::BELUM_DIBAYAR) {
            return $this->deny(
                $order,
                'Payment cannot be started because the order is not awaiting payment.',
            );
        }

        // Condition 3 (booking's condition 5) — the requested amount must
        // equal the order's own frozen total, in integer minor units, and
        // that total must be strictly positive (defense in depth alongside
        // the `marketplace_orders_total_positive` CHECK).
        if (! $order->total()->isPositive() || $order->total()->toMinorInt() !== $requestedAmount->toMinorInt()) {
            return $this->deny(
                $order,
                'Payment cannot be started because the amount does not match the order total.',
            );
        }

        // Condition 4 (booking's condition 6) — merchant and `badan_usaha`
        // bound and non-blank. The SAME payment-platform-wide binding
        // booking's condition 6 checks (`KEY_PAYMENT_MERCHANT_REF`/
        // `KEY_PAYMENT_BADAN_USAHA_REF`) — NOT `marketplace.badan_usaha_ref`/
        // `KEY_MARKETPLACE_BADAN_USAHA_REF`, which is a different binding
        // (the vendor-payable entity `PlaceMarketplaceOrder` freezes onto
        // `marketplace_orders.entity_ref`). Checked explicitly here, not
        // left to `OpenPaymentSession::assertMerchantBound()` alone: that
        // method only compares the command's claimed `merchantRef` against
        // the bound value, and `Checkout::payOnline()` derives its claim
        // from the SAME `SettingsService` read — so if the binding is blank,
        // claim and bound value are both `''` and `assertMerchantBound()`
        // would pass vacuously. This condition is what actually fails
        // closed when FIN-DEC-01 is unprovisioned.
        $merchantRef = trim((string) app(SettingsService::class)
            ->setting(SiteSetting::KEY_PAYMENT_MERCHANT_REF, (string) config('payment.merchant_ref', '')));
        $badanUsahaRef = trim((string) app(SettingsService::class)
            ->setting(SiteSetting::KEY_PAYMENT_BADAN_USAHA_REF, (string) config('payment.badan_usaha_ref', '')));

        if ($merchantRef === '' || $badanUsahaRef === '') {
            return $this->deny(
                $order,
                'Payment cannot be started because the merchant and business-entity binding is not available (FIN-DEC-01 pending).',
            );
        }

        return MarketplacePaymentOpeningResult::allowed();
    }

    /**
     * Record the denial and return it. Mirrors `GuardPaymentSession`'s own
     * division of labour: the guard audits its own denials; an ALLOWED
     * evaluation writes nothing here — `OpenPaymentSession` already writes
     * the `PAYMENT_SESSION_OPENED` audit event atomically with the session,
     * uniformly for every `OrderType` (no marketplace-specific change was
     * needed there).
     */
    private function deny(MarketplaceOrder $order, string $reason): MarketplacePaymentOpeningResult
    {
        $actor = $this->actors->resolve();
        $actorRole = $actor->isAuthenticated() ? 'customer' : 'guest';
        $correlationId = $this->correlation->current();

        Audit::record(
            action: MarketplaceAuditActions::PAYMENT_OPENING_DENIED,
            subject: new AuditSubject('marketplace_order', $order->getKey()),
            outcome: AuditOutcome::Denied,
            actorRef: $actor->identityReference,
            actorRole: $actorRole,
            source: AuditSource::Api,
            correlationId: $correlationId === null ? null : (string) $correlationId,
            // `note` is an EXISTING `MetadataAllowlist::ALLOWED_KEYS` key.
            // `$reason` is always one of the fixed literal strings above —
            // never free text, never a record's contents — same public-safe
            // contract as `App\Platform\Payment\ConditionDenial::
            // $publicMessage` (`AGENTS.md` §Observability).
            metadata: ['note' => $reason],
        );

        return MarketplacePaymentOpeningResult::denied($reason);
    }
}
