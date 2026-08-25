<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use InvalidArgumentException;

/**
 * The closed list of `booking_drafts.service_type` values —
 * `docs/product/booking-wizard-fields.md` §Step 3 ("Pilih Jenis Layanan"):
 * `NEW_GRAVE`, `OVERLAPPING_GRAVE`, `URGENT_TODAY`, `PRE_NEED`, in that
 * document's own order. Plain string column with application-layer
 * validation, not a Postgres enum type — the same convention as
 * `App\Domain\CemeteryDirectory\LaunchCityCode` and
 * `App\Domain\ServiceCatalog\ServiceCode`.
 *
 * This class was the subject of a recorded "Open decision" in both
 * `public-booking-wizard/design.md` and `booking-and-order-orchestration/
 * design.md` (08 Aug 2026): which module should own it. Resolved here, in
 * `App\Domain\Booking`, because `booking-and-order-orchestration` AC4
 * ("select an explicit product type and workflow") is this list's routing
 * consumer; `public-booking-wizard`'s Step 3 rendering consumes it
 * read-only.
 *
 * Selecting `OVERLAPPING_GRAVE` or `URGENT_TODAY` records the choice only —
 * this batch enforces NO operational precondition on either value.
 * `booking-wizard-fields.md` documents preconditions this codebase cannot
 * yet check: "OVERLAPPING_GRAVE only selectable when cemetery/package
 * supports it" needs a signal `cemetery-directory-and-availability` AC6-9
 * do not yet provide (unbuilt — verified 08 Aug 2026 baseline analysis);
 * "URGENT_TODAY checks service area, operating hours, and capacity" needs
 * infrastructure that does not exist and depends on
 * `docs/governance/assumptions-and-gates.md` §5 open decision #6 (Urgent
 * SLA), unresolved. Both are recorded here rather than guessed at with an
 * invented signal.
 */
final class BookingServiceType
{
    public const string NEW_GRAVE = 'NEW_GRAVE';

    public const string OVERLAPPING_GRAVE = 'OVERLAPPING_GRAVE';

    public const string URGENT_TODAY = 'URGENT_TODAY';

    public const string PRE_NEED = 'PRE_NEED';

    /**
     * @var list<string>
     */
    public const array KNOWN_CODES = [
        self::NEW_GRAVE,
        self::OVERLAPPING_GRAVE,
        self::URGENT_TODAY,
        self::PRE_NEED,
    ];

    /**
     * The human-visible Step 3 labels — COPIED, not invented.
     *
     * `docs/product/mvp-scope.md` row 3 ("Pilih jenis layanan | Makam Baru,
     * Makam Tumpang, Urgent, Pre-Need") and `docs/product/product-brief.md`
     * §3 ("Pilih jenis layanan: Makam Baru, Makam Tumpang, Urgent,
     * Pre-Need.") name these four in exactly this order, in the Step 3
     * context. `booking-wizard-fields.md` §Step 3 lists only the machine
     * codes, so those two documents are the canonical label source.
     *
     * Deliberately NOT translated further: "Urgent" and "Pre-Need" read as
     * English but are the stakeholder's own product copy, and `AGENTS.md`
     * forbids renaming a product label ("Never rename, reorder, or hide a
     * product label, route, menu item, or booking step"). Inventing
     * "Pemakaman Hari Ini" / "Pra-Kebutuhan" here would be exactly that
     * rename, and would fork the catalogue away from the two canonical
     * documents. Changing this wording is a product decision made in
     * `mvp-scope.md` first, not a code change.
     *
     * @var array<string, string>
     */
    public const array LABELS = [
        self::NEW_GRAVE => 'Makam Baru',
        self::OVERLAPPING_GRAVE => 'Makam Tumpang',
        self::URGENT_TODAY => 'Urgent',
        self::PRE_NEED => 'Pre-Need',
    ];

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::KNOWN_CODES, true);
    }

    /**
     * The display label for one code. Same contract as
     * `App\Domain\Booking\BookingWizardStep::label()`: an unknown code is a
     * programming error, not a rendering fallback.
     *
     * @throws InvalidArgumentException when `$code` is not one of
     *                                  `self::KNOWN_CODES`.
     */
    public static function label(string $code): string
    {
        self::assertKnown($code);

        return self::LABELS[$code];
    }

    /**
     * @throws InvalidArgumentException when `$code` is not one of
     *                                  `self::KNOWN_CODES`.
     */
    public static function assertKnown(string $code): void
    {
        if (! self::isKnown($code)) {
            throw new InvalidArgumentException(
                "Unknown booking service type [{$code}]. Known types: ".implode(', ', self::KNOWN_CODES).'.'
            );
        }
    }
}
