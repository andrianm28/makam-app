<?php

declare(strict_types=1);

namespace App\Domain\GraveRegistry;

use InvalidArgumentException;

/**
 * The closed list of `grave_records.source` values —
 * `.kiro/specs/renewal-and-grave-registry/design.md`'s Data section names
 * `source` and `source_updated_at` as columns on `grave_records`.
 *
 * `source` answers "where did this row come from", which is what makes the
 * §6.2 *no-result* copy honest: the public message says the registry may be
 * incomplete, and provenance is what lets an operator later show WHICH
 * registry was incomplete. It is deliberately NOT the same field as the
 * TARIFF source AC6 requires on the fee step (PUB-032) — that belongs to
 * `app/Domain/Renewal`'s quote/tariff shape, which Sprint 13 owns
 * (`docs/planning/sprint-plan.md` §9) and this batch does not build.
 *
 * Same `final class` + `KNOWN_*` + `assertKnown()` shape as every other
 * DB-backed closed list here; see `GraveRecordAccessMode` for the convention
 * citation.
 */
final class GraveRecordSource
{
    /**
     * Loaded from an operator's bulk registry file. The async 10,000-row
     * import that produces these (AC13) is NOT built in this batch — it is
     * deferred to Sprint 13 with the rest of this spec. The value exists
     * now so the column has a real vocabulary rather than being widened
     * later by a schema change.
     */
    public const string OPERATOR_IMPORT = 'operator_import';

    /**
     * Entered one row at a time by an operator or admin through the admin
     * surface. That surface is likewise not built in this batch.
     */
    public const string OPERATOR_MANUAL = 'operator_manual';

    /**
     * Fictional example data, seeded so `dev.makam.co.id` renders this
     * journey end to end. "Contoh" is Indonesian for "example" and is this
     * repository's established marker word for fabricated placeholder
     * content — see `2026_07_26_190300_seed_cemeteries_and_capability_
     * profiles.php` ("Jl. Contoh ...") and `2026_07_26_210000_backfill_
     * dummy_map_price_and_photo_for_seeded_cemeteries.php` ("data contoh").
     *
     * A row carrying this source is never real business data and any
     * surface displaying it must be able to say so.
     */
    public const string CONTOH = 'contoh';

    /**
     * @var list<string>
     */
    public const array KNOWN_SOURCES = [
        self::OPERATOR_IMPORT,
        self::OPERATOR_MANUAL,
        self::CONTOH,
    ];

    public static function isKnown(string $source): bool
    {
        return in_array($source, self::KNOWN_SOURCES, true);
    }

    /**
     * @throws InvalidArgumentException when `$source` is not one of
     *                                  `self::KNOWN_SOURCES`.
     */
    public static function assertKnown(string $source): void
    {
        if (! self::isKnown($source)) {
            throw new InvalidArgumentException(
                "Unknown grave record source [{$source}]. Known sources: ".implode(', ', self::KNOWN_SOURCES).'.'
            );
        }
    }
}
