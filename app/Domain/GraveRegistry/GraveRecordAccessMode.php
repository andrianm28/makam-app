<?php

declare(strict_types=1);

namespace App\Domain\GraveRegistry;

use InvalidArgumentException;

/**
 * The closed list of `grave_records.access_mode` values —
 * `.kiro/specs/renewal-and-grave-registry/requirements.md` AC14: "THE SYSTEM
 * SHALL support open, limited, and closed search access modes."
 *
 * Plain `string` column with application-layer validation in the model's
 * `booted()` hook, no Postgres `CHECK` — this codebase's established
 * convention for a domain closed list (`ScopeEntityType`'s originating doc
 * block: "a string column with app-level validation against a known-types
 * list, not a Postgres enum type that requires a migration to extend"). The
 * three `CHECK` constraints that do exist are all `app/Platform` protocol
 * values; see `makam-migration` for why this is not one of them.
 *
 * ---------------------------------------------------------------------------
 * How these three modes relate to `G-DATA-01` — an explicit reconciliation
 * ---------------------------------------------------------------------------
 * There are TWO independent access controls on this journey and they are
 * routinely conflated. They are not the same thing and they produce two
 * different screens:
 *
 *   1. `G-DATA-01` (`App\Platform\FeatureGate\Modes\GraveSearchMode`) is
 *      BINARY and GLOBAL — `search_enabled` or `manual_assistance`. Closed
 *      means the search CAPABILITY is unavailable to everyone, for every
 *      record. `design.md`'s Privacy section and AC16 own this. The screen
 *      is design-system.md §6.4's explanatory gate-closed page.
 *   2. `access_mode` (this class) is PER RECORD and has THREE values —
 *      AC14. It decides the FIELD PROJECTION for a record that a search has
 *      already matched, while the gate is open. The screen is
 *      design-system.md §6.2's *privacy-limited* empty state.
 *
 * `design.md`'s Privacy section fixes the rule this class implements:
 * "Public results must only return fields allowed by configured access mode.
 * Contact details must never appear in public search by default."
 *
 * The consequence that matters most on this product: `CLOSED` does NOT mean
 * "omit the row from the result set". Silently dropping a matched record is
 * indistinguishable, to the family reading the screen, from the record not
 * existing — which is exactly the defect `tasks.md` calls out ("a family
 * searching for a grave record and finding nothing must not conclude the
 * grave does not exist"). A `CLOSED` record is acknowledged as existing and
 * carries no fields at all; see `GraveRecordProjection`.
 */
final class GraveRecordAccessMode
{
    /**
     * Full public projection — deceased name, cemetery, block, death date,
     * due date. Never heir contact (that is not a mode, it is an absolute:
     * see `GraveRecordProjection`).
     */
    public const string OPEN = 'open';

    /**
     * Partial projection — the record's location (cemetery, block) is
     * shown so a family can recognise it and take the manual-assistance
     * path, but the identifying and date fields are withheld.
     */
    public const string LIMITED = 'limited';

    /**
     * Existence-only projection — no fields at all. The record is still
     * counted and still surfaces the privacy-limited state; it is never
     * silently dropped. See this class's own doc block for why.
     */
    public const string CLOSED = 'closed';

    /**
     * The safest of the three, and therefore the column default. A record
     * whose access mode was never explicitly decided must not be published
     * in full by omission.
     */
    public const string DEFAULT = self::CLOSED;

    /**
     * In AC14's own listed order ("open, limited, and closed").
     *
     * @var list<string>
     */
    public const array KNOWN_MODES = [
        self::OPEN,
        self::LIMITED,
        self::CLOSED,
    ];

    public static function isKnown(string $mode): bool
    {
        return in_array($mode, self::KNOWN_MODES, true);
    }

    /**
     * @throws InvalidArgumentException when `$mode` is not one of
     *                                  `self::KNOWN_MODES`.
     */
    public static function assertKnown(string $mode): void
    {
        if (! self::isKnown($mode)) {
            throw new InvalidArgumentException(
                "Unknown grave record access mode [{$mode}]. Known modes: ".implode(', ', self::KNOWN_MODES).'.'
            );
        }
    }

    /**
     * `true` when this mode withholds at least one field a fully-open
     * record would show — i.e. when the row must be reported through the
     * §6.2 privacy-limited state rather than as an ordinary result.
     */
    public static function isRestricted(string $mode): bool
    {
        self::assertKnown($mode);

        return $mode !== self::OPEN;
    }
}
