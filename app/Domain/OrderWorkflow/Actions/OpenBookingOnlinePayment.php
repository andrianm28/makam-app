<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderTransition;
use App\Domain\Quotation\Actions\ComposeQuoteLinesFromBookingDraft;
use App\Domain\Quotation\Actions\IssueQuote;
use App\Domain\Quotation\Models\Quote;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\Payment\Actions\OpenPaymentSession;
use App\Platform\Payment\Actions\OpenPaymentSessionCommand;
use App\Platform\Payment\Exceptions\PaymentSessionOpeningDeniedException;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\OrderType;
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ARCH-01 remediation (`docs/superpowers/plans/2026-09-07-batchm5a-domain-
 * action-extraction.md`). Extracts `BookingWizard::openOnlinePayment()`'s
 * P0 submission chain — submit the draft as an order, ensure a current
 * quote exists, open the hosted checkout for the quote total — out of the
 * Livewire component and into this Domain Action. The wizard now calls this
 * in essentially one line; every exception this class lets propagate is one
 * the wizard already had a catch clause for, unchanged.
 *
 * ---------------------------------------------------------------------------
 * `IssueOrderQuote` vs `IssueQuote` — verified, not assumed
 * ---------------------------------------------------------------------------
 * `IssueOrderQuote` additionally records an `OrderStatus` transition to
 * `PENAWARAN_TERKIRIM` — but `OrderTransition::ALLOWED` only permits that
 * edge from `DIVERIFIKASI` or `MENUNGGU_KETERSEDIAAN`, never from `MASUK`
 * (the status every order starts at, per `SubmitBookingDraft`). Calling
 * `IssueOrderQuote` unconditionally would throw
 * `IllegalOrderTransitionException` on the wizard's common path — a freshly
 * submitted order with no quote yet — AFTER the quote row is already
 * committed (`IssueOrderQuote` writes the quote via `IssueQuote` first,
 * then attempts the status transition). `GuardPaymentSession::
 * CONFIRMED_STATUSES` starting at `PENAWARAN_TERKIRIM`, and
 * `BookingWizardOnlinePaymentTest::operatorCompletes()`'s explicit
 * `DIVERIFIKASI -> MENUNGGU_KETERSEDIAAN -> PENAWARAN_TERKIRIM` sequence,
 * both confirm that transition is a deliberately operator-only act.
 *
 * The real gap `IssueOrderQuote` fixes is narrower: when an operator has
 * ALREADY verified the order (moved it to `DIVERIFIKASI` or
 * `MENUNGGU_KETERSEDIAAN`) before any quote existed, and the customer's
 * online-payment click is what first composes one, the order's status
 * should advance to `PENAWARAN_TERKIRIM` with a real status event — plain
 * `IssueQuote` would otherwise leave the order stuck at the earlier status
 * with an issued quote the payment guard can never see as confirmed. So the
 * choice between the two is made on the order's CURRENT status, not
 * hardcoded to either.
 */
