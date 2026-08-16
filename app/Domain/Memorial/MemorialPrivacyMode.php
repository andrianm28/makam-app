<?php

declare(strict_types=1);

namespace App\Domain\Memorial;

use InvalidArgumentException;

/**
 * The closed list of `memorial_profiles.privacy_mode` values —
 * `.kiro/specs/memorial-and-qr/requirements.md` AC2: "THE SYSTEM SHALL
 * support privacy modes: private, family-only, unlisted, and public."
 *
 * Plain `string` column with application-layer validation in the model's
 * `booted()` hook — the codebase's established convention for a domain
 * closed list (`GraveRecordAccessMode`'s own doc block names this
 * convention), not a Postgres enum type.
 *
 * The four modes' visibility semantics live in
 * `MemorialProfile::isVisibleTo()`; this class only names the values and
 * refuses unknown ones. `DEFAULT` is `private` (AC1: "THE SYSTEM SHALL
 * default a memorial to private").
 */
enum MemorialPrivacyMode: string
{
    /**
     * Only an active family editor of the profile can view it — a QR
     * token alone is never sufficient.
     */
    case PRIVATE = 'private';

    /**
     * Token holders plus active family editors; a guest holding a
     * legitimately-issued token still sees nothing.
     */
    case FAMILY_ONLY = 'family_only';

    /**
     * Anyone who holds the token (the QR physically in hand) can view
     * the projection; the profile is not indexed anywhere else.
     */
    case UNLISTED = 'unlisted';

    /**
     * Anyone resolving the token can view the allowlisted projection.
     */
    case PUBLIC = 'public';

    /**
     * AC1's default.
     */
    public const string DEFAULT = 'private';

    /**
     * @var list<string>
     */
    public const array KNOWN_MODES = [
        self::PRIVATE->value,
        self::FAMILY_ONLY->value,
        self::UNLISTED->value,
        self::PUBLIC->value,
    ];

    public static function assertKnown(string $mode): void
    {
        if (! in_array($mode, self::KNOWN_MODES, true)) {
            throw new InvalidArgumentException(
                "Unknown memorial privacy mode [{$mode}]. Known modes: ".implode(', ', self::KNOWN_MODES).'.'
            );
        }
    }
}
