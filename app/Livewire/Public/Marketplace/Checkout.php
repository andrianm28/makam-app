<?php

declare(strict_types=1);

namespace App\Livewire\Public\Marketplace;

use App\Domain\Marketplace\Actions\PlaceMarketplaceOrder;
use App\Domain\Marketplace\Exceptions\BadanUsahaNotConfiguredException;
use App\Domain\Marketplace\Exceptions\CartPricingChangedException;
use App\Domain\Marketplace\Models\Cart;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Platform\Audit\AuditSource;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
use App\Platform\FinancialLedger\Money;
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
use App\Platform\Payment\SubmitManualPayment;
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Throwable;

/**
 * `/marketplace/checkout` — PUB-023, the checkout screen.
 *
 * ---------------------------------------------------------------------------
 * The online branch is honestly gate-closed (§6.9)
 * ---------------------------------------------------------------------------
 * The online option renders a gate-closed banner explaining that online
 * payment is unavailable and pointing at the manual path. The gate is read
 * through `ModeResolver::paymentMode()` — the SAME server-resolved authority
 * `App\Domain\Marketplace\Actions\GuardMarketplacePaymentOpening`'s own
 * first condition re-checks server-side at submit time (a mount-time read
 * alone would be a TOCTOU gap if the gate closed between page load and
 * click). The screen starts working the day the gate opens without any
 * code change — the mode flips, the banner disappears.
 *
 * ---------------------------------------------------------------------------
 * Manual fallback: a CONSUMER of L7's path, not a modifier
 * ---------------------------------------------------------------------------
 * `submitManualProof()` submits through `SubmitManualPayment`, which writes
 * exactly one `payment_verifications` row (`reference` = the marketplace
 * order number, `order_id` = that order's real id since PAY-02, plus the
 * customer's own stated `manualPaymentAmount` converted through
 * `Money::fromDecimal()`) plus its audit event — it deliberately does not
 * touch `marketplace_orders.payment_state` itself (its own doc block: it
 * writes nothing beyond its own row). Moving the order to `DIBAYAR` is the
 * verifier lane's job (`App\Platform\Payment\VerifyManualPayment`, since
 * PAY-02, calls `App\Domain\Marketplace\Actions\MarkMarketplaceOrderPaid`
 * on approval) once an admin accepts the proof; this screen only records
 * the customer's submission.
 *
 * ---------------------------------------------------------------------------
 * Duplicate submit is safe (§6.6); failures never leak internals
 * ---------------------------------------------------------------------------
 * `$idempotencyKey` is minted once at mount and reused, so a double submit
 * returns the same order. `CartPricingChangedException` redirects back to
 * the cart (never silently recharges). `BadanUsahaNotConfiguredException`
 * renders a fixed Indonesian message — the config key and the exception
 * message are internal details and never reach the customer
 * (`AGENTS.md` §Observability).
 */
final class Checkout extends Component
{
    public string $recipientName = '';

    public string $recipientPhone = '';

    public string $recipientEmail = '';

    public string $selectedAreaCode = '';

    public ?string $scheduledFor = null;

    public string $manualPaymentReference = '';

    /**
     * The customer's OWN stated transfer amount, as a decimal-rupiah string
     * from the form — see `SubmitManualPayment`'s own doc block for why this
     * is not defaulted to the order total. Converted through
     * `Money::fromDecimal()` (the platform's one read seam for a decimal
     * amount, `config('money.currency')`'s convention) at submit time, never
     * before — the raw string is what §6.3 requires be kept on screen if
     * validation fails.
     */
    public string $manualPaymentAmount = '';

    public string $idempotencyKey;

    /** @var list<array{id: int, area_code: string, area_label: string, delivery_fee_minor: int}> */
    public array $serviceAreas = [];

    public bool $onlinePaymentAllowed = false;

    public bool $orderPlaced = false;

    public ?string $placedOrderNumber = null;

    public ?string $manualSubmissionError = null;

    public ?string $onlinePaymentError = null;

