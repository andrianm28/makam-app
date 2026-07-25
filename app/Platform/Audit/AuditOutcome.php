<?php

declare(strict_types=1);

namespace App\Platform\Audit;

/**
 * The closed set of audit outcomes named by `platform-audit` design.md's
 * Data section: "outcome -- allowed | denied | failed."
 *
 * Backed by a real Postgres CHECK constraint on `audit_events.outcome`
 * (see `2026_07_26_110000_create_audit_events_table.php`) as well as
 * this PHP enum, so an invalid outcome is rejected before the query is
 * even built, not only at the database.
 */
enum AuditOutcome: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Failed = 'failed';
}
