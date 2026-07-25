<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Scopes;

use InvalidArgumentException;

/**
 * The closed list of entity types `scope_assignments.entity_type` may hold
 * today — `platform-identity-and-access` requirements.md AC5: "enforce
 * record scope at query level for cemetery, vendor, order, case, grave, and
 * business entity, per rbac-matrix.md."
 *
 * Deliberately a plain string column at the schema level (see the
 * migration's own doc block), validated against this list at the
 * application layer instead — the batch brief's explicit instruction: "a
 * string column with app-level validation against a known-types list, not a
 * Postgres enum type that requires a migration to extend." Adding a new
 * entity type later is a one-line change here, not a schema migration.
 *
 * `CASE_RECORD` is named that way — not `CASE` — only because `case` is a
 * reserved word in PHP's own grammar (`switch`/`match`/`enum` syntax); the
 * *value* is still the literal string `'case'` that requirements.md AC5 and
 * rbac-matrix.md use.
 */
final class ScopeEntityType
{
    public const string CEMETERY = 'cemetery';

    public const string VENDOR = 'vendor';

    public const string ORDER = 'order';

    public const string CASE_RECORD = 'case';

    public const string GRAVE = 'grave';

    public const string BUSINESS_ENTITY = 'business_entity';

    /**
     * @var list<string>
     */
    public const array KNOWN_TYPES = [
        self::CEMETERY,
        self::VENDOR,
        self::ORDER,
        self::CASE_RECORD,
        self::GRAVE,
        self::BUSINESS_ENTITY,
    ];

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::KNOWN_TYPES, true);
    }

    /**
     * @throws InvalidArgumentException when `$type` is not one of
     *                                   `self::KNOWN_TYPES`.
     */
    public static function assertKnown(string $type): void
    {
        if (! self::isKnown($type)) {
            throw new InvalidArgumentException(
                "Unknown scope entity type [{$type}]. Known types: ".implode(', ', self::KNOWN_TYPES).'.'
            );
        }
    }
}
