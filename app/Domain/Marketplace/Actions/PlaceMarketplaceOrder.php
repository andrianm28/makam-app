<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\Exceptions\BadanUsahaNotConfiguredException;
use App\Domain\Marketplace\Exceptions\CartPricingChangedException;
use App\Domain\Marketplace\Models\Cart;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Domain\Marketplace\PaymentState;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Platform\FinancialLedger\Actions\VendorPayable;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\VendorPayableAssessmentTrigger;
use App\Platform\FinancialLedger\VendorPayableEligibility;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Turns a cart into an order, its vendor allocation, and the vendor's
 * payable.
 *
 * The payable's `(source_type, source_id)` is `('marketplace_order', $order->id)`.
 * `vendor_payables` carries `UNIQUE(vendor_id, source_type, source_id)`, so a
 * replayed checkout cannot create a second debt even if the application-level
 * idempotency check is bypassed.
 *
 * ---------------------------------------------------------------------------
 * Ruling 2 (14 Aug 2026): the checkout payable is an UNATTENDED assessment
 * ---------------------------------------------------------------------------
 * `VendorPayable::assess()` requires `ActorContext` and a trigger. A
 * customer's checkout is nobody's human decision: the customer is not an
 * operator, no `finance` role or vendor grant exists on the request, and the
 * payable is opened HELD (not eligible — the vendor has fulfilled nothing).
 * The call therefore names `VendorPayableAssessmentTrigger::UnattendedAssessment`
 * explicitly and the actor context is resolved from the container (the
 * scoped `ActorContext` binding; a guest request resolves `ActorContext::guest()`,
 * which `FinanceVendorPayableAuthorizer::authorizeUnattended()` accepts and
 * which makes the audit row's `actorRole: 'system'` true by construction).
 * If a human IS present on the context, the authorizer refuses — the Action
 * does not bypass it.
 *
 * ---------------------------------------------------------------------------
 * Allocation is a loop over distinct vendors; one `vendor_orders` row per
 * listing line (Ruling 1 / L10's flat schema)
 * ---------------------------------------------------------------------------
 * Trunk's `vendor_orders` carries a singular `listing_id` and NOT NULL
 * `customer_name`/`customer_phone`/`customer_email`; `vendor_order_items`
 * must never exist. So each cart line produces its own `vendor_orders` row
 * with the recipient identity copied from checkout input. The cart's vendor
 * lock and the `vendor_orders_single_vendor` constraint trigger hold every
 * row of one marketplace order to ONE vendor (requirement 4). The loop shape
 * exists so multi-vendor is later a constraint change rather than a rewrite,
 * per design.md — it does NOT mean multi-vendor is supported. Requirement 14
 * forbids that until order splitting, partial cancellation/refund,
 * fee/tax allocation, dispute handling, and reconciliation all exist.
 */
final class PlaceMarketplaceOrder
{
    public function handle(
        Cart $cart,
        string $customerRef,
        ServiceArea $area,
        string $idempotencyKey,
        string $recipientName,
        string $recipientPhone,
        string $recipientEmail,
        ?string $scheduledFor = null,
        ?CarbonImmutable $now = null,
    ): MarketplaceOrder {
        $existing = MarketplaceOrder::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $items = $cart->items()->with('listing')->get();
        if ($items->isEmpty()) {
            throw new InvalidArgumentException('Cannot place an order from an empty cart.');
        }

        if ($cart->hasStalePricing()) {
            throw new CartPricingChangedException(
                "A cart line's price changed since it was added. The customer must reconfirm before checkout."
            );
        }

        // P2 `admin-data-management` review fix: the entity ref resolves
        // through SettingsService's config → env → `site_settings` → default
        // precedence so an operator-managed `marketplace_badan_usaha_ref`
        // row participates without an environment change; the config default
        // keeps the pre-existing behaviour identical while no DB row exists.
        $entityRef = (string) app(SettingsService::class)
            ->setting(SiteSetting::KEY_MARKETPLACE_BADAN_USAHA_REF, (string) config('marketplace.badan_usaha_ref', ''));
        if (trim($entityRef) === '') {
            throw new BadanUsahaNotConfiguredException(
                'Marketplace checkout requires `marketplace.badan_usaha_ref` to be configured. '
                .'Refusing to place an order without an explicit badan usaha.'
            );
        }

        $now ??= CarbonImmutable::now();

        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += (int) $item->unit_price_minor * (int) $item->quantity;
        }
        $deliveryFee = (int) $area->delivery_fee_minor;

        return DB::transaction(function () use (
            $cart,
            $items,
            $customerRef,
            $idempotencyKey,
            $entityRef,
            $subtotal,
            $deliveryFee,
            $recipientName,
            $recipientPhone,
            $recipientEmail,
            $scheduledFor,
            $now,
        ): MarketplaceOrder {
            $order = MarketplaceOrder::create([
                'order_number' => 'MKT-'.strtoupper(Str::random(10)),
                'customer_ref' => $customerRef,
                'entity_ref' => $entityRef,
                'vendor_id' => $cart->vendor_id,
                'subtotal_minor' => $subtotal,
                'delivery_fee_minor' => $deliveryFee,
                'total_minor' => $subtotal + $deliveryFee,
                'payment_state' => PaymentState::BELUM_DIBAYAR,
                'scheduled_for' => $scheduledFor,
                'idempotency_key' => $idempotencyKey,
                'placed_at' => $now,
            ]);

            $itemsByVendor = [];
            foreach ($items as $item) {
                $orderItem = $order->items()->create([
                    'vendor_listing_id' => $item->vendor_listing_id,
                    'product_id' => $item->listing->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => (int) $item->quantity,
                    'unit_price_minor' => (int) $item->unit_price_minor,
                    'line_total_minor' => (int) $item->unit_price_minor * (int) $item->quantity,
                    'price_version' => (int) $item->price_version,
                ]);

                $itemsByVendor[$item->listing->vendor_id][] = $orderItem;
            }

            foreach ($itemsByVendor as $vendorId => $vendorItems) {
                $vendorSubtotal = 0;
                foreach ($vendorItems as $vendorItem) {
                    // One flat `vendor_orders` row per listing line (Ruling 1).
                    // `uuid` is L10's required, separately-stored identifier
                    // column (the bigint `id` is the PK).
                    $order->vendorOrders()->create([
                        'uuid' => (string) Str::uuid(),
                        'vendor_id' => $vendorId,
                        'listing_id' => $vendorItem->vendor_listing_id,
                        'customer_name' => $recipientName,
                        'customer_phone' => $recipientPhone,
                        'customer_email' => $recipientEmail,
                        'status' => VendorProcessingStatus::MENUNGGU_VENDOR,
                    ]);
                    $vendorSubtotal += (int) $vendorItem->line_total_minor;
                }

                $this->assessPayable((string) $vendorId, $entityRef, $order->id, $vendorSubtotal, $now);
            }

            $cart->items()->delete();
            $cart->update(['vendor_id' => null]);

            return $order->fresh();
        });
    }

    /**
     * The ONE ledger call site: `VendorPayable::assess()` with the
     * unattended trigger, a held eligibility, and the same `$now` the order
     * was placed at (Ruling 2 — see the class doc block).
     */
    private function assessPayable(
        string $vendorId,
        string $entityRef,
        string $orderId,
        int $amountMinor,
        CarbonImmutable $now,
    ): void {
        // Resolved here, not constructor-injected, so the Action stays
        // constructible with zero arguments (the plan's call shape) while
        // still reading the container's per-request scoped `ActorContext` —
        // the same source `GuardPaymentSession` and `ManualPayout` read.
        $actorContext = app(ActorContext::class);

        (new VendorPayable(actorContext: $actorContext))->assess(
            vendorId: $vendorId,
            entityRef: $entityRef,
            sourceType: 'marketplace_order',
            sourceId: $orderId,
            amount: new Money($amountMinor),
            eligibility: new VendorPayableEligibility(
                orderPaid: false,
                fulfilmentEvidenceAccepted: false,
                disputeWindowEndsAt: null,
            ),
            trigger: VendorPayableAssessmentTrigger::UnattendedAssessment,
            now: $now,
        );
    }
}
