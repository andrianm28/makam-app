<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory;

use InvalidArgumentException;

/**
 * The closed list of `cemeteries.type` values — `.kiro/specs/cemetery-
 * directory-and-availability/design.md`'s Data section names `cemeteries`
 * and `docs/contracts/openapi.yaml`'s `CemeterySummary.type` schema fixes
 * the same two-value enum (`TPU`, `TPS`) independently, so this class is
 * not inventing the pair, only giving it one PHP-side source of truth.
 *
 * `TPU` = Tempat Pemakaman Umum (public/government-operated cemetery).
 * `TPS` = Tempat Pemakaman Swasta/Khusus (private/special-purpose
 * cemetery). Both are ordinary Indonesian funeral-sector terms, not this
 * project's invention.
 *
 * Plain string column with application-layer validation, not a Postgres
 * enum type — this codebase's established convention for closed-list
 * string columns (`App\Domain\Faq\FaqCategoryCode`,
 * `App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus`,
 * `App\Platform\IdentityAccess\Scopes\ScopeEntityType`).
 */
final class CemeteryType
{
    /**
     * Tempat Pemakaman Umum — public/government-operated cemetery.
     */
    public const string TPU = 'TPU';

    /**
     * Tempat Pemakaman Swasta/Khusus — private or special-purpose
     * cemetery.
     */
    public const string TPS = 'TPS';

    /**
     * @var list<string>
     */
    public const array KNOWN_TYPES = [
        self::TPU,
        self::TPS,
    ];

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::KNOWN_TYPES, true);
    }

    /**
     * @throws InvalidArgumentException when `$type` is not one of
     *                                  `self::KNOWN_TYPES`.
     */
    public static function assertKnown(string $type): void
    {
        if (! self::isKnown($type)) {
            throw new InvalidArgumentException(
                "Unknown cemetery type [{$type}]. Known types: ".implode(', ', self::KNOWN_TYPES).'.'
            );
        }
    }
}