    public function mount(): void
    {
        $this->idempotencyKey = (string) Str::uuid();

        $cart = $this->cart();
        $vendorId = $cart->vendor_id;

        $this->serviceAreas = $vendorId !== null
            ? ServiceArea::query()->where('vendor_id', $vendorId)->active()
                ->get(['id', 'area_code', 'area_label', 'delivery_fee_minor'])
                ->map(fn (ServiceArea $area): array => [
                    'id' => $area->id,
                    'area_code' => $area->area_code,
                    'area_label' => $area->area_label,
                    'delivery_fee_minor' => (int) $area->delivery_fee_minor,
                ])
                ->all()
            : [];

        // A bare `<select>` with no property-matching `selected` option
        // still visually shows its first `<option>` (native browser
        // behaviour) while Livewire's own `$selectedAreaCode` stays at its
        // `''` default — the two silently disagree until the user touches
        // the field. That let a customer submit with the area FIELD
        // showing their intended choice while the bound value was really
        // empty, surfacing as a "wajib diisi" error next to a
        // visibly-filled dropdown (2 Sep 2026 UAT finding). Seeding the
        // property to the first option keeps what's shown and what's
        // bound in agreement from first render, same discipline a
        // `wire:model`-bound `<select>` needs whenever it has no blank
        // placeholder option.
        $this->selectedAreaCode = $this->serviceAreas[0]['area_code'] ?? '';

        // The §6.9 gate, read from the server authority — see class doc
        // block for why this is the guard's condition-1 source, not a
        // hardcoded boolean.
        $this->onlinePaymentAllowed = app(ModeResolver::class)->paymentMode() === PaymentMode::Online;
    }

    public function placeOrder(): void
    {
        if ($this->orderPlaced) {
            return;
        }

        $cart = $this->cart();

        $areaCodes = collect($this->serviceAreas)->pluck('area_code')->all();

        $validated = Validator::make(
            [
                'recipientName' => $this->recipientName,
                'recipientPhone' => $this->recipientPhone,
                'recipientEmail' => $this->recipientEmail,
                'selectedAreaCode' => $this->selectedAreaCode,
                'scheduledFor' => $this->scheduledFor,
            ],
            [
                'recipientName' => ['required', 'string', 'max:255'],
                'recipientPhone' => ['required', 'string', 'max:32'],
                'recipientEmail' => ['required', 'email', 'max:255'],
                'selectedAreaCode' => ['required', Rule::in($areaCodes)],
                'scheduledFor' => ['nullable', 'date'],
            ],
        )->validate();

        $area = ServiceArea::query()
            ->where('vendor_id', $cart->vendor_id)
            ->where('area_code', $validated['selectedAreaCode'])
            ->active()
            ->first();

        if (! $area instanceof ServiceArea) {
            // The Rule::in() above already refuses an unknown code; this is
            // the belt-and-braces branch so a stale area list cannot slip a
            // fee past the screen.
            $this->addError('selectedAreaCode', 'Pilih area layanan yang tersedia.');

            return;
        }

        try {
            $order = (new PlaceMarketplaceOrder)->handle(
                cart: $cart,
                customerRef: auth()->check() ? (string) auth()->id() : session()->getId(),
                area: $area,
                idempotencyKey: $this->idempotencyKey,
                recipientName: $validated['recipientName'],
                recipientPhone: $validated['recipientPhone'],
                recipientEmail: $validated['recipientEmail'],
                scheduledFor: $validated['scheduledFor'],
            );
        } catch (CartPricingChangedException) {
            $this->redirectRoute('marketplace.cart');

            return;
        } catch (BadanUsahaNotConfiguredException) {
            // Deliberately NOT `$e->getMessage()`: the config key is an
            // internal detail that must never reach a customer screen.
            $this->addError('checkout', 'Checkout belum dapat diproses. Silakan hubungi dukungan.');

            return;
        } catch (Throwable $e) {
            report($e);
            $this->addError('checkout', 'Checkout belum dapat diproses. Silakan hubungi dukungan.');

            return;
        }

        $this->orderPlaced = true;
        $this->placedOrderNumber = $order->order_number;
    }

    public function submitManualProof(): void
    {
        if (! $this->orderPlaced || $this->placedOrderNumber === null) {
            return;
        }

        $this->manualSubmissionError = null;

        $validated = Validator::make(
            ['manualPaymentAmount' => $this->manualPaymentAmount],
            ['manualPaymentAmount' => ['required', 'numeric', 'gt:0']],
            [],
            ['manualPaymentAmount' => 'jumlah transfer'],
        )->validate();

        try {
            $amountMinor = Money::fromDecimal((string) $validated['manualPaymentAmount']);
        } catch (Throwable $e) {
            report($e);
            $this->manualSubmissionError = 'Jumlah transfer tidak valid. Masukkan angka yang benar.';

            return;
        }

        try {
            app(SubmitManualPayment::class)->submit(
                reference: $this->placedOrderNumber,
                paymentMethod: 'MANUAL',
                paymentReference: trim($this->manualPaymentReference),
                instructions: null,
                amountMinor: $amountMinor,
                currency: (string) config('money.currency'),
                proofFile: null,
                actorRef: auth()->id(),
                actorRole: auth()->check() ? 'customer' : 'guest',
                source: AuditSource::Api,
            );
        } catch (Throwable $e) {
            report($e);
            $this->manualSubmissionError = 'Bukti transfer tidak dapat dikirim. Silakan coba lagi atau hubungi dukungan.';
        }
    }

