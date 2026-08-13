<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

use InvalidArgumentException;

/**
 * The closed list of `renewals.source` values.
 *
 * `docs/superpowers/plans/2026-08-12-platform-renewal-completion.md` fixes
 * these two: `online`, `external`. This is the column that makes the AC11
 * duplicate-period guard work across two different write paths sharing one
 * table: a family completing the public renewal journey inserts a
 * `renewals` row with `source = ONLINE`; an admin recording a payment taken
 * outside the platform (AC10) inserts one with `source = EXTERNAL`. Both
 * rows are ordinary `renewals` rows subject to the same
 * `renewals_grave_period_unique` index — `source` only records provenance,
 * it plays no part in the uniqueness check itself. See
 * `2026_08_12_100000_create_renewals_table.php` for why that is
 * deliberate.
 *
 * Not to be confused with `App\Domain\GraveRegistry\GraveRecordSource`,
 * which answers the same "where did this row come from" question for an
 * entirely different table (`grave_records`) and has a disjoint vocabulary.
 *
 * Same `final class` + `KNOWN_*` + `assertKnown()` shape as every other
 * closed list here; see `App\Domain\GraveRegistry\GraveRecordAccessMode`
 * for the convention citation.
 */
final class RenewalSource
{
    /**
     * Written by the public renewal journey's own write path (`OpenRenewal`,
     * a later task in this lane) once a family completes steps 4-6.
     */
    public const string ONLINE = 'online';

    /**
     * Written by an admin recording a payment settled outside the platform
     * — AC10's external marking. A later task in this lane (`MarkExternalRenewal`)
     * owns the privileged write path; this task only reserves the value.
     */
    public const string EXTERNAL = 'external';

    /**
     * @var list<string>
     */
    public const array KNOWN_SOURCES = [
        self::ONLINE,
        self::EXTERNAL,
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
                "Unknown renewal source [{$source}]. Known sources: ".implode(', ', self::KNOWN_SOURCES).'.'
            );
        }
    }
}
