<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use InvalidArgumentException;

/**
 * The closed list of vendor order processing statuses named by
 * `docs/product/marketplace-catalog.md` "Vendor processing statuses"
 * (L74-L85) and echoed by `.kiro/specs/funeral-marketplace-and-vendor-portal/
 * tasks.md` "Vendor processing statuses — 8 codes" and its "Vendor
 * processing status → intent" table (`design-system.md` §3.7's normative
 * `StatusIntent` mapping).
 *
 * ---------------------------------------------------------------------------
 * Deliberately NOT wired to anything yet — this batch (S4-T1) is
 * catalogue/master-data only
 * ---------------------------------------------------------------------------
 * No `vendor_orders` table, state machine, or `StatusIntent` resolver call
 * exists in this repository yet — those belong to `funeral-marketplace-
 * and-vendor-portal` tasks "Implement vendor order status and evidence
 * upload" and this project's `VendorFulfillment` module, both explicitly out
 * of this batch's narrow scope (no vendors, carts, or orders exist yet; see
 * `app/Domain/Marketplace/ProductCode.php` and the migrations in this same
 * batch for what DOES exist). This class exists now, ahead of that table,
 * for the same reason `ProductCode` exists ahead of a cart: so that whenever
 * a later batch DOES build `vendor_orders.status`, it has one closed-list
 * definition, traceable to the catalogue, to consume from day one — instead
 * of that later batch inventing its own literal string list and creating
 * the exact "restate the catalogue in code" drift `AGENTS.md` §Documentation
 * warns against.
 *
 * Same plain-string-class convention as `ProductCode` — see that class's
 * own doc block for why this codebase uses this shape (not a native PHP
 * backed enum) for a closed list that will back a DB column.
 *
 * **`DIBAYAR` (paid) is intentionally NOT in this list.** AC12: "THE SYSTEM
 * SHALL NOT treat a paid vendor order as fulfillment complete" —
 * `tasks.md`: *"`DIBAYAR` ≠ `SELESAI`... Payment and fulfilment are separate
 * states."* Payment state belongs to a payment/order concern, not this
 * vendor-fulfilment-processing list; conflating the two here would silently
 * reintroduce the exact "paid means done" defect AC12 forbids.
 */
final class VendorProcessingStatus
{
    /** Order created, awaiting the assigned vendor's response. */
    public const string MENUNGGU_VENDOR = 'MENUNGGU_VENDOR';

    /** Vendor accepted the order. */
    public const string DITERIMA_VENDOR = 'DITERIMA_VENDOR';

    /** Vendor rejected the order. */
    public const string DITOLAK_VENDOR = 'DITOLAK_VENDOR';

    /** Vendor is actively working/preparing the order. */
    public const string DIPROSES = 'DIPROSES';

    /** Delivered (goods) or scheduled/performed (service). */
    public const string DIKIRIM_OR_DIJADWALKAN = 'DIKIRIM_OR_DIJADWALKAN';

    /** Fulfilment complete. Deliberately distinct from any payment state. */
    public const string SELESAI = 'SELESAI';

    /** Customer raised a complaint about the order. */
    public const string KOMPLAIN = 'KOMPLAIN';

    /** Order cancelled. */
    public const string DIBATALKAN = 'DIBATALKAN';

    /**
     * In the catalogue's own listed order.
     *
     * @var list<string>
     */
    public const array KNOWN_STATUSES = [
        self::MENUNGGU_VENDOR,
        self::DITERIMA_VENDOR,
        self::DITOLAK_VENDOR,
        self::DIPROSES,
        self::DIKIRIM_OR_DIJADWALKAN,
        self::SELESAI,
        self::KOMPLAIN,
        self::DIBATALKAN,
    ];

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::KNOWN_STATUSES, true);
    }

    /**
     * @throws InvalidArgumentException when `$status` is not one of
     *                                  `self::KNOWN_STATUSES`.
     */
    public static function assertKnown(string $status): void
    {
        if (! self::isKnown($status)) {
            throw new InvalidArgumentException(
                "Unknown vendor processing status [{$status}]. Known statuses: ".implode(', ', self::KNOWN_STATUSES).'.'
            );
        }
    }
}
