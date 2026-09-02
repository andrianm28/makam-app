<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

use App\Domain\Booking\BookingServiceType;
use InvalidArgumentException;

/**
 * The closed list backing `orders.product_type` — AC4's "select an explicit
 * product type and workflow".
 *
 * ---------------------------------------------------------------------------
 * Six cases, not two — this enum COPIES a canonical catalogue
 * ---------------------------------------------------------------------------
 * `docs/domain/product-type-model.md` §Rule is the canonical product-type
 * catalogue and names six types with their triggers and obligations. All six
 * are here, in that document's own order, because `AGENTS.md` §Documentation
 * forbids duplicating canonical catalogue data in a rival hand-maintained
 * location — and a two-case enum would have been exactly that: a second,
 * silently narrower catalogue that a later reader would take as the closed
 * list. Its opening rule is the reason the distinction matters at all:
 * "Similar payment frequency does not make products equivalent."
 *
 * Only two are REACHABLE from booking submission today. That is the same
 * document's §Initial scope talking, not this enum quietly deciding: At-Need
 * is proposed R1; paid Pre-Need sits behind the legal/financial gate;
 * care subscription and renewal are RKS scope gated by payment/data; funeral
 * protection is explicitly "not covered by RKS; separate discovery and legal
 * analysis required". `Actions\SubmitBookingDraft` therefore routes the two
 * and refuses the other four by name rather than falling through to a
 * default arm.
 *
 * Deliberately a SEPARATE vocabulary from `App\Domain\Booking\
 * BookingServiceType`, even though the mapping below is total and
 * mechanical. `BookingServiceType` is the customer's Step 3 choice
 * (`booking-wizard-fields.md` §Step 3: four values, three of which are
 * At-Need shapes); `ProductType` is the commercial workflow that choice
 * routes to. Collapsing them would mean adding a fifth service type — a
 * product decision made in `mvp-scope.md` — silently created a fifth
 * workflow here.
 *
 * `2026_08_12_100000_create_orders_table.php` left `product_type` a plain
 * unconstrained string precisely because this enum did not exist yet, and
 * said so; `2026_08_12_100015_add_order_submission_identity.php` adds the
 * PostgreSQL CHECK now that it does, following the same
 * `payment_verifications.status` precedent that migration cites.
 */
enum ProductType: string
{
    /**
     * Trigger "Death/immediate need"; obligation "Deliver coordinated
     * service now". Routes to a `App\Domain\FuneralCase\Models\FuneralCase`
     * — `docs/domain/funeral-case-model.md` §Purpose: "At-Need and Urgent
     * are human service orchestration problems. `order.status` alone cannot
     * represent the work required."
     */
    case AT_NEED_SERVICE_ORDER = 'AT_NEED_SERVICE_ORDER';

    /**
     * Trigger "Future preparation"; key risk "Liability, price, reserve,
     * cancellation". Routes to interest registration only while
     * `G-LEGAL-01` is closed — design-system §6.9, "registers interest; no
     * payment created".
     */
    case PRE_NEED_PLOT_PURCHASE = 'PRE_NEED_PLOT_PURCHASE';

    /**
     * "not covered by RKS; separate discovery and legal analysis required".
     * No workflow exists for it anywhere in this codebase.
     */
    case FUNERAL_PROTECTION_MEMBERSHIP = 'FUNERAL_PROTECTION_MEMBERSHIP';

    /** RKS scope, gated by payment/data. No submission path today. */
    case CARE_SUBSCRIPTION = 'CARE_SUBSCRIPTION';

    /** Owned by the marketplace lane, not by booking submission. */
    case MARKETPLACE_PRODUCT_ORDER = 'MARKETPLACE_PRODUCT_ORDER';

    /** RKS scope, gated by payment/data. Owned by the renewal lane. */
    case RENEWAL_ORDER = 'RENEWAL_ORDER';

    /**
     * The Step 3 choice → commercial workflow mapping. Exhaustive over
     * `BookingServiceType::KNOWN_CODES` by construction: the `match` has no
     * default arm, so adding a fifth service type there is an unhandled-case
     * error here rather than a silent fall-through to At-Need.
     *
     * @throws InvalidArgumentException when `$serviceType` is not one of
     *                                  `BookingServiceType::KNOWN_CODES`.
     */
    public static function fromBookingServiceType(string $serviceType): self
    {
        BookingServiceType::assertKnown($serviceType);

        return match ($serviceType) {
            BookingServiceType::NEW_GRAVE,
            BookingServiceType::OVERLAPPING_GRAVE,
            BookingServiceType::URGENT_TODAY => self::AT_NEED_SERVICE_ORDER,
            BookingServiceType::PRE_NEED => self::PRE_NEED_PLOT_PURCHASE,
        };
    }

    /**
     * The Indonesian display label — moved here (2 Sep 2026 UAT finding)
     * from `App\Filament\Admin\Resources\BookingOrders\
     * BookingOrderProductTypeLabel`, which stays as a thin delegate so its
     * existing call sites are untouched. Centralizing here is what let
     * `/akun/pesanan` (`resources/views/livewire/public/akun/order-list.
     * blade.php`) stop humanizing the raw enum name inline (`AT_NEED_
     * SERVICE_ORDER` -> "At Need Service Order") for lack of "authority to
     * write new canonical product copy" — that copy already existed here,
     * one layer up, it just hadn't been reachable outside the Admin
     * resource. "At-Need" and "Pre-Need" are kept as the established
     * English product terms (see this enum's own doc block and
     * `BookingOrderProductTypeLabel`'s prior doc block for the sourcing).
     */
    public function label(): string
    {
        return match ($this) {
            self::AT_NEED_SERVICE_ORDER => 'Pesanan Layanan At-Need',
            self::PRE_NEED_PLOT_PURCHASE => 'Pembelian Plot Pre-Need',
            self::FUNERAL_PROTECTION_MEMBERSHIP => 'Keanggotaan Perlindungan Pemakaman',
            self::CARE_SUBSCRIPTION => 'Langganan Perawatan',
            self::MARKETPLACE_PRODUCT_ORDER => 'Pesanan Produk Marketplace',
            self::RENEWAL_ORDER => 'Pesanan Perpanjangan',
        };
    }
}
