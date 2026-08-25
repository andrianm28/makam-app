<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory;

use InvalidArgumentException;

/**
 * The closed list of `cemeteries.city` values — requirements.md AC1: "THE
 * SYSTEM SHALL include Jakarta, Bogor, Depok, Tangerang, and Bekasi in the
 * initial launch city/regency list," and negative criterion "No hidden
 * omission of a required MVP city."
 *
 * Sourced from `docs/product/booking-wizard-fields.md` §Step 1
 * ("Allowed MVP values": `JAKARTA`, `BOGOR`, `DEPOK`, `TANGERANG`,
 * `BEKASI`), which is itself the same five cities `docs/product/
 * mvp-scope.md` row 1 and `docs/product/information-architecture.md` §1
 * name — this class does not invent the list, it gives this spec's own
 * `cemeteries.city` column one PHP-side source of truth for it.
 *
 * ---------------------------------------------------------------------------
 * A known, flagged duplication risk — not resolved here
 * ---------------------------------------------------------------------------
 * `booking-wizard-fields.md` documents a `city_or_regency_code` field for
 * the booking wizard's own Step 1, which belongs to `app/Domain/Booking`
 * (out of this batch's scope — `AGENTS.md` §Architecture assigns booking
 * intake to that module, and this batch touches only `CemeteryDirectory`/
 * `CemeteryCapability`). If/when `Booking` is implemented, whoever builds
 * it should reuse THIS class (or extract a shared one) rather than
 * redefine the same five codes a second time — `AGENTS.md` §Documentation:
 * "Do not duplicate canonical catalog data in multiple hand-maintained
 * documents or code locations." Flagged explicitly in this batch's final
 * report rather than silently created as a second source of truth.
 *
 * Plain string column with application-layer validation, not a Postgres
 * enum type — same established convention as `CemeteryType` and every
 * other closed-list string column in this codebase.
 */
final class LaunchCityCode
{
    public const string JAKARTA = 'JAKARTA';

    public const string BOGOR = 'BOGOR';

    public const string DEPOK = 'DEPOK';

    public const string TANGERANG = 'TANGERANG';

    public const string BEKASI = 'BEKASI';

    /**
     * In `booking-wizard-fields.md`'s own listed order.
     *
     * @var list<string>
     */
    public const array KNOWN_CODES = [
        self::JAKARTA,
        self::BOGOR,
        self::DEPOK,
        self::TANGERANG,
        self::BEKASI,
    ];

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::KNOWN_CODES, true);
    }

    /**
     * @throws InvalidArgumentException when `$code` is not one of
     *                                  `self::KNOWN_CODES`.
     */
    public static function assertKnown(string $code): void
    {
        if (! self::isKnown($code)) {
            throw new InvalidArgumentException(
                "Unknown launch city code [{$code}]. Known codes: ".implode(', ', self::KNOWN_CODES).'.'
            );
        }
    }
}
