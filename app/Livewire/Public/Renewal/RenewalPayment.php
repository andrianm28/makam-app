<?php

declare(strict_types=1);

namespace App\Livewire\Public\Renewal;

use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\GraveRecordProjection;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\GuardRenewalPaymentOpening;
use App\Domain\Renewal\Actions\OpenRenewal;
use App\Domain\Renewal\Actions\QuoteRenewal;
use App\Domain\Renewal\Exceptions\DuplicateRenewalPeriodException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalGraveSelection;
use App\Domain\Renewal\RenewalJourneyStep;
use App\Domain\Renewal\RenewalQuoteDraft;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\GraveSearchMode;
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
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * `/perpanjangan/pembayaran` — Screen 2 "Biaya & Bayar" of the consolidated
 * renewal journey (`docs/superpowers/specs/2026-08-29-wizard-screen-
 * consolidation-design.md`). Folds journey steps 4-5 (Biaya, Pembayaran)
 * into one screen: the fee section renders first, and only once the
 * visitor's explicit "Terima Tarif" click accepts it does the payment
 * section reveal — on the SAME screen, no navigation (Implementation
 * Decision 4 in the plan this class was built from).
 *
 * This class is the MERGE of the former `RenewalFee` (step 4, formerly its
 * own route `/perpanjangan/biaya`) and `RenewalPayment` (step 5). Every
 * property, guard call and payment mechanic below is carried over from
 * whichever of the two owned it, UNCHANGED in behavior — see each member's
 * own doc block for its original screen if that history matters.
 *
 * ---------------------------------------------------------------------------
 * Which section renders is a THREE-WAY state, resolved fresh every render
 * ---------------------------------------------------------------------------
 *  1. `$perpanjangan !== ''` (a real `Renewal` exists — either a genuine
 *     bookmark arrival, or one this same instance just created via
 *     `terimaDanLanjutkan()`) → the PAYMENT section, via the unchanged
 *     `resolveState()`/`GuardRenewalPaymentOpening` path `RenewalPayment`
 *     has always used.
 *  2. No `$perpanjangan` but `RenewalGraveSelection::current() !== null`
 *     (Screen 1 just handed off a selection) → the FEE section, via the
 *     unchanged `QuoteRenewal`/`GraveRecordProjection` path `RenewalFee`
 *     has always used.
 *  3. Neither → "tidak ditemukan", exactly `RenewalPayment`'s original
 *     no-parameter case.
 *
 * `terimaDanLanjutkan()` — the only bridge between 2 and 1 — fires ONLY from
 * an explicit click and, exactly as `RenewalFee::terimaDanLanjutkan()`
 * always has, calls `OpenRenewal` (the only write in the entire renewal
 * journey) and nothing else. It never redirects: it sets `$this->
 * perpanjangan` directly, which `#[Url(history: true)]` reflects into the
 * browser URL, and the NEXT render finds state 1 above — the same
 * `resolveState()` a genuine bookmark arrival already uses.
 */
final class RenewalPayment extends Component
{
    #[Url(as: 'perpanjangan', history: true)]
    public string $perpanjangan = '';

    /**
     * Set only by `terimaDanLanjutkan()`, to report a handled failure of the
     * acceptance itself (formerly `RenewalFee::$actionMessage`).
     */
    #[Locked]
    public string $actionMessage = '';

    public ?string $checkoutError = null;

    public function render(): View
    {
        $state = $this->resolveState();

        return view('livewire.public.renewal.payment', [
            ...$state,
            'paymentMode' => app(ModeResolver::class)->paymentMode(),
            'currentStep' => $state['mode'] === 'fee' ? RenewalJourneyStep::FEE : RenewalJourneyStep::PAYMENT,
            'stepLabels' => RenewalJourneyStep::labels(),
            'checkoutError' => $this->checkoutError,
            'isSandboxPayment' => config('payment.default') === PaymentProviders::SUMOPOD_SANDBOX,
        ])->layout('layouts.app', [
            'title' => $state['mode'] === 'fee'
                ? 'Biaya Perpanjangan Makam - Makam.co.id'
                : 'Pembayaran Perpanjangan Makam - Makam.co.id',
            'active' => 'perpanjangan',
        ]);
    }

    /**
     * The fee section's acceptance — formerly `RenewalFee::
     * terimaDanLanjutkan()`. Re-resolves the grave and re-quotes
     * server-side rather than trusting any value carried on the component,
     * exactly as before. The only change from the original: no redirect —
     * see this class's own doc block, "Implementation Decision 4".
     */
    public function terimaDanLanjutkan(): mixed
    {
        $graveId = RenewalGraveSelection::current();

        if ($graveId === null) {
            return null;
        }

        if (app(ModeResolver::class)->graveSearchMode() === GraveSearchMode::ManualAssistance) {
            return null;
        }

        $grave = Str::isUuid($graveId) ? GraveRecord::query()->find($graveId) : null;

        if (! $grave instanceof GraveRecord) {
            return null;
        }

        if ((string) $grave->access_mode !== GraveRecordAccessMode::OPEN) {
            return null;
        }

        try {
            $renewal = app(OpenRenewal::class)($grave);
        } catch (DuplicateRenewalPeriodException) {
            $this->actionMessage = 'Perpanjangan untuk periode ini sudah tercatat. Silakan hubungi petugas kami untuk memeriksa statusnya.';

            return null;
        } catch (\InvalidArgumentException) {
            $this->actionMessage = 'Tarif tidak tersedia. Silakan hubungi petugas kami.';

            return null;
        }

        RenewalGraveSelection::forget();
        $this->perpanjangan = $renewal->id;

        return null;
    }

