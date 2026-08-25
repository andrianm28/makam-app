<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Exceptions;

use RuntimeException;

/**
 * The actor has not re-proved their identity recently enough to perform a
 * bulk financial export.
 *
 * `AGENTS.md` §Authentication: "Require recent re-authentication for
 * financial, gate, bank-detail, certificate, plot-override, and bulk-export
 * actions." AC13 names bulk financial export explicitly.
 *
 * Raised only after
 * `App\Platform\IdentityAccess\Reauthentication\ReauthenticationService
 * ::challenge()` has recorded the refusal, so a stale export attempt always
 * leaves an audit trail rather than only an exception — the same discipline
 * `PayoutReauthenticationRequiredException` establishes for payout approval.
 */
final class BulkFinancialExportReauthenticationRequiredException extends RuntimeException
{
    public static function forActor(int|string $actorRef, int $freshnessSeconds): self
    {
        return new self(
            "Actor [{$actorRef}] has no satisfied re-authentication within the last ".
            "{$freshnessSeconds} seconds. A bulk financial export requires recent re-authentication ".
            'before it may proceed.'
        );
    }
}
