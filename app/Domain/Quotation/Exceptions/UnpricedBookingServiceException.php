<?php

declare(strict_types=1);

namespace App\Domain\Quotation\Exceptions;

use RuntimeException;

/**
 * Thrown by `Actions\ComposeQuoteLinesFromBookingDraft` — Task 2 of
 * `docs/superpowers/plans/2026-08-14-p0-booking-submission-chain.md` —
 * when a selected service cannot be priced from the current catalogue
 * state: either the code resolves to no `ServiceDefinition` at all
 * (`forUnknownCode()`), or the definition carries no current
 * (non-superseded) `PriceVersion` (`forCode()`).
 *
 * Mirrors the honesty discipline Step 5's summary already renders as
 * "Harga belum tersedia": a quote line is never composed from a made-up
 * or guessed amount. The draft's `selected_services` is a JSON column
 * whose only write path (`App\Domain\Booking\Actions\SaveBookingDraftStep`)
 * already guarantees known codes and positive quantities, so in practice
 * this fires only for hand-edited or older-schema rows — but a quote is
 * money, and money fails loudly here, never degrades silently into an
 * underquoted line set (the read path `BookingDraftQuery::summary()`
 * may skip unpriceable lines on screen; a write feeder may not).
 */
final class UnpricedBookingServiceException extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self(
            "Selected service [{$code}] has no current price version; ".
            'refusing to compose a quote line with a fabricated unit amount.'
        );
    }

    public static function forUnknownCode(string $code): self
    {
        return new self(
            "Selected service [{$code}] does not exist in the service catalog; ".
            'refusing to compose a quote line with a fabricated unit amount.'
        );
    }
}