final readonly class OpenBookingOnlinePayment
{
    public function __construct(
        private SubmitBookingDraft $submitBookingDraft,
        private IssueQuote $issueQuote,
        private IssueOrderQuote $issueOrderQuote,
        private ComposeQuoteLinesFromBookingDraft $composeLines,
        private ActorContextResolver $actors,
        private OpenPaymentSession $openPaymentSession,
        private SettingsService $settings,
    ) {}

    public function __invoke(BookingDraft $draft, string $idempotencyKey): PaymentSession
    {
        $order = ($this->submitBookingDraft)($draft, $idempotencyKey);

        $quote = Quote::currentFor($order);

        if (! $quote instanceof Quote) {
            $quote = $this->issueQuoteFor($order, $draft);
        }

        // PAY-04 (PR #257): pre-generate the session id so it can be embedded
        // in the return/cancel URLs BEFORE the `payment_sessions` row exists.
        // Without it the return page has no `session` selector to resolve
        // (`ReturnPageState::fromRequest()`) and can never say which session
        // it is describing. Extracting this chain must not quietly drop that
        // fix, so the id is generated here rather than left to the provider.
        $paymentSessionId = (string) Str::uuid();

        try {
            $session = ($this->openPaymentSession)(new OpenPaymentSessionCommand(
                orderType: OrderType::Booking,
                orderRef: $order->reference,
                // The current quote's total in integer minor units — the amount
                // the six-condition guard's condition 5 verifies; never a
                // client-supplied figure.
                amountMinor: $quote->totalMinor()->toMinorInt(),
                merchantRef: (string) $this->settings->setting(
                    SiteSetting::KEY_PAYMENT_MERCHANT_REF,
                    (string) config('payment.merchant_ref', ''),
                ),
                successReturnUrl: route('payments.return', ['session' => $paymentSessionId]),
                cancelReturnUrl: route('payments.cancel', ['session' => $paymentSessionId]),
                sessionId: $paymentSessionId,
            ));

            // H-2: record, on the ORDER, that a checkout is now open for it.
            // Without this the hourly `orders:expire-stale-quotes` sweep has
            // no way to distinguish "nobody is paying this order" from
            // "the customer is on the provider's payment page right now",
            // and expires the second case — releasing the plot for resale
            // while the money is in flight. See
            // `QuoteExpiryScheduler::expireDueOrders()` for the reader and
            // the migration's doc block for why a column rather than a join.
            //
            // NOT atomic with the session row, and that is a deliberate
            // trade rather than an oversight. `OpenPaymentSession` makes a
            // blocking HTTP call to the provider before it writes anything,
            // so wrapping both in one transaction would hold a database
            // transaction open across a network round trip — on a 2 vCPU
            // host with a pooled connection, that is its own outage shape.
            // The residual window is: session committed, process dies here,
            // order never learns of it. That order then behaves exactly as
            // it does today (pre-fix), which is strictly no worse than the
            // status quo rather than a regression this introduces. Closing
            // it properly needs the provider call moved out of the write
            // path entirely; that is a larger change than this fix.
            $order->linkPaymentSession($session);

            return $session;
        } catch (PaymentSessionOpeningDeniedException $denial) {
            // Name the order the denial is about. `OpenPaymentSession` cannot:
            // it is handed a reference and throws before establishing that the
            // reference names anything. THIS class submitted the order a few
            // lines above, so it is the one place that knows the order exists
            // and what it is called.
            //
            // This matters to the customer, not just to logs: a denial by the
            // three operator-owned conditions means the booking WAS accepted
            // and is waiting on the operator, and the UI says so by quoting
            // the reference back (the 8 Sep 2026 "payment failed" report).
            // Re-thrown, never swallowed — the caller's fail-closed handling
            // is unchanged.
            throw $denial->withOrderReference($order->reference);
        }
    }

    /**
     * No current quote exists for this order yet. The quote's actor context
     * comes from the same seam `OpenPaymentSession` reads
     * (`ActorContextResolver`); an anonymous submission names the draft,
     * mirroring `SubmitBookingDraft`'s own initial-event reference — the
     * only stable, non-identifying reference that exists here.
     */
    private function issueQuoteFor(Order $order, BookingDraft $draft): Quote
    {
        $actor = $this->actors->resolve();
        $actorRef = $actor->identityReference !== null
            ? (string) $actor->identityReference
            : 'booking_draft:'.$draft->id;
        $actorRole = $actor->isAuthenticated() ? 'customer' : 'guest';
        $expiresAt = CarbonImmutable::now()->addDays(IssueOrderQuote::DEFAULT_VALIDITY_DAYS);

        if (OrderTransition::isAllowed($order->status(), OrderStatus::PENAWARAN_TERKIRIM)) {
            ($this->issueOrderQuote)($order, $expiresAt, $actorRef, $actorRole);

            return Quote::currentFor($order) ?? throw new RuntimeException(
                "IssueOrderQuote reported success for order [{$order->getKey()}] but no current quote exists."
            );
        }

        return ($this->issueQuote)(
            $order,
            ($this->composeLines)($draft),
            $expiresAt,
            $actorRef,
            $actorRole,
        );
    }
}
