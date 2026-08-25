<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use InvalidArgumentException;

/**
 * The closed list of customer-order payment states on
 * `marketplace_orders.payment_state`.
 *
 * ---------------------------------------------------------------------------
 * Deliberately disjoint from `VendorProcessingStatus` (requirement 12)
 * ---------------------------------------------------------------------------
 * "THE SYSTEM SHALL NOT treat a paid vendor order as fulfillment complete" —
 * `tasks.md`: "`DIBAYAR` ≠ `SELESAI`... Payment and fulfilment are separate
 * states." `VendorProcessingStatus`'s own doc block already refuses to carry
 * `DIBAYAR`; this list carries payment and nothing else. The two render as
 * two separate indicators on PUB-024, never one merged "done" badge.
 *
 * Same plain-string-class convention as `EvidenceRequirement` /
 * `VendorProcessingStatus`.
 */
final class PaymentState
{
    /** Order placed; no payment received yet. */
    public const string BELUM_DIBAYAR = 'BELUM_DIBAYAR';

    /** Customer submitted manual-transfer proof; admin verification pending. */
    public const string MENUNGGU_VERIFIKASI = 'MENUNGGU_VERIFIKASI';

    /** Payment confirmed (verified, never from a browser return URL). */
    public const string DIBAYAR = 'DIBAYAR';

    /** Payment failed or was refused. */
    public const string GAGAL = 'GAGAL';

    /** Payment refunded/reversed after being received. */
    public const string DIKEMBALIKAN = 'DIKEMBALIKAN';

    /** @var list<string> */
    public const array KNOWN = [
        self::BELUM_DIBAYAR,
        self::MENUNGGU_VERIFIKASI,
        self::DIBAYAR,
        self::GAGAL,
        self::DIKEMBALIKAN,
    ];

    public static function isKnown(string $value): bool
    {
        return in_array($value, self::KNOWN, true);
    }

    /**
     * @throws InvalidArgumentException when `$value` is not one of
     *                                  `self::KNOWN`.
     */
    public static function assertKnown(string $value): void
    {
        if (! self::isKnown($value)) {
            throw new InvalidArgumentException(
                "Unknown payment state [{$value}]. Known: ".implode(', ', self::KNOWN).'.'
            );
        }
    }
}
