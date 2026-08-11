<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use InvalidArgumentException;

/**
 * The recipient-role vocabulary `RecipientResolver` emits on each
 * `Recipient` — the six classes `.kiro/specs/platform-notifications/
 * requirements.md:16` (AC6) names: customer, admin, cemetery operator,
 * vendor, case manager, finance. `FINANCE` is deliberately absent from
 * this list: no provisional role source in this lane can derive it (see
 * `ProvisionalScopeEntityRecipientRoleSource`'s doc block), and it is not
 * fabricated here just to have a constant for it.
 *
 * This is a plain vocabulary list, not itself provisional — what IS
 * provisional (per ruling 2, `docs/superpowers/plans/2026-08-10-wave1a-
 * notifications-decisions.md`) is *how* a role is derived from a scope
 * grant, which lives behind `Contracts\RecipientRoleSource`.
 */
final class RecipientRole
{
    public const string CUSTOMER = 'customer';

    public const string CEMETERY_OPERATOR = 'cemetery_operator';

    public const string VENDOR = 'vendor';

    public const string CASE_MANAGER = 'case_manager';

    public const string PLATFORM_ADMIN = 'platform_admin';

    /**
     * @var list<string>
     */
    public const array KNOWN_ROLES = [
        self::CUSTOMER,
        self::CEMETERY_OPERATOR,
        self::VENDOR,
        self::CASE_MANAGER,
        self::PLATFORM_ADMIN,
    ];

    public static function isKnown(string $role): bool
    {
        return in_array($role, self::KNOWN_ROLES, true);
    }

    /**
     * @throws InvalidArgumentException when `$role` is not one of
     *                                  `self::KNOWN_ROLES`.
     */
    public static function assertKnown(string $role): void
    {
        if (! self::isKnown($role)) {
            throw new InvalidArgumentException(
                "Unknown recipient role [{$role}]. Known roles: ".implode(', ', self::KNOWN_ROLES).'.'
            );
        }
    }
}