    /**
     * The payment section's ONLINE branch — unchanged from `RenewalPayment::
     * payOnline()`. See that method's original doc block (carried over
     * verbatim in spirit) for the full re-click-guard and exception-mapping
     * reasoning; nothing about it changes with this merge.
     */
    public function payOnline(): void
    {
        $this->checkoutError = null;

        $renewal = Renewal::query()->find($this->perpanjangan);

        if (! $renewal instanceof Renewal) {
            $this->checkoutError = 'Data perpanjangan tidak ditemukan. Silakan muat ulang halaman ini.';

            return;
        }

        $sessionKey = 'renewal_online_payment.'.$renewal->getKey();
        $stored = session($sessionKey);

        if (is_array($stored) && isset($stored['session_id'])) {
            $existing = PaymentSession::query()->find($stored['session_id']);

            if ($existing instanceof PaymentSession) {
                $state = SessionState::tryFrom((string) $existing->state);

                if ($state === SessionState::Paid || $state === SessionState::Failed || $state === SessionState::Expired) {
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
                amountMinor: $quote->amountAsMoney()->toMinorInt(),
                merchantRef: (string) app(SettingsService::class)
                    ->setting(SiteSetting::KEY_PAYMENT_MERCHANT_REF, (string) config('payment.merchant_ref', '')),
                successReturnUrl: route('payments.return'),
                cancelReturnUrl: route('payments.cancel'),
            ));
        } catch (PaymentSessionOpeningDeniedException) {
            $this->checkoutError = 'Pembayaran online belum dapat dibuka saat ini. Silakan hubungi petugas kami untuk koordinasi manual.';

            return;
        } catch (PaymentSessionOrderAlreadyPaidException) {
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
     * Resolves this render's screen state — mode `'not_found'` (neither a
     * real renewal nor a pending selection), `'fee'` (a selection is
     * pending, formerly `RenewalFee::resolveState()`), or the three
     * payment-section outcomes `'denied'|'manual'|'online'` (a real renewal
     * exists, formerly `RenewalPayment::resolveState()`) — driven fresh from
     * the database on every render, exactly as before; the guard is never
     * trusted from an earlier render.
     *
     * @return array{mode: string, errorMessage: string, privacyRestricted: bool, quoteUnavailable: bool, graveView: ?GraveRecordProjection, quote: ?RenewalQuoteDraft, paymentState: string}
     */
    private function resolveState(): array
    {
        $empty = [
            'mode' => 'not_found',
            'errorMessage' => 'Data perpanjangan tidak ditemukan.',
            'privacyRestricted' => false,
            'quoteUnavailable' => false,
            'graveView' => null,
            'quote' => null,
            'paymentState' => 'none',
        ];

        if ($this->perpanjangan !== '') {
            return [...$empty, ...$this->resolvePaymentState()];
        }

        $graveId = RenewalGraveSelection::current();

        if ($graveId === null) {
            return $empty;
        }

        return [...$empty, ...$this->resolveFeeState($graveId)];
    }

    /**
     * @return array{mode: string, errorMessage: string, privacyRestricted: bool, quoteUnavailable: bool, graveView: ?GraveRecordProjection, quote: ?RenewalQuoteDraft}
     */
    private function resolveFeeState(string $graveId): array
    {
        $fee = [
            'mode' => 'fee',
            'errorMessage' => '',
            'privacyRestricted' => false,
            'quoteUnavailable' => false,
            'graveView' => null,
            'quote' => null,
        ];

        if (app(ModeResolver::class)->graveSearchMode() === GraveSearchMode::ManualAssistance) {
            return [...$fee, 'errorMessage' => 'Pencarian data makam secara online belum tersedia. Silakan hubungi petugas kami.'];
        }

        $grave = Str::isUuid($graveId)
            ? GraveRecord::query()->with('cemetery')->find($graveId)
            : null;

        if (! $grave instanceof GraveRecord) {
            return [...$fee, 'errorMessage' => 'Data makam tidak ditemukan.'];
        }

        $graveView = GraveRecordProjection::fromRecord($grave, $grave->cemetery?->name);

        if ($graveView->isRestricted()) {
            return [...$fee, 'graveView' => $graveView, 'privacyRestricted' => true];
        }

        if ($this->actionMessage !== '') {
            return [...$fee, 'graveView' => $graveView, 'errorMessage' => $this->actionMessage];
        }

        try {
            $quote = app(QuoteRenewal::class)($grave);
        } catch (\InvalidArgumentException) {
            return [...$fee, 'graveView' => $graveView, 'quoteUnavailable' => true];
        }

        return [...$fee, 'graveView' => $graveView, 'quote' => $quote];
    }

    /**
     * @return array{mode: string, errorMessage: string, paymentState: string}
     */
    private function resolvePaymentState(): array
    {
        $notFound = [
            'mode' => 'not_found',
            'errorMessage' => 'Data perpanjangan tidak ditemukan.',
            'paymentState' => 'none',
        ];

        $renewal = Renewal::query()->find($this->perpanjangan);

        if (! $renewal instanceof Renewal) {
            return $notFound;
        }

        $quote = $renewal->quotes()->latest()->first();

        if ($quote === null) {
            return ['mode' => 'payment', 'errorMessage' => '', 'paymentState' => 'denied'];
        }

        $result = app(GuardRenewalPaymentOpening::class)($renewal, $quote->amountAsMoney());

        if (! $result->isAllowed()) {
            return ['mode' => 'payment', 'errorMessage' => '', 'paymentState' => 'denied'];
        }

        return [
            'mode' => 'payment',
            'errorMessage' => '',
            'paymentState' => $result->isManualCoordinationRequired() ? 'manual' : 'online',
        ];
    }
}
