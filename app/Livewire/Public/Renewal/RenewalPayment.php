<?php

declare(strict_types=1);

namespace App\Livewire\Public\Renewal;

use App\Domain\Renewal\Actions\GuardRenewalPaymentOpening;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalJourneyStep;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\Payment\Actions\OpenPaymentSession;
use App\Platform\Payment\Actions\OpenPaymentSessionCommand;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutProviderException;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutUnavailableException;
use App\Platform\Payment\Exceptions\PaymentSessionOpeningDeniedException;
use App\Platform\Payment\Exceptions\PaymentSessionOrderAlreadyPaidException;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\OrderType;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\SessionState;
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Step 5, PUB-033 — the payment screen. AC8.
 *
 * Renders one of three states, driven entirely by `GuardRenewalPaymentOpening`
 * (via `resolveState()`): a real "Bayar Sekarang" online checkout when the
 * guard allows the opening AND `G-PAY-01` is open, the manual-coordination
 * card when the guard allows the opening but the gate is closed
 * (`isManualCoordinationRequired()`), or a fixed refusal when the guard
 * denies outright. The step is never removed when the gate is closed
 * (design-system.md §6.9).
 *
 * The online branch's own opening call (`payOnline()`) goes through
 * `App\Platform\Payment\Actions\OpenPaymentSession::authorizeRenewal()` —
 * the ONLY path that creates a `payment_sessions` row for a renewal. That
 * method re-evaluates `GuardRenewalPaymentOpening` itself (this component
 * never trusts its own earlier read as authorization) and additionally
 * refuses an already-`DIBAYAR` renewal before the guard ever runs — see that
 * method's class doc block. This component's `payOnline()` mirrors
 * `App\Livewire\Public\Booking\BookingWizard::openOnlinePayment()`'s
 * try/catch shape and redirect mechanism.
 *
 * ---------------------------------------------------------------------------
 * The re-click guard, the same session-remembering mechanism
 * App\Livewire\Public\Marketplace\Checkout::payOnline() uses
 * ---------------------------------------------------------------------------
 * `#[Url]` makes this screen bookmarkable, so a customer who clicks "Bayar
 * Sekarang", backs out of the hosted checkout, and returns (a stale tab, a
 * reload, the browser back button) can trigger `payOnline()` a second time.
 * Without a guard that unconditionally opens a SECOND real `PaymentSession`
 * and a second live provider charge for the same still-unpaid renewal - a
 * genuine double-charge reachable through ordinary user behaviour, not an
 * edge case. `payOnline()` therefore stores `session_id`/`link_url` in the
 * Laravel session, keyed `'renewal_online_payment.'.$renewal->getKey()` (the
 * renewal's real UUID primary key). On a later call: a stored TERMINAL
 * session (`Paid`/`Failed`/`Expired`) is never re-opened from here - the
 * manual-coordination card and the eventual webhook-driven state govern
 * recovery, exactly like `Checkout::payOnline()`'s own terminal branch; a
 * stored still-open session redirects back to the SAME `link_url` instead of
 * opening a new one; only when nothing valid is stored does a genuinely new
 * session open.
 *
 * ---------------------------------------------------------------------------
 * The guard's denial reason is a server-side diagnostic, never page copy
 * ---------------------------------------------------------------------------
 * `RenewalPaymentOpeningResult::denialReason()` names the specific condition
 * that failed — "Grave record not found", "Grave record is not available for
 * online renewal", "Payment amount does not match the quoted total". An
 * earlier revision printed that string straight onto an anonymous page, which
 * turned the screen into an oracle: anyone iterating UUIDs could tell a
 * renewal that does not exist from one whose grave is access-restricted from
 * one whose quote went stale, all without authenticating.
 *
 * This component therefore reduces the guard's outcome to a state flag and the
 * view prints one fixed Indonesian refusal with the support escape hatch. The
 * specific reason stays inside the guard, where an operator-facing surface can
 * still read it.
 *
 * ---------------------------------------------------------------------------
 * Access model
 * ---------------------------------------------------------------------------
 * Bearer-UUID: whoever holds the unguessable `renewals.id` may view this step.
 * That is deliberate for an anonymous journey with no accounts, and safe here
 * because this screen projects no grave record field at all — it names no
 * deceased, no block, and no dates. See `GuardRenewalPaymentOpening`'s doc
 * block for why this is an access model rather than a guard condition.
 */
final class RenewalPayment extends Component
{
    #[Url(as: 'perpanjangan', history: true)]
    public string $perpanjangan = '';

    /**
     * The ONLINE branch's fail-closed copy — `null` means no `payOnline()`
     * attempt has failed yet (or this is the first attempt). Every failure
     * lands here as fixed Indonesian copy, never a 500 and never an English
     * exception message (`AGENTS.md` §Observability). Mirrors
     * `BookingWizard::$onlinePaymentError`'s exact role, renamed to avoid
     * colliding with this component's own `errorMessage` (which, unlike
     * this property, blocks the ENTIRE screen — "renewal not found").
     */
    public ?string $checkoutError = null;

    public function render(): View
    {
        return view('livewire.public.renewal.payment', [
            ...$this->resolveState(),
            'paymentMode' => app(ModeResolver::class)->paymentMode(),
            'currentStep' => RenewalJourneyStep::PAYMENT,
            'stepLabels' => RenewalJourneyStep::labels(),
            'checkoutError' => $this->checkoutError,
            // ADR-0035 item 1's mitigation, mirrored from `BookingWizard`:
            // "unmissable payment-step labelling ... before any redirect to
            // the sandbox." See that component's `render()` for the same
            // computation and its own doc block for why this expression
            // (rather than a hardcoded provider name) stays correct the day
            // a real production provider is selected.
            'isSandboxPayment' => config('payment.default') === PaymentProviders::SUMOPOD_SANDBOX,
        ])->layout('layouts.app', [
            'title' => 'Pembayaran Perpanjangan Makam - Makam.co.id',
            'active' => 'perpanjangan',
        ]);
    }

    /**
     * The ONLINE branch's checkout-opening action, triggered by the Blade
     * view's "Bayar Sekarang" button — only rendered when `resolveState()`
     * already computed `paymentState === 'online'`. Mirrors
     * `BookingWizard::openOnlinePayment()`'s try/catch shape exactly (see
     * that method's doc block for the full submission-chain reasoning; this
     * renewal leg has no equivalent draft/order submission step, since a
     * `Renewal` row already exists by the time this screen is reachable).
     *
     * Exception coverage, confirmed by reading `OpenPaymentSession::
     * authorizeRenewal()`'s real committed code rather than assuming
     * `BookingWizard`'s catch list transfers unchanged:
     * - `PaymentSessionOpeningDeniedException` — `GuardRenewalPaymentOpening`
     *   denied, OR returned `isAllowed() && isManualCoordinationRequired()`
     *   (the gate closed between this screen's render and this click).
     * - `PaymentSessionOrderAlreadyPaidException` — `assertRenewalNotAlready
     *   Settled()` refused because `renewals.status` is already `DIBAYAR`.
     *   This is a GENUINELY DIFFERENT exception type from the denial above,
     *   not a second use of `PaymentSessionOpeningDeniedException` — the
     *   task brief's premise that one exception type covers both cases does
     *   not hold against the real code; `BookingWizard` catches the two
     *   separately for the same reason, with different copy.
     * - `PaymentCheckoutProviderException` / `PaymentCheckoutUnavailableException`
     *   — the provider call failed (shared path, not renewal-specific).
     * - Any other `Throwable` — reported and shown fixed fallback copy,
     *   exactly like `BookingWizard`'s own final catch.
     *
     * Redirect: a plain `$this->redirect($session->payment_link_url)` —
     * confirmed against `BookingWizard::openOnlinePayment()`'s real final
     * line, which does NOT pass `navigate: false` for an external URL.
     */
    public function payOnline(): void
    {
        $this->checkoutError = null;

        $renewal = Renewal::query()->find($this->perpanjangan);

        if (! $renewal instanceof Renewal) {
            $this->checkoutError = 'Data perpanjangan tidak ditemukan. Silakan muat ulang halaman ini.';

            return;
        }

        // The re-click guard — see this class's own doc block. Checked
        // BEFORE opening a genuinely new session, mirroring
        // `Checkout::payOnline()`'s structure exactly.
        $sessionKey = 'renewal_online_payment.'.$renewal->getKey();
        $stored = session($sessionKey);

        if (is_array($stored) && isset($stored['session_id'])) {
            $existing = PaymentSession::query()->find($stored['session_id']);

            if ($existing instanceof PaymentSession) {
                $state = SessionState::tryFrom((string) $existing->state);

                if ($state === SessionState::Paid || $state === SessionState::Failed || $state === SessionState::Expired) {
                    // A terminal session is never re-opened from here — the
                    // manual-coordination card / webhook-driven state
                    // governs; the manual path is the recovery route for
                    // Failed/Expired.
                    return;
                }

                $link = is_string($stored['link_url'] ?? null) ? $stored['link_url'] : '';

                if ($link !== '') {
                    $this->redirect($link);

                    return;
                }
            }

            session()->forget($sessionKey);
        }

        $quote = $renewal->quotes()->latest()->first();

        if ($quote === null) {
            $this->checkoutError = 'Pembayaran online belum dapat dibuka saat ini. Silakan hubungi petugas kami untuk koordinasi manual.';

            return;
        }

        try {
            $session = app(OpenPaymentSession::class)(new OpenPaymentSessionCommand(
                orderType: OrderType::Renewal,
                orderRef: $renewal->reference,
                // The current quote's total in integer minor units — the
                // amount `authorizeRenewal()`'s guard verifies, never a
                // client-supplied figure.
                amountMinor: $quote->amountAsMoney()->toMinorInt(),
                merchantRef: (string) app(SettingsService::class)
                    ->setting(SiteSetting::KEY_PAYMENT_MERCHANT_REF, (string) config('payment.merchant_ref', '')),
                successReturnUrl: route('payments.return'),
                cancelReturnUrl: route('payments.cancel'),
            ));
        } catch (PaymentSessionOpeningDeniedException) {
            // The guard denied, or allowed-but-gate-closed. Fixed Indonesian
            // copy — the guard's own denial reason is internal English and
            // stays off-screen (this component's own class doc block).
            $this->checkoutError = 'Pembayaran online belum dapat dibuka saat ini. Silakan hubungi petugas kami untuk koordinasi manual.';

            return;
        } catch (PaymentSessionOrderAlreadyPaidException) {
            // The renewal is already DIBAYAR; a second session would only
            // allow a second charge. Honest copy instead of the generic
            // denial — and no `report()`: this is a normal customer action
            // (a resumed/duplicate tab), not an error.
            $this->checkoutError = 'Perpanjangan ini telah dibayar dan tidak perlu dibayar lagi.';

            return;
        } catch (PaymentCheckoutProviderException|PaymentCheckoutUnavailableException) {
            $this->checkoutError = 'Layanan pembayaran online sedang tidak tersedia. Silakan coba lagi atau hubungi dukungan.';

            return;
        } catch (Throwable $e) {
            report($e);
            $this->checkoutError = 'Pembayaran online belum dapat diproses. Silakan hubungi dukungan.';

            return;
        }

        session([
            $sessionKey => [
                'session_id' => $session->id,
                'link_url' => $session->payment_link_url,
            ],
        ]);

        $this->redirect($session->payment_link_url);
    }

    /**
     * @return array{errorMessage: string, paymentState: string}
     */
    private function resolveState(): array
    {
        $notFound = [
            'errorMessage' => 'Data perpanjangan tidak ditemukan.',
            'paymentState' => 'none',
        ];

        if ($this->perpanjangan === '') {
            return $notFound;
        }

        $renewal = Renewal::query()->find($this->perpanjangan);

        if (! $renewal instanceof Renewal) {
            return $notFound;
        }

        $quote = $renewal->quotes()->latest()->first();

        if ($quote === null) {
            return ['errorMessage' => '', 'paymentState' => 'denied'];
        }

        $result = app(GuardRenewalPaymentOpening::class)($renewal, $quote->amountAsMoney());

        if (! $result->isAllowed()) {
            return ['errorMessage' => '', 'paymentState' => 'denied'];
        }

        return [
            'errorMessage' => '',
            'paymentState' => $result->isManualCoordinationRequired() ? 'manual' : 'online',
        ];
    }
}
