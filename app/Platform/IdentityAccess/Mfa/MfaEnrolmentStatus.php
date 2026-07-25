<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa;

use InvalidArgumentException;

/**
 * The closed list of values `mfa_enrolments.status` may hold. Plain string
 * column with application-layer validation, not a Postgres enum type —
 * matching this module's already-established convention
 * (`Scopes\ScopeEntityType`, `Scopes\ScopeGrantLevel`), so adding a future
 * status is a one-line change here, not a schema migration.
 */
final class MfaEnrolmentStatus
{
    /**
     * A secret has been generated and stored, but the actor has not yet
     * proven possession of it by submitting one valid TOTP code. NOT
     * trusted for anything — `MfaChallengeService` only ever verifies
     * against a `CONFIRMED` enrolment.
     */
    public const string PENDING = 'pending';

    /**
     * The actor proved possession of the secret once (`MfaEnrolmentService
     * ::confirm()`); recovery codes have been generated. This is the only
     * status a real MFA challenge may be verified against.
     */
    public const string CONFIRMED = 'confirmed';

    /**
     * Explicitly revoked (`MfaEnrolmentService::revoke()`) or superseded by
     * a newer enrolment (`MfaEnrolmentService::startEnrolment()`). Kept as
     * a row (never deleted) for history — mirrors `actor_sessions
     * .revoked_at` / `scope_assignments.revoked_at`'s already-established
     * soft-revoke pattern in this codebase.
     */
    public const string REVOKED = 'revoked';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATUSES = [
        self::PENDING,
        self::CONFIRMED,
        self::REVOKED,
    ];

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::KNOWN_STATUSES, true);
    }

    /**
     * @throws InvalidArgumentException when `$status` is not one of
     *                                  `self::KNOWN_STATUSES`.
     */
    public static function assertKnown(string $status): void
    {
        if (! self::isKnown($status)) {
            throw new InvalidArgumentException(
                "Unknown MFA enrolment status [{$status}]. Known statuses: ".implode(', ', self::KNOWN_STATUSES).'.'
            );
        }
    }
}
