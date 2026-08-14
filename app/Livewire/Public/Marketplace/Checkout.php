<?php

declare(strict_types=1);

namespace App\Livewire\Public\Marketplace;

use App\Domain\Marketplace\Actions\PlaceMarketplaceOrder;
use App\Domain\Marketplace\Exceptions\BadanUsahaNotConfiguredException;
use App\Domain\Marketplace\Exceptions\CartPricingChangedException;
use App\Domain\Marketplace\Models\Cart;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Platform\Audit\AuditSource;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
use App\Platform\Payment\SubmitManualPayment;
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
 * `GuardPaymentSession`'s condition 1 evaluates (`ProductGateOpen`), and the
 * same source the booking wizard and renewal screens render their §6.9
 * banners from. `GuardPaymentSession` itself is not invoked: it requires an
 * order-workflow `Order` and writes booking-domain `payment_intents` rows
 * per evaluation, so calling it from a marketplace checkout would fabricate
 * wrong-domain records (cross-lane ownership: L7's guard is consumed, never
 * modified). The screen starts working the day the gate opens without any
 * code change — the mode flips, the banner disappears.
 *
 * ---------------------------------------------------------------------------
 * Manual fallback: a CONSUMER of L7's path, not a modifier
 * ---------------------------------------------------------------------------
 * `submitManualProof()` submits through `SubmitManualPayment`, which writes
 * exactly one `payment_verifications` row (`reference` = the marketplace
 * order number) plus its audit event — it deliberately does not touch
 * `marketplace_orders.payment_state` (its own doc block: it writes nothing
 * beyond its own row). Moving the order from `BELUM_DIBAYAR` to
 * `MENUNGGU_VERIFIKASI`/`DIBAYAR` is the verifier lane's job once an admin
 * accepts the proof; this screen only records the customer's submission.
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

    public string $idempotencyKey;

    /** @var list<array{id: int, area_code: string, area_label: string, delivery_fee_minor: int}> */
    public array $serviceAreas = [];

    public bool $onlinePaymentAllowed = false;

    public bool $orderPlaced = false;

    public ?string $placedOrderNumber = null;

    public ?string $manualSubmissionError = null;

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

        try {
            app(SubmitManualPayment::class)->submit(
                reference: $this->placedOrderNumber,
                paymentMethod: 'MANUAL',
                paymentReference: trim($this->manualPaymentReference),
                instructions: null,
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
