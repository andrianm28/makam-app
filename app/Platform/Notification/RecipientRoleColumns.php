<?php

declare(strict_types=1);

namespace App\Platform\Notification;

/**
 * Maps a `RecipientRole` back to its `docs/contracts/notification-matrix.md`
 * column header, for `Actions\DispatchNotification`'s channel-token scan
 * (task-3-brief.md D4: "derive a recipient's channels by scanning that
 * recipient's cell for those tokens").
 *
 * ---------------------------------------------------------------------------
 * Why this duplicates `RecipientResolver::ROLE_COLUMNS` instead of reusing it
 * ---------------------------------------------------------------------------
 * `RecipientResolver::ROLE_COLUMNS` (and its `CUSTOMER_COLUMN` sibling) is
 * `private const` on a Task 2 file this lane's brief lists as "do not
 * modify" (already reviewed clean). The exact same role-to-column mapping
 * is needed here, one layer up, to read the raw cell text a resolved
 * `Recipient` came from and scan it for channel tokens —
 * `RecipientResolver` itself never exposes that cell text, only the
 * resolved `Recipient` list. Rather than widening a frozen, reviewed file's
 * visibility (or reaching into it via reflection), this is a second,
 * independent copy of the same small, stable fact — the matrix's own
 * header row, `| Event | Customer | Admin platform | Pengelola TPU/TPS |
 * Vendor | Case manager | Finance |`. A future cleanup that exposes this
 * mapping from one shared location instead of two is a reasonable follow-up
 * (flagged in `task-3-report.md`), not done here to avoid touching Task 2's
 * frozen file.
 */
final class RecipientRoleColumns
{
    public const string CUSTOMER_COLUMN = 'Customer';

    /**
     * @var array<string, string>
     */
    public const array SCOPE_ROLE_COLUMNS = [
        RecipientRole::PLATFORM_ADMIN => 'Admin platform',
        RecipientRole::CEMETERY_OPERATOR => 'Pengelola TPU/TPS',
        RecipientRole::VENDOR => 'Vendor',
        RecipientRole::CASE_MANAGER => 'Case manager',
    ];

    /**
     * The matrix column that targets `$role`, or `null` when the role has
     * no column (there is none today — every `RecipientRole::KNOWN_ROLES`
     * value maps to a column — but this stays defensive rather than
     * assuming the two lists can never drift).
     */
    public static function columnFor(string $role): ?string
    {
        if ($role === RecipientRole::CUSTOMER) {
            return self::CUSTOMER_COLUMN;
        }

        return self::SCOPE_ROLE_COLUMNS[$role] ?? null;
    }
}
