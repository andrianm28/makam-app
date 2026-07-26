<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog;

use InvalidArgumentException;

/**
 * The closed list of `service_definitions.code` values — the exact 12 codes
 * `docs/product/service-catalog.md` names: 2 "Basic services" and 10
 * "Additional services" (counted directly against that file's own table
 * rows), in that document's own table order. Plain string column with
 * application-layer validation, not a Postgres enum type — same
 * established convention as every other closed-list string column in this
 * codebase (see `App\Domain\Faq\FaqCategoryCode`'s own doc block for the
 * precedent this mirrors: `App\Platform\IdentityAccess\Mfa\
 * MfaEnrolmentStatus`, `App\Platform\IdentityAccess\Reauthentication\
 * ReauthenticationOutcome`, `App\Platform\IdentityAccess\Scopes\
 * ScopeEntityType`).
 *
 * ---------------------------------------------------------------------------
 * 12 codes, not 13 — a discrepancy between this batch's own commissioning
 * brief and the actual canonical file, resolved in favour of the file
 * ---------------------------------------------------------------------------
 * The brief that commissioned this batch described "11 additional service
 * codes (AMBULANCE, FUNERAL_HOME, HEARSE, TENT_AND_CHAIRS, SOUND_SYSTEM,
 * FLOWERS, GRAVESTONE, DOCUMENTATION, CATERING, LIVE_STREAMING)" and "13
 * real catalogue codes" overall — but that parenthetical list itself only
 * names 10 codes, and `docs/product/service-catalog.md`'s own "Additional
 * services" table (the actual canonical source, verified by reading the
 * file directly) has exactly those same 10 rows, no eleventh. 2 basic + 10
 * additional = 12, not 13. This class follows the real file — the one
 * document `AGENTS.md` §Documentation names as canonical — not the brief's
 * miscounted summary of it. Flagged explicitly in this batch's own final
 * report; if an eleventh additional service was actually intended,
 * `service-catalog.md` needs a product decision to add it first.
 *
 * `docs/planning/sprint-plan.md` S4-T1: "enums derive from the catalogue."
 * This list — and the seed migration built from it
 * (`2026_07_26_180700_seed_service_definitions_from_catalog.php`) — is the
 * single application-layer representation of `service-catalog.md`'s two
 * tables. `AGENTS.md` §Documentation: "Do not duplicate canonical catalog
 * data in multiple hand-maintained documents or code locations" — nothing
 * else in this codebase should restate these 12 strings; reference this
 * class instead.
 *
 * Extending this list is a product/catalogue change (`service-catalog.md`
 * itself would need to change first), not a routine code change.
 */
final class ServiceCode
{
    // --- Basic services (service-catalog.md "Basic services" table) ---

    public const string DOCUMENT_PROCESSING = 'DOCUMENT_PROCESSING';

    public const string GRAVE_DIGGING = 'GRAVE_DIGGING';

    // --- Additional services (service-catalog.md "Additional services" table) ---

    public const string AMBULANCE = 'AMBULANCE';

    public const string FUNERAL_HOME = 'FUNERAL_HOME';

    public const string HEARSE = 'HEARSE';

    public const string TENT_AND_CHAIRS = 'TENT_AND_CHAIRS';

    public const string SOUND_SYSTEM = 'SOUND_SYSTEM';

    public const string FLOWERS = 'FLOWERS';

    public const string GRAVESTONE = 'GRAVESTONE';

    public const string DOCUMENTATION = 'DOCUMENTATION';

    public const string CATERING = 'CATERING';

    public const string LIVE_STREAMING = 'LIVE_STREAMING';

    /**
     * In `service-catalog.md`'s own "Basic services" table order.
     *
     * @var list<string>
     */
    public const array BASIC_CODES = [
        self::DOCUMENT_PROCESSING,
        self::GRAVE_DIGGING,
    ];

    /**
     * In `service-catalog.md`'s own "Additional services" table order.
     *
     * @var list<string>
     */
    public const array ADDITIONAL_CODES = [
        self::AMBULANCE,
        self::FUNERAL_HOME,
        self::HEARSE,
        self::TENT_AND_CHAIRS,
        self::SOUND_SYSTEM,
        self::FLOWERS,
        self::GRAVESTONE,
        self::DOCUMENTATION,
        self::CATERING,
        self::LIVE_STREAMING,
    ];

    /**
     * All 12 codes, basic first then additional — the seed migration's own
     * insert order and this class's one authoritative "the whole catalogue"
     * list.
     *
     * @var list<string>
     */
    public const array KNOWN_CODES = [
        ...self::BASIC_CODES,
        ...self::ADDITIONAL_CODES,
    ];

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::KNOWN_CODES, true);
    }

    public static function isBasic(string $code): bool
    {
        return in_array($code, self::BASIC_CODES, true);
    }

    public static function isAdditional(string $code): bool
    {
        return in_array($code, self::ADDITIONAL_CODES, true);
    }

    /**
     * @throws InvalidArgumentException when `$code` is not one of
     *                                  `self::KNOWN_CODES`.
     */
    public static function assertKnown(string $code): void
    {
        if (! self::isKnown($code)) {
            throw new InvalidArgumentException(
                "Unknown service catalogue code [{$code}]. Known codes: ".implode(', ', self::KNOWN_CODES).'.'
            );
        }
    }
}