    /**
     * The online branch (only rendered when `G-PAY-01` is open): open a real
     * session for the placed marketplace order through
     * `GuardMarketplacePaymentOpening` and redirect to the hosted checkout.
     *
     * Every failure lands honest fail-closed copy with the manual path (and
     * the support escape) still on screen — never a 500, never a fabricated
     * session. Catch order and copy mirror `BookingWizard::
     * openOnlinePayment()`'s own established pattern — including the
     * exactly-once re-click guard below: a stored non-terminal session is
     * re-pointed at rather than re-opened, since neither this guard nor
     * `payment_sessions` (which carries no order reference — see
     * `SessionState`'s doc block) stops a second `OpenPaymentSession` call
     * for the same still-`BELUM_DIBAYAR` order from creating a second
     * session and a second provider charge.
     */
    public function payOnline(): void
    {
        if (! $this->onlinePaymentAllowed) {
            return;
        }

        if (! $this->orderPlaced || $this->placedOrderNumber === null) {
            return;
        }

        $this->onlinePaymentError = null;

        $sessionKey = 'marketplace_online_payment.'.$this->placedOrderNumber;
        $stored = session($sessionKey);

        if (is_array($stored) && isset($stored['session_id'])) {
            $existing = PaymentSession::query()->find($stored['session_id']);

            if ($existing instanceof PaymentSession) {
                $state = SessionState::tryFrom((string) $existing->state);

                if ($state === SessionState::Paid || $state === SessionState::Failed || $state === SessionState::Expired) {
                    // A terminal session is never re-opened from here — the
                    // order-tracking screen governs; the manual path is the
                    // recovery route for Failed/Expired.
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

        $order = MarketplaceOrder::query()->where('order_number', $this->placedOrderNumber)->first();

        if (! $order instanceof MarketplaceOrder) {
            $this->onlinePaymentError = 'Pembayaran online belum dapat diproses. Silakan gunakan transfer manual atau hubungi dukungan.';

            return;
        }

        try {
            $session = app(OpenPaymentSession::class)(new OpenPaymentSessionCommand(
                orderType: OrderType::Marketplace,
                orderRef: $order->order_number,
                amountMinor: $order->total()->toMinorInt(),
                merchantRef: (string) app(SettingsService::class)
                    ->setting(SiteSetting::KEY_PAYMENT_MERCHANT_REF, (string) config('payment.merchant_ref', '')),
                successReturnUrl: route('payments.return'),
                cancelReturnUrl: route('payments.cancel'),
            ));
        } catch (PaymentSessionOpeningDeniedException) {
            // The marketplace guard denied. Fixed Indonesian copy — the
            // guard's own messages are internal English and stay off-screen.
            $this->onlinePaymentError = 'Pembayaran online belum dapat dibuka saat ini. Gunakan transfer manual atau hubungi dukungan.';

            return;
        } catch (PaymentSessionOrderAlreadyPaidException) {
            // The order is already paid; a second session would only allow a
            // second charge. Honest copy instead of the generic denial — and
            // no `report()`: this is a normal customer action, not an error.
            $this->onlinePaymentError = 'Pesanan ini telah dibayar dan tidak perlu dibayar lagi.';

            return;
        } catch (PaymentCheckoutProviderException|PaymentCheckoutUnavailableException) {
            $this->onlinePaymentError = 'Layanan pembayaran online sedang tidak tersedia. Silakan coba lagi atau gunakan transfer manual.';

            return;
        } catch (Throwable $e) {
            report($e);
            $this->onlinePaymentError = 'Pembayaran online belum dapat diproses. Silakan gunakan transfer manual atau hubungi dukungan.';

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

    public function render(): View
    {
        $cart = $this->cart();
        $items = $cart->items()->with(['listing.product', 'listing.vendor'])->get();

        return view('livewire.public.marketplace.checkout', [
            'cart' => $cart,
            'items' => $items,
            'hasStalePricing' => $cart->hasStalePricing(),
            'paymentMode' => app(ModeResolver::class)->paymentMode(),
            'deliveryFeeByCode' => collect($this->serviceAreas)->keyBy('area_code'),
            // ADR-0035 item 1's mitigation — see `wizard.blade.php`'s own
            // `$isSandboxPayment` for the identical booking-side warning.
            'isSandboxPayment' => config('payment.default') === PaymentProviders::SUMOPOD_SANDBOX,
        ])->layout('layouts.app', [
            'title' => 'Checkout - Layanan Pemakaman - Makam.co.id',
            'active' => 'layanan',
        ]);
    }

    private function cart(): Cart
    {
        $authenticated = auth()->check();

        return Cart::query()->firstOrCreate([
            'customer_ref' => $authenticated ? (string) auth()->id() : null,
            'session_ref' => $authenticated ? null : session()->getId(),
        ]);
    }
}
